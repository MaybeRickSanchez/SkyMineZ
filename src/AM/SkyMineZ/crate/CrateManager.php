<?php

declare(strict_types=1);

namespace AM\SkyMineZ\crate;

use AM\SkyMineZ\Main;
use AM\SkyMineZ\useless\Arrays;
use AM\SkyMineZ\useless\ReadOnlyInventory;
use AM\SkyMineZ\useless\SpreadTask;
use AM\SkyMineZ\useless\Worlds;
use pocketmine\block\ShulkerBox;
use pocketmine\block\DyedShulkerBox;
use pocketmine\block\VanillaBlocks;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\Position;
use pocketmine\world\World;
use pocketmine\world\WorldManager;
use RuntimeException;

/**
 * Owns every crate on the server.
 *
 * Crates live in plugin_data/crates.json. Keys are stored per crate by id, and
 * rewards are serialised as base64 Bedrock NBT so the full item state survives a
 * restart.
 *
 * Crates are indexed by their block position, because a crate lookup happens on
 * every block interaction and a linear scan over every crate would make each
 * click cost O(n).
 */
final class CrateManager
{
    private const FILE_NAME = 'crates.json';

    /** @var array<string, Crate> */
    private array $crates = [];

    /**
     * Position index: "worldFolder:x:y:z" => crate name.
     *
     * @var array<string, string>
     */
    private array $index = [];

    private ReadOnlyInventory $readOnlyInventory;

    public function __construct(
        private Main $main
    ) {
        try {
            $virtualWindow = $this->main->getVirtualWindow();
        } catch (\Error) {
            $virtualWindow = null;
        }
        $this->readOnlyInventory = new ReadOnlyInventory(
            $this->main->getServer(),
            $this->main,
            $virtualWindow
        );
    }

    public function load(): void
    {
        $this->crates = [];
        $this->index = [];

        $db = $this->main->getCrateDB();

        $worldManager = $this->main->getServer()
            ->getWorldManager();

        foreach (
            $db->getAll() as $crateName => $crateData
        ) {
            if (
                !is_string($crateName)
                || !is_array($crateData)
                || !Arrays::isStringMap($crateData)
            ) {
                continue;
            }

            $crate = $this->createFromArray(
                $crateName,
                $crateData,
                $worldManager
            );

            if ($crate === null) {
                $this->main->getLogger()->warning(
                    "Skipped malformed crate '{$crateName}' in " . self::FILE_NAME
                );

                continue;
            }

            $this->crates[$crateName] = $crate;
            $this->index[
                self::key(
                    $crate->getPosition()
                )
            ] = $crateName;

            $crate->spawn();
            $crate->update();
        }
    }

    public function saveAll(): void
    {
        $db = $this->main->getCrateDB();

        $data = [];

        foreach (
            $this->crates as $name => $crate
        ) {
            $data[$name] = $crate->toArray();
        }

        $db->setAll($data);
        $db->save();
    }

    public function save(
        string $name
    ): void {
        $crate = $this->crates[$name] ?? null;

        if ($crate === null) {
            return;
        }

        $this->main->getCrateDB()->set(
            $name,
            $crate->toArray()
        );
        $this->main->getCrateDB()->save();
    }

    /**
     * @throws RuntimeException when the name is taken or the world is missing
     */
    public function create(
        string $name,
        Vector3 $position,
        string|World $world
    ): Crate {
        if ($this->hasCrate($name)) {
            throw new RuntimeException(
                "Crate '$name' already exists."
            );
        }

        $position = new Position(
            $position->x,
            $position->y,
            $position->z,
            $this->resolveWorld(
                $world,
                $this->main->getServer()
                    ->getWorldManager()
            )
        );

        $color = Crate::colorFromName(
            $this->main->getConfigManager()->getString('crates.default-color', 'purple')
        );

        $crate = new Crate(
            $this->main,
            $this->readOnlyInventory,
            $name,
            $position,
            $color
        );

        $this->crates[$name] = $crate;
        $this->index[self::key(
            $position
        )] = $name;

        $crate->spawn();

        $this->save($name);

        return $crate;
    }

