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
 * The lobby: where players arrive, what they cannot break, and which damage
 * and hunger rules apply there.
 *
 * Everything persistent lives in lobby.json: the hub teleport target, the
 * mid-lobby welcome position, and the optional protection cuboid. Behaviour
 * switches (join teleport, fall/void/hunger handling) live in config.yml under
 * `lobby:` because admins flip those far more often than they move the lobby.
 *
 * The protection box is intentionally reusable: {@link isGuarded()} is a
 * public geometric query any system can use, instead of every feature growing
 * its own hardcoded area check.
 */
final class LobbyManager
{
    private const FILE_NAME = 'lobby.json';

    private Config $db;

    /**
     * Memoized protection box. Null means "not built or stale" and is rebuilt
     * lazily, so per-event checks never allocate.
     */
    private ?CollisionBox $protectionBox = null;

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

        $this->dropProtectionBox();
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
     * @return array{Position, Position}|null
     */
    public function getProtection(): ?array
    {
        $data = $this->db->get('protection');

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

    public function setProtection(
        Position $pos1,
        Position $pos2
    ): void {
        $this->db->set('protection', [
            'pos1' => Positions::toArray($pos1),
            'pos2' => Positions::toArray($pos2)
        ]);
        $this->db->save();

        $this->dropProtectionBox();
    }

    public function clearProtection(): void
    {
        $this->db->remove('protection');
        $this->db->save();

        $this->dropProtectionBox();
    }

    public function hasProtection(): bool
    {
        return $this->getProtection() !== null;
    }

    /**
     * Whether the anti-grief rules apply at this spot: protection must be set
     * and enabled, and the spot must be inside it. One canonical area query —
     * the world-aware {@link CollisionBox::isInWorld()} does the world check
     * and the containment in a single call.
     */
    public function isGuarded(
        World $world,
        Vector3 $position
    ): bool {
        if (!$this->protectionEnabled()) {
            return false;
        }

        return $this->protectionBox()?->isInWorld($position, $world) ?? false;
    }

    private function protectionBox(): ?CollisionBox
    {
        if ($this->protectionBox !== null) {
            return $this->protectionBox;
        }

        $region = $this->getProtection();

        if ($region === null) {
            return null;
        }

        [$pos1, $pos2] = $region;

        $this->protectionBox = new CollisionBox(
            $pos1,
            $pos2,
            $pos1->getWorld()
        );

        return $this->protectionBox;
    }

    private function dropProtectionBox(): void
    {
        $this->protectionBox = null;
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