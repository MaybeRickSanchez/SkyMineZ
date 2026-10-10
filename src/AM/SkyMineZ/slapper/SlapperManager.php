<?php

declare(strict_types=1);

namespace AM\SkyMineZ\slapper;

use AM\SkyMineZ\Main;
use AM\SkyMineZ\useless\Arrays;
use AM\SkyMineZ\useless\SpreadTask;
use AM\SkyMineZ\useless\Worlds;
use pocketmine\block\Block;
use pocketmine\entity\Entity;
use pocketmine\entity\Location;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\utils\Config;
use pocketmine\world\Position;
use pocketmine\world\World;
use RuntimeException;

final class SlapperManager
{
    /**
     * @var array<string, Slapper>
     */
    private array $slappers = [];

    /**
     * @var array<int, Slapper>
     */
    private array $entities = [];

    /**
     * @var array<string, SlapperBlock>
     */
    private array $blocks = [];

    /**
     * Block position index: "worldFolder:x:y:z" => slapper block name.
     *
     * A lookup happens on every block interaction, so a linear scan over every
     * slapper block would make each click cost O(n).
     *
     * @var array<string, string>
     */
    private array $blockIndex = [];

    private Config $db;

    private SlapperLookTask $lookTask;

    public function __construct(
        private Main $main
    ) {
        $this->db = new Config(
            $this->main->getDataFolder() .
            'slappers.json',
            Config::JSON
        );

        $this->lookTask = new SlapperLookTask($this);

        /*
         * One repeating task for all slappers. Plugin tasks die with the
         * scheduler on disable, so no manual cancellation is needed.
         */
        $this->main->getScheduler()->scheduleRepeatingTask(
            $this->lookTask,
            SlapperLookTask::INTERVAL
        );
    }

