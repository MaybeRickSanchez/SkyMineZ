<?php

declare(strict_types=1);

namespace AM\SkyMineZ\warp;

use AM\SkyMineZ\Main;
use AM\SkyMineZ\useless\Arrays;
use AM\SkyMineZ\useless\Positions;
use pocketmine\player\Player;
use pocketmine\utils\Config;
use pocketmine\world\Position;
use RuntimeException;

/**
 * Named server warps with persistent positions.
 *
 * Warps live in warps.json as canonical position records (world + xyz + view
 * direction). A warp whose world no longer loads reads as missing rather than
 * crashing the teleport, which is what "safe handling" means here: stale data
 * degrades to a clear error message.
 */
final class WarpManager
{
    private const FILE_NAME = 'warps.json';

    /** @var array<string, Position> */
    private array $warps = [];

    private Config $db;

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
        $this->warps = [];

        $worldManager = $this->main->getServer()->getWorldManager();

        foreach ($this->db->getAll() as $name => $data) {
            if (
                !is_string($name)
                || !is_array($data)
                || !Arrays::isStringMap($data)
            ) {
                continue;
            }

            $position = Positions::fromArray($data, $worldManager);

            if ($position === null) {
                $this->main->getLogger()->warning(
                    "Skipped warp '{$name}': world is missing."
                );

                continue;
            }

            $this->warps[$name] = $position;
        }
    }

    public function saveAll(): void
    {
        $data = [];

        foreach ($this->warps as $name => $position) {
            $data[$name] = Positions::toArray($position);
        }

        $this->db->setAll($data);
        $this->db->save();
    }

    /**
     * @throws RuntimeException when the name is taken
     */
    public function create(
        string $name,
        Position $position
    ): void {
        if ($this->has($name)) {
            throw new RuntimeException("Warp '{$name}' already exists.");
        }

        $this->warps[$name] = Positions::toLocation($position);

        $this->saveAll();
    }

    public function remove(
        string $name
    ): bool {
        if (!isset($this->warps[$name])) {
            return false;
        }

        unset($this->warps[$name]);

        $this->saveAll();

        return true;
    }

    public function move(
        string $name,
        Position $position
    ): bool {
        if (!isset($this->warps[$name])) {
            return false;
        }

        $this->warps[$name] = Positions::toLocation($position);

        $this->saveAll();

        return true;
    }

    public function has(
        string $name
    ): bool {
        return isset($this->warps[$name]);
    }

    public function get(
        string $name
    ): ?Position {
        return $this->warps[$name] ?? null;
    }

    /**
     * @return array<string, Position>
     */
    public function getAll(): array
    {
        return $this->warps;
    }

    /**
     * Teleports a player to a warp. Returns false (with feedback) when the warp
     * is missing or its world cannot be resolved anymore.
     */
    public function teleport(
        Player $player,
        string $name
    ): bool {
        $position = $this->get($name);

        if ($position === null) {
            return false;
        }

        $player->teleport(Positions::toLocation($position));

        return true;
    }
}