    public function remove(
        string $name
    ): bool {
        $crate = $this->crates[$name] ?? null;

        if ($crate === null) {
            return false;
        }

        $position = $crate->getPosition();
        $world = $position->getWorld();

        $crate->despawn();

        $block = $world->getBlock($position);

        if (
            $block instanceof ShulkerBox
            || $block instanceof DyedShulkerBox
            || $block->hasSameTypeId(VanillaBlocks::CHEST())
        ) {
            // The chest branch only exists for crates placed before the
            // shulker migration; spawn() has replaced them all since.
            $world->setBlock(
                $position,
                VanillaBlocks::AIR()
            );
        }

        unset(
            $this->crates[$name],
            $this->index[self::key($position)]
        );

        $db = $this->main->getCrateDB();

        if ($db->exists($name)) {
            $db->remove($name);
        }

        $db->save();

        return true;
    }

    public function hasCrate(
        string $name
    ): bool {
        return isset($this->crates[$name]);
    }

    public function getCrate(
        string $name
    ): ?Crate {
        return $this->crates[$name] ?? null;
    }

    /**
     * @return array<string, Crate>
     */
    public function getCrates(): array
    {
        return $this->crates;
    }

    public function count(): int
    {
        return count($this->crates);
    }

    /**
     * The crate standing on a block, or null. O(1) through the position index.
     */
    public function getCrateAt(
        Position $position
    ): ?Crate {
        $name = $this->index[self::key(
            $position
        )] ?? null;

        if ($name === null) {
            return null;
        }

        return $this->crates[$name] ?? null;
    }

    /**
     * Pushes every crate label to a player who just spawned in.
     */
    public function spawnTo(
        Player $player
    ): void {
        SpreadTask::spread(
            $this->main,
            $this->crates,
            8,
            static function(
                mixed $crate
            ) use ($player): void {
                if (
                    !$crate instanceof Crate
                    || $crate->getWorld() !==
                    $player->getWorld()
                ) {
                    return;
                }

                $crate->spawnText($player);
            }
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function createFromArray(
        string $name,
        array $data,
        WorldManager $worldManager
    ): ?Crate {
        if (
            !isset(
                $data['world'],
                $data['x'],
                $data['y'],
                $data['z']
            )
            || !is_string($data['world'])
            || !is_numeric($data['x'])
            || !is_numeric($data['y'])
            || !is_numeric($data['z'])
        ) {
            return null;
        }

        $world = Worlds::resolve(
            $worldManager,
            $data['world']
        );

        if ($world === null) {
            return null;
        }

        $position = new Position(
            (float) $data['x'],
            (float) $data['y'],
            (float) $data['z'],
            $world
        );

        $color = isset($data['color']) && is_string($data['color'])
            ? Crate::colorFromName($data['color'])
            : null;

        $crate = new Crate(
            $this->main,
            $this->readOnlyInventory,
            $name,
            $position,
            $color
        );

        foreach (
            (array) ($data['keys'] ?? []) as $keyId
        ) {
            if (is_string($keyId) && $keyId !== '') {
                $crate->addKey($keyId);
            }
        }

        foreach (
            (array) ($data['rewards'] ?? []) as $rewardData
        ) {
            if (
                !is_array($rewardData)
                || !Arrays::isStringMap($rewardData)
            ) {
                continue;
            }

            $reward = Reward::fromArray($rewardData);

            if ($reward !== null) {
                $crate->addRewardObject($reward);
            }
        }

        return $crate;
    }

    private function resolveWorld(
        string|World $world,
        WorldManager $worldManager
    ): World {
        if ($world instanceof World) {
            return $world;
        }

        $resolved = Worlds::resolve(
            $worldManager,
            $world
        );

        if ($resolved === null) {
            throw new RuntimeException(
                "World '$world' could not be loaded."
            );
        }

        return $resolved;
    }

    /**
     * Position index key. Uses the world *folder* name because that is what is
     * stored on disk and stays stable across restarts.
     */
    private static function key(
        Position $position
    ): string {
        return $position->getWorld()->getFolderName()
            . ':'
            . $position->getFloorX()
            . ':'
            . $position->getFloorY()
            . ':'
            . $position->getFloorZ();
    }
}