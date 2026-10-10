<?php

declare(strict_types=1);

namespace AM\SkyMineZ\mine;

use AM\SkyMineZ\Main;
use AM\SkyMineZ\useless\Arrays;
use pocketmine\math\Vector3;
use pocketmine\scheduler\TaskHandler;
use pocketmine\world\Position;
use pocketmine\world\World;

/**
 * A named mine: a cuboid, the blocks it is built from, and a hologram showing
 * the name plus the countdown to the next automatic reset.
 *
 * A refill runs across several ticks (see {@link MineFillTask}). Only one refill
 * can be in flight per mine; starting a second one is refused.
 */
final class Mine
{
    private MineBox $mineBox;

    /** @var list<MineBlock> */
    private array $mineBlocks = [];

    private MineInfo $info;

    /**
     * Seconds between two automatic resets. 0 disables automatic resets.
     */
    private int $resetInterval;

    /** @var TaskHandler<MineFillTask>|null */
    private ?TaskHandler $fillTask = null;

    public function __construct(
        private Main $main,
        string $name,
        Vector3 $position1,
        Vector3 $position2,
        World $world,
        int $resetInterval = 300,
        ?Position $labelPosition = null
    ) {
        $this->mineBox = new MineBox(
            $position1,
            $position2,
            $world
        );

        $this->resetInterval = max(
            0,
            $resetInterval
        );

        $this->info = new MineInfo($name);

        $this->info->setResetInterval($this->resetInterval);

        if ($labelPosition !== null) {
            $this->info->setPosition($labelPosition);
        }
    }

    public function getName(): string
    {
        return $this->info->getMineName();
    }

    public function getMineBox(): MineBox
    {
        return $this->mineBox;
    }

    public function getInfo(): MineInfo
    {
        return $this->info;
    }

    public function getWorld(): World
    {
        return $this->mineBox->getWorld();
    }

    public function getResetInterval(): int
    {
        return $this->resetInterval;
    }

    public function setResetInterval(
        int $resetInterval
    ): self {
        $this->resetInterval = max(
            0,
            $resetInterval
        );

        $this->info->setResetInterval($this->resetInterval);

        if ($this->resetInterval > 0) {
            $this->info->setNextResetAt(
                $this->nextResetTimestamp()
            );
        } else {
            $this->info->setNextResetAt(0);
        }

        return $this;
    }

    public function addBlock(
        MineBlock $block
    ): self {
        $this->mineBlocks[] = $block;

        return $this;
    }

    /**
     * @return list<MineBlock>
     */
    public function getBlocks(): array
    {
        return $this->mineBlocks;
    }

    /**
     * Sum of the configured percentages. Anything other than 100 is legal; the
     * refill scales the entries by their share of the total.
     */
    public function getTotalPercent(): int
    {
        $total = 0;

        foreach (
            $this->mineBlocks as $block
        ) {
            $total += $block->getPercent();
        }

        return $total;
    }

    public function removeBlock(
        int $index
    ): self {
        /*
         * Every index in this list is player facing (/mine block remove <index>),
         * so removing an entry has to re-pack the list instead of leaving a gap.
         */
        $this->mineBlocks = Arrays::removeIndex($this->mineBlocks, $index);

        return $this;
    }

    public function clearBlocks(): self {
        $this->mineBlocks = [];

        return $this;
    }

    /**
     * Starts a refill. Blocks are written over several ticks; poll
     * {@link isFilling()} for completion or listen for the hologram changing.
     *
     * @return bool false when the mine has no blocks, is empty, or is already
     *              being refilled
     */
    public function reset(
        string $reason = 'manual'
    ): bool {
        if ($this->isFilling()) {
            return false;
        }

        if ($this->mineBlocks === []) {
            return false;
        }

        $pool = $this->mineBox->buildPool(
            $this->mineBlocks,
            $this->mineBox->getVolume()
        );

        if ($pool === []) {
            return false;
        }

        $this->mineBox->evacuatePlayers();

        $task = new MineFillTask(
            $this->mineBox,
            $pool,
            $this->main
                ->getConfigManager()
                ->getInt('mines.blocks-per-tick', 3000),
            function(): void {
                $this->onFillComplete();
            }
        );

        $this->fillTask = $this->main->getScheduler()->scheduleRepeatingTask(
            $task,
            1
        );

        $this->info->setFilling(true);

        if ($this->resetInterval > 0) {
            $this->info->setNextResetAt(
                $this->nextResetTimestamp()
            );
        }

        return true;
    }

    /**
     * Called by {@link MineFillTask} once the last block was written.
     */
    public function onFillComplete(): void
    {
        $this->fillTask = null;

        $this->info->setFilling(false);
    }

    public function isFilling(): bool
    {
        return $this->fillTask !== null
            && !$this->fillTask->isCancelled();
    }

    public function cancelFill(): void
    {
        $this->fillTask?->cancel();
        $this->fillTask = null;

        $this->info->setFilling(false);
    }

    public function spawn(): void
    {
        $this->info->spawn();
    }

    public function deSpawn(): void
    {
        $this->info->deSpawn();
    }

    public function isIn(
        Vector3 $position
    ): bool {
        return $this->mineBox->isIn($position);
    }

    public function nextResetTimestamp(
        ?int $from = null
    ): int {
        if ($this->resetInterval <= 0) {
            return 0;
        }

        return ($from ?? time()) + $this->resetInterval;
    }

    public function updateHologram(): void
    {
        $this->info->updateTime();
    }

    /**
     * @return array{
     *     world: string,
     *     pos1: array{float, float, float},
     *     pos2: array{float, float, float},
     *     resetInterval: int,
     *     label: array{string, float, float, float}|null,
     *     blocks: list<array{block: string, percent: int}>
     * }
     */
    public function toArray(): array
    {
        $label = $this->info->getPosition();

        return [
            'world' => $this->getWorld()->getFolderName(),
            'pos1' => [
                $this->mineBox->getPos1()->x,
                $this->mineBox->getPos1()->y,
                $this->mineBox->getPos1()->z
            ],
            'pos2' => [
                $this->mineBox->getPos2()->x,
                $this->mineBox->getPos2()->y,
                $this->mineBox->getPos2()->z
            ],
            'resetInterval' => $this->resetInterval,
            'label' => $label === null ? null : [
                $label->getWorld()->getFolderName(),
                $label->x,
                $label->y,
                $label->z
            ],
            'blocks' => array_map(
                static fn(
                    MineBlock $block
                ): array => $block->toArray(),
                $this->mineBlocks
            )
        ];
    }
}