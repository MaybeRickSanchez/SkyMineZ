<?php

declare(strict_types=1);

namespace AM\SkyMineZ\lagmaker;

use AM\SkyMineZ\useless\SpreadTask;
use Closure;
use pocketmine\entity\object\ItemEntity;
use pocketmine\plugin\PluginBase;
use pocketmine\world\World;

/**
 * Runs one item-entity cleanup pass: collect candidates across worlds, then
 * despawn a bounded slice per tick.
 *
 * State machine with exactly two states. `begin()` starts a pass and is
 * refused while one is already running; `finish()`/`stop()` always land in
 * the idle state. Every entity is re-validated at the moment it is processed,
 * so picking it up, merging it, or unloading its world mid-pass can never
 * produce an invalid operation — the pass is idempotent by construction.
 *
 * Collection and draining run concurrently: the SpreadTask queues one world
 * per tick while `processBatch()` drains a bounded slice per tick. The pass
 * only ends once collection has finished AND the queue is empty, so a fast
 * drain can never cut off worlds the producer has not visited yet.
 *
 * Task ownership: the queue-building SpreadTask is scheduled here and cancels
 * itself on completion. `stop()` flips the active flag first, so a spread tick
 * that fires after shutdown collects nothing and still terminates cleanly.
 * Nothing scheduled here outlives `stop()`.
 */
final class ItemSweeper
{
    /** @var list<ItemEntity> */
    private array $queue = [];

    private bool $cleaning = false;

    private bool $active = true;

    /**
     * Whether the SpreadTask producer is still visiting worlds. While true,
     * an empty queue means "nothing collected yet", not "pass complete".
     */
    private bool $collecting = false;

    /** Increments per pass; stale SpreadTask completions carry an old value. */
    private int $generation = 0;

    /** @var Closure(ItemEntity): bool|null */
    private ?Closure $shouldQueue = null;

    private int $perTick;

    public function __construct(
        private PluginBase $plugin,
        int $perTick = 200
    ) {
        $this->perTick = max(1, $perTick);
    }

    public function setPerTick(
        int $perTick
    ): void {
        $this->perTick = max(1, $perTick);
    }

    public function isCleaning(): bool
    {
        return $this->cleaning;
    }

    /**
     * Starts a pass over the given worlds. Returns false when a pass is already
     * running or the sweeper was stopped.
     *
     * @param list<World> $worlds
     * @param callable(ItemEntity): bool $shouldQueue decides per entity
     */
    public function begin(
        array $worlds,
        callable $shouldQueue
    ): bool {
        if ($this->cleaning || !$this->active) {
            return false;
        }

        $this->queue = [];
        $this->cleaning = true;
        $this->collecting = true;
        ++$this->generation;

        $generation = $this->generation;

        $this->shouldQueue = $shouldQueue instanceof Closure
            ? $shouldQueue
            : Closure::fromCallable($shouldQueue);

        SpreadTask::spread(
            $this->plugin,
            $worlds,
            1,
            function(mixed $world): void {
                if (!$this->active) {
                    return;
                }

                if ($world instanceof World) {
                    $this->queueWorld($world);
                }
            },
            function() use ($generation): void {
                // A newer pass (or stop()) may have started since: only the
                // owning generation may mark collection complete.
                if ($generation === $this->generation) {
                    $this->collecting = false;

                    $this->maybeFinish();
                }
            }
        );

        return true;
    }

    /**
     * Despawns a bounded slice of the queue. Every entry is re-validated here:
     * anything picked up, merged or unloaded since it was queued is skipped.
     */
    public function processBatch(): void
    {
        if (!$this->cleaning) {
            return;
        }

        for (
            $i = 0;
            $i < $this->perTick
            && $this->queue !== [];
            ++$i
        ) {
            $entity = array_pop($this->queue);

            if (
                $entity === null
                || $entity->isClosed()
                || $entity->isFlaggedForDespawn()
            ) {
                continue;
            }

            $entity->flagForDespawn();
        }

        $this->maybeFinish();
    }

    /**
     * Drops queued entities of an unloaded world. They are all closed already
     * (and therefore harmless), but holding them would pin dead objects until
     * the pass ends.
     */
    public function purgeWorld(
        World $world
    ): void {
        if ($this->queue === []) {
            return;
        }

        $this->queue = array_values(
            array_filter(
                $this->queue,
                static function(ItemEntity $entity) use ($world): bool {
                    // getWorld() on a closed entity is safe, but identity
                    // compare avoids touching dead objects more than needed.
                    return !$entity->isClosed() && $entity->getWorld() !== $world;
                }
            )
        );

        $this->maybeFinish();
    }

    /**
     * Ends the pass once the queue drained and collection finished. One
     * canonical termination check shared by the collector, the batch runner
     * and the world purge.
     */
    private function maybeFinish(): void
    {
        if ($this->queue === [] && !$this->collecting) {
            $this->finish();
        }
    }

    /**
     * Stops everything now. After this, no queued entity will be touched and no
     * scheduled spread tick will collect anything.
     */
    public function stop(): void
    {
        $this->finish();
        $this->active = false;
    }

    private function finish(): void
    {
        ++$this->generation;
        $this->queue = [];
        $this->cleaning = false;
        $this->collecting = false;
        $this->shouldQueue = null;
    }

    private function queueWorld(
        World $world
    ): void {
        $shouldQueue = $this->shouldQueue;

        if ($shouldQueue === null) {
            return;
        }

        foreach ($world->getEntities() as $entity) {
            if (
                !$entity instanceof ItemEntity
                || $entity->isClosed()
                || $entity->isFlaggedForDespawn()
            ) {
                continue;
            }

            if ($shouldQueue($entity)) {
                $this->queue[] = $entity;
            }
        }
    }
}