    public function load(): void
    {
        $this->despawnAll();

        $this->slappers = [];
        $this->entities = [];
        $this->blocks = [];
        $this->blockIndex = [];

        foreach (
            $this->db->getAll()
            as $name => $data
        ) {
if (
                !is_string($name)
                || !is_array($data)
                || !Arrays::isStringMap($data)
            ) {
                continue;
            }

            $slapper =
                $this->createSlapperFromArray(
                    $name,
                    $data
                );

            if ($slapper === null) {
                continue;
            }

            $this->slappers[$name] =
                $slapper;

            foreach (
                (array) (
                    $data['blocks'] ?? []
                ) as $blockName => $blockData
            ) {
                if (
                    !is_string($blockName)
                    || !is_array($blockData)
                    || !Arrays::isStringMap($blockData)
                ) {
                    continue;
                }

                $block =
                    $this->createBlockFromArray(
                        $blockName,
                        $blockData
                    );

                if ($block === null) {
                    continue;
                }

                $this->blocks[$blockName] =
                    $block;

                $this->blockIndex[self::positionKey(
                    $block->getPosition()
                )] = $blockName;
            }

            $this->spawn($name);

            foreach (
                $this->getBlocksForSlapper($name)
                as $block
            ) {
                $block->spawn();
            }
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function createSlapperFromArray(
        string $name,
        array $data
    ): ?Slapper {
        if (
            !isset(
                $data['world'],
                $data['x'],
                $data['y'],
                $data['z'],
                $data['skin']
            )
            || !is_string($data['world'])
            || !is_numeric($data['x'])
            || !is_numeric($data['y'])
            || !is_numeric($data['z'])
        ) {
            return null;
        }

        $yaw = $data['yaw'] ?? 0;
        $pitch = $data['pitch'] ?? 0;

        $world =
            $this->resolveWorld(
                $data['world']
            );

        if ($world === null) {
            return null;
        }

        $skin =
            $this->createSkinFromArray(
                $data['skin']
            );

        if ($skin === null) {
            return null;
        }

        $location =
            new Location(
                (float) $data['x'],
                (float) $data['y'],
                (float) $data['z'],
                $world,
                is_numeric($yaw) ? (float) $yaw : 0.0,
                is_numeric($pitch) ? (float) $pitch : 0.0
            );

        $slapper = new Slapper(
            $name,
            $location,
            $skin
        );

        foreach (
            (array) (
                $data['commands'] ?? []
            ) as $command
        ) {
            if (is_string($command)) {
                $slapper->addCommand(
                    $command
                );
            }
        }

        foreach (
            (array) (
                $data['messages'] ?? []
            ) as $message
        ) {
            if (is_string($message)) {
                $slapper->addMessage(
                    $message
                );
            }
        }

        return $slapper;
    }

    private function createSkinFromArray(
        mixed $data
    ): ?\pocketmine\entity\Skin {
        if (!is_array($data)) {
            return null;
        }

        if (
            !isset(
                $data['id'],
                $data['data']
            )
        ) {
            return null;
        }

        $skinData =
            base64_decode(
                (string) $data['data'],
                true
            );

        if ($skinData === false) {
            return null;
        }

        $capeData =
            base64_decode(
                (string) (
                    $data['cape'] ?? ''
                ),
                true
            );

        if ($capeData === false) {
            $capeData = '';
        }

        $geometryData =
            base64_decode(
                (string) (
                    $data['geometryData'] ?? ''
                ),
                true
            );

        if ($geometryData === false) {
            $geometryData = '';
        }

        try {
            return new \pocketmine\entity\Skin(
                (string) $data['id'],
                $skinData,
                $capeData,
                (string) (
                    $data['geometryName'] ?? ''
                ),
                $geometryData
            );
        } catch (\Throwable) {
            return null;
        }
    }

    public function addSlapper(
        string $name,
        Position $position,
        Player|\pocketmine\entity\Skin $skin
    ): Slapper {
        if ($this->hasSlapper($name)) {
            throw new RuntimeException(
                "Slapper '{$name}' already exists."
            );
        }

        if ($skin instanceof Player) {
            $creatorLoc = $skin->getLocation();

            $yaw = $creatorLoc->getYaw();
            $pitch = $creatorLoc->getPitch();
            $skin = $skin->getSkin();
        } else {
            $yaw = 0.0;
            $pitch = 0.0;
        }

        $location =
            new \pocketmine\entity\Location(
                $position->x,
                $position->y,
                $position->z,
                $position->getWorld(),
                $yaw,
                $pitch
            );

        $slapper = new Slapper(
            $name,
            $location,
            $skin
        );

        $this->slappers[$name] =
            $slapper;

        $this->spawn($name);

        return $slapper;
    }

    public function spawn(
        string $name
    ): bool {
        $slapper =
            $this->getSlapper($name);

        if ($slapper === null) {
            return false;
        }

        $this->forgetEntity($slapper);

        $slapper->spawn();

        if ($slapper->getEntity() === null) {
            return false;
        }

        $this->trackEntity($slapper);

        return true;
    }

    /**
     * Drops a slapper's live entity from the id index. The same few lines
     * appeared in spawn(), move() and removeSlapper().
     */
    private function forgetEntity(
        Slapper $slapper
    ): void {
        $entity = $slapper->getEntity();

        if ($entity !== null) {
            unset(
                $this->entities[$entity->getId()]
            );
        }
    }

    /**
     * (Re)registers a slapper's live entity in the id index.
     */
    private function trackEntity(
        Slapper $slapper
    ): void {
        $entity = $slapper->getEntity();

        if ($entity !== null) {
            $this->entities[$entity->getId()] = $slapper;
        }
    }

    public function despawnAll(): void
    {
        foreach (
            $this->slappers as $slapper
        ) {
            $slapper->despawn();
        }

        foreach (
            $this->blocks as $block
        ) {
            $block->despawn();
        }

        $this->entities = [];
    }

    /**
     * Pushes every slapper entity and block label to a player who just spawned
     * in. Spread over ticks so a server with hundreds of slappers does not send
     * them all in one tick.
     */
    public function spawnTo(
        Player $player
    ): void {
        SpreadTask::spread(
            $this->main,
            $this->slappers,
            8,
            static function(
                mixed $slapper
            ) use ($player): void {
                if (
                    !$slapper instanceof Slapper
                    || $slapper->getLocation()
                        ->getWorld() !==
                    $player->getWorld()
                ) {
                    return;
                }

                $slapper->getEntity()?->spawnTo(
                    $player
                );
            }
        );

        SpreadTask::spread(
            $this->main,
            $this->blocks,
            8,
            static function(
                mixed $block
            ) use ($player): void {
                if (
                    !$block instanceof SlapperBlock
                    || $block->getWorld() !== $player->getWorld()
                ) {
                    return;
                }

                $block->spawnText($player);
            }
        );
    }

    public function addBlock(
        string $name,
        Position $position,
        Block $block,
        string $slapperName
    ): SlapperBlock {
        if ($this->hasBlock($name)) {
            throw new RuntimeException(
                "Slapper block '{$name}' already exists."
            );
        }

        if (
            !$this->hasSlapper(
                $slapperName
            )
        ) {
            throw new RuntimeException(
                "Slapper '{$slapperName}' does not exist."
            );
        }

        $slapperBlock =
            new SlapperBlock(
                $name,
                $position,
                $block,
                $slapperName
            );

        $this->blocks[$name] =
            $slapperBlock;

        $this->blockIndex[self::positionKey(
            $position
        )] = $name;

        $slapperBlock->spawn();

        return $slapperBlock;
    }

    public function getSlapper(
        string $name
    ): ?Slapper {
        return $this->slappers[$name] ?? null;
    }

    public function getByEntityId(
        int $entityId
    ): ?Slapper {
        return $this->entities[$entityId] ?? null;
    }

    public function getByEntity(
        Entity $entity
    ): ?Slapper {
        return $this->getByEntityId(
            $entity->getId()
        );
    }

    public function hasSlapper(
        string $name
    ): bool {
        return isset(
            $this->slappers[$name]
        );
    }

    /**
     * Moves an existing slapper, keeping its commands, messages and skin.
     *
     * The entity is teleported rather than respawned, so no client sees the NPC
     * disappear and reappear.
     */
    public function move(
        string $name,
        Position $position
    ): bool {
        $slapper = $this->getSlapper($name);

        if ($slapper === null) {
            return false;
        }

        $this->forgetEntity($slapper);

        $slapper->setPosition($position);

        $this->trackEntity($slapper);

        $this->save($name);

        return true;
    }

    /**
     * @return array<string, Slapper>
     */
    public function getSlappers(): array
    {
        return $this->slappers;
    }

    private function getBlock(
        string $name
    ): ?SlapperBlock {
        return $this->blocks[$name] ?? null;
    }

    /**
     * @return array<string, SlapperBlock>
     */
    public function getSlapperBlocks(): array
    {
        return $this->blocks;
    }

    /**
     * @return list<SlapperBlock>
     */
    public function getBlocksForSlapper(
        string $slapperName
    ): array {
        $result = [];

        foreach (
            $this->blocks as $block
        ) {
            if (
                $block->getSlapperName()
                === $slapperName
            ) {
                $result[] = $block;
            }
        }

        return $result;
    }

    /**
     * The slapper block standing at a position, or null. O(1).
     */
    public function getBlockAt(
        Vector3 $position
    ): ?SlapperBlock {
        if (!$position instanceof Position) {
            return null;
        }

        $name = $this->blockIndex[self::positionKey(
            $position
        )] ?? null;

        if ($name === null) {
            return null;
        }

        return $this->blocks[$name] ?? null;
    }

    public function hasBlock(
        string $name
    ): bool {
        return isset(
            $this->blocks[$name]
        );
    }

    public function removeSlapper(
        string $name
    ): bool {
        $slapper =
            $this->getSlapper($name);

        if ($slapper === null) {
            return false;
        }

        $this->forgetEntity($slapper);

        $slapper->despawn();

        $this->lookTask->forget($name);

        unset(
            $this->slappers[$name]
        );

        foreach (
            $this->blocks as $blockName => $block
        ) {
            if (
                $block->getSlapperName()
                !== $name
            ) {
                continue;
            }

            $block->remove();

            unset(
                $this->blocks[$blockName],
                $this->blockIndex[self::positionKey(
                    $block->getPosition()
                )]
            );
        }

        return true;
    }

    public function removeBlock(
        string $name
    ): bool {
        $block =
            $this->getBlock($name);

        if ($block === null) {
            return false;
        }

        $block->remove();

        unset(
            $this->blocks[$name],
            $this->blockIndex[self::positionKey(
                $block->getPosition()
            )]
        );

        return true;
    }

    public function save(
        string $name
    ): void {
        $slapper =
            $this->getSlapper($name);

        if ($slapper === null) {
            return;
        }

        $this->db->set(
            $name,
            $this->serializeSlapper($slapper)
        );

        $this->db->save();
    }

    public function saveAll(): void
    {
        $this->db->setAll([]);

        foreach (
            $this->slappers as $slapper
        ) {
            $this->db->set(
                $slapper->getName(),
                $this->serializeSlapper($slapper)
            );
        }

        $this->db->save();
    }

    /**
     * One canonical slapper record: base data plus attached blocks.
     *
     * @return array<string, mixed>
     */
    private function serializeSlapper(
        Slapper $slapper
    ): array {
        $data =
            $slapper->toArray();

        $data['blocks'] = [];

        foreach (
            $this->getBlocksForSlapper(
                $slapper->getName()
            ) as $block
        ) {
            $data['blocks'][
            $block->getName()
            ] =
                $block->toArray();
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function createBlockFromArray(
        string $name,
        array $data
    ): ?SlapperBlock {
        if (
            !isset(
                $data['world'],
                $data['x'],
                $data['y'],
                $data['z'],
                $data['slapper'],
                $data['block']
            )
            || !is_string($data['world'])
            || !is_string($data['slapper'])
            || !is_numeric($data['x'])
            || !is_numeric($data['y'])
            || !is_numeric($data['z'])
        ) {
            return null;
        }

        $world =
            $this->resolveWorld(
                $data['world']
            );

        if ($world === null) {
            return null;
        }

        $block =
            SlapperBlock::blockFromArray(
                $data
            );

        if ($block === null) {
            return null;
        }

        $position = new Position(
            (float) $data['x'],
            (float) $data['y'],
            (float) $data['z'],
            $world
        );

        return new SlapperBlock(
            $name,
            $position,
            $block,
            $data['slapper']
        );
    }

    /**
     * Position index key. Uses the world *folder* name because that is what is
     * stored on disk and stays stable across restarts. Requires a Position:
     * a plain Vector3 has no world and would collide across worlds under '?'.
     */
    private static function positionKey(
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

    private function resolveWorld(
        string $world
    ): ?World {
        return Worlds::resolve(
            $this->main
                ->getServer()
                ->getWorldManager(),
            $world
        );
    }
}