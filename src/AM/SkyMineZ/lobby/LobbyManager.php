<?php

declare(strict_types=1);

namespace AM\SkyMineZ\lobby;

use AM\SkyMineZ\Main;
use AM\SkyMineZ\useless\Arrays;
use AM\SkyMineZ\useless\CollisionBox;
use AM\SkyMineZ\useless\Positions;
use pocketmine\math\Vector3;
use pocketmine\utils\Config;
use pocketmine\world\Position;
use pocketmine\world\World;

/**
 * The lobby: where players arrive, which world is protected, and which damage
 * and hunger rules apply there.
 *
 * Everything persistent lives in lobby.json: the hub teleport target, the
 * mid-lobby welcome position, and the optional mid-lobby scoreboard zone. Behaviour
 * switches (join teleport, fall/void/hunger handling) live in config.yml under
 * `lobby:` because admins flip those far more often than they move the lobby.
 *
 * Protection is map based: the world holding the hub teleport target is the
 * lobby map, and the anti-grief rules apply everywhere inside it. There is no
 * cuboid to maintain, so mines, crates and slappers placed in the lobby map
 * keep working — the listener simply lets their own handlers own those
 * blocks (see {@link LobbyListener}).
 */
final class LobbyManager
{
    private const FILE_NAME = 'lobby.json';

    private Config $db;

    /**
     * Memoized mid-zone box. Null means "not built or stale" and is rebuilt
     * lazily, so per-tick zone checks never allocate or re-parse.
     */
    private ?CollisionBox $midZoneBox = null;

    public function __construct(
        private Main $main
    ) {
        $this->db = new Config(
            $this->main->getDataFolder() . self::FILE_NAME,
            Config::JSON
        );
    }

    public function load(): void
    {
        $this->db->reload();

        $this->dropMidZoneBox();
    }

    public function saveAll(): void
    {
        $this->db->save();
    }

    public function getLobby(): ?Position
    {
        $data = $this->db->get('lobby');

        if (!is_array($data) || !Arrays::isStringMap($data)) {
            return null;
        }

        return Positions::fromArray(
            $data,
            $this->main->getServer()->getWorldManager()
        );
    }

    public function setLobby(
        Position $position
    ): void {
        $this->db->set('lobby', Positions::toArray($position));
        $this->db->save();
    }

    public function clearLobby(): void
    {
        $this->db->remove('lobby');
        $this->db->save();
    }

    /**
     * Where /hub sends players: the main lobby, falling back to mid-lobby so
     * a server with only a mid set still has a working hub.
     */
    public function getHub(): ?Position
    {
        return $this->getLobby() ?? $this->getMidLobby();
    }

    public function getMidLobby(): ?Position
    {
        $data = $this->db->get('midlobby');

        if (!is_array($data) || !Arrays::isStringMap($data)) {
            return null;
        }

        return Positions::fromArray(
            $data,
            $this->main->getServer()->getWorldManager()
        );
    }

    public function setMidLobby(
        Position $position
    ): void {
        $this->db->set('midlobby', Positions::toArray($position));
        $this->db->save();
    }

    public function clearMidLobby(): void
    {
        $this->db->remove('midlobby');
        $this->db->save();
    }

    /**
     * The mid-lobby scoreboard zone (pos1/pos2 selection), or null when it was
     * never set or its world no longer resolves.
     *
     * @return array{Position, Position}|null
     */
    public function getMidZone(): ?array
    {
        $data = $this->db->get('midzone');

        if (!is_array($data) || !Arrays::isStringMap($data)) {
            return null;
        }

        if (
            !isset($data['pos1'], $data['pos2'])
            || !is_array($data['pos1'])
            || !is_array($data['pos2'])
            || !Arrays::isStringMap($data['pos1'])
            || !Arrays::isStringMap($data['pos2'])
        ) {
            return null;
        }

        $manager = $this->main->getServer()->getWorldManager();

        $pos1 = Positions::fromArray($data['pos1'], $manager);
        $pos2 = Positions::fromArray($data['pos2'], $manager);

        if ($pos1 === null || $pos2 === null) {
            return null;
        }

        if ($pos1->getWorld() !== $pos2->getWorld()) {
            return null;
        }

        return [$pos1, $pos2];
    }

    public function setMidZone(
        Position $pos1,
        Position $pos2
    ): void {
        $this->db->set('midzone', [
            'pos1' => Positions::toArray($pos1),
            'pos2' => Positions::toArray($pos2)
        ]);
        $this->db->save();

        $this->dropMidZoneBox();
    }

    public function clearMidZone(): void
    {
        $this->db->remove('midzone');
        $this->db->save();

        $this->dropMidZoneBox();
    }

    public function hasMidZone(): bool
    {
        return $this->getMidZone() !== null;
    }

    /**
     * Memoized mid-zone box for the per-tick scoreboard checks. Null when no
     * zone is set or its world is gone.
     */
    public function midZoneBox(): ?CollisionBox
    {
        if ($this->midZoneBox !== null) {
            return $this->midZoneBox;
        }

        $region = $this->getMidZone();

        if ($region === null) {
            return null;
        }

        [$pos1, $pos2] = $region;

        $this->midZoneBox = new CollisionBox(
            $pos1,
            $pos2,
            $pos1->getWorld()
        );

        return $this->midZoneBox;
    }

    private function dropMidZoneBox(): void
    {
        $this->midZoneBox = null;
    }

    /**
     * Whether $world is the lobby map: the world holding the hub teleport
     * target. Worlds are compared by folder name so a reloaded world instance
     * still matches.
     */
    public function isLobbyMap(
        World $world
    ): bool {
        $lobby = $this->getLobby();

        if ($lobby === null) {
            return false;
        }

        return $lobby->getWorld()->getFolderName() === $world->getFolderName();
    }

    /**
     * Whether the anti-grief rules apply in $world: map protection must be
     * enabled and $world must be the lobby map.
     *
     * The position parameter is retained so existing callers keep compiling;
     * protection is map-wide and the exact spot no longer matters.
     */
    public function isGuarded(
        World $world,
        ?Vector3 $position = null
    ): bool {
        if (!$this->protectionEnabled()) {
            return false;
        }

        return $this->isLobbyMap($world);
    }

    public function protectionEnabled(): bool
    {
        return $this->main->getConfigManager()->getBool(
            'lobby.protection-enabled',
            true
        );
    }

    public function joinTeleportMode(): string
    {
        $mode = strtolower(
            $this->main->getConfigManager()->getString(
                'lobby.join-teleport',
                'first'
            )
        );

        return match ($mode) {
            'always', 'never' => $mode,
            default => 'first'
        };
    }

    public function noFallDamage(): bool
    {
        return $this->main->getConfigManager()->getBool(
            'lobby.no-fall-damage',
            true
        );
    }

    public function voidRescue(): bool
    {
        return $this->main->getConfigManager()->getBool(
            'lobby.void-rescue',
            true
        );
    }

    public function noHunger(): bool
    {
        return $this->main->getConfigManager()->getBool(
            'lobby.no-hunger',
            true
        );
    }
}