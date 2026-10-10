<?php

declare(strict_types=1);

namespace AM\SkyMineZ\useless;

use Closure;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\Task;
use pocketmine\scheduler\TaskHandler;

/**
 * Walks a list incrementally, visiting a fixed number of entries per tick, so
 * iterating a large collection never spends a whole tick of the main thread.
 *
 * The task is self-cancelling: as soon as every entry has been visited the
 * handler cancels itself and the completion callback runs. Always schedule it
 * through {@link SpreadTask::spread()}, which arms a delayed repeating task
 * and therefore keeps visiting entries on every tick until the list is done.
 *
 *     SpreadTask::spread($plugin, $bigList, 500, function($entry): void {
 *         // at most 500 entries per tick
 *     });
 */
final class SpreadTask extends Task
{
    /** @var list<mixed> */
    private array $entries;

    private readonly Closure $callback;

    private readonly ?Closure $onComplete;

    private int $index = 0;

    private bool $finished = false;

    /**
     * @param iterable<mixed> $list entries to walk; arrays and Traversable are supported
     * @param int $perTick how many entries to visit per tick, must be >= 1
     * @param callable(mixed, int): void $callback receives the entry and its index
     * @param (callable(): void)|null $onComplete run once after the last entry was visited
     *
     * @throws \InvalidArgumentException when $perTick is below 1
     */
    public function __construct(
        iterable $list,
        private readonly int $perTick,
        callable $callback,
        ?callable $onComplete = null
    ) {
        if ($perTick < 1) {
            throw new \InvalidArgumentException(
                'perTick must be greater than 0.'
            );
        }

        $this->entries = self::flatten($list);
        $this->callback = self::toClosure($callback);
        $this->onComplete = $onComplete === null
            ? null
            : self::toClosure($onComplete);
    }

    /**
     * Schedules a one-shot spread over $list.
     *
     * @param iterable<mixed> $list
     * @param callable(mixed, int): void $callback
     * @param (callable(): void)|null $onComplete
     *
     * @return TaskHandler<self>|null null when the list is empty or the plugin
     *                           is already disabled; the callback still runs for
     *                           an empty list so callers stay in sync
     */
    public static function spread(
        PluginBase $plugin,
        iterable $list,
        int $perTick,
        callable $callback,
        ?callable $onComplete = null,
        int $delay = 1
    ): ?TaskHandler {
        $task = new self(
            $list,
            $perTick,
            $callback,
            $onComplete
        );

        if ($task->getTotal() === 0) {
            if ($onComplete !== null) {
                $onComplete();
            }

            return null;
        }

        if (!$plugin->isEnabled()) {
            return null;
        }

        /*
         * Delayed *repeating*: a one-shot delayed task would run onRun() exactly
         * once and silently drop every entry past the first batch. The task
         * cancels itself in finish(), so no handler leaks.
         */
        return $plugin
            ->getScheduler()
            ->scheduleDelayedRepeatingTask(
                $task,
                max(1, $delay),
                1
            );
    }

    public function onRun(): void
    {
        if ($this->finished) {
            $this->getHandler()?->cancel();

            return;
        }

        $total = count($this->entries);

        if ($this->index >= $total) {
            $this->finish();

            return;
        }

        /*
         * The per-tick budget is local to each run: without a fresh counter
         * every tick the first tick would consume the whole budget and every
         * later tick would visit zero entries, stalling the task forever.
         */
        $processed = 0;

        while (
            $this->index < $total
            && $processed < $this->perTick
        ) {
            /*
             * The callback may cancel this task (for example when the player it
             * belongs to disconnects). Reading the handler on every iteration
             * would be wasteful, so the loop only watches the entry count and
             * the next tick picks up the cancellation.
             */
            ($this->callback)(
                $this->entries[$this->index],
                $this->index
            );

            ++$this->index;
            ++$processed;
        }

        if ($this->index >= $total) {
            $this->finish();
        }
    }

    public function finish(): void
    {
        if ($this->finished) {
            return;
        }

        $this->finished = true;

        $this->getHandler()?->cancel();

        if ($this->onComplete !== null) {
            ($this->onComplete)();
        }
    }

    public function getTotal(): int
    {
        return count($this->entries);
    }

    private static function toClosure(callable $callable): Closure
    {
        return $callable instanceof Closure
            ? $callable
            : Closure::fromCallable($callable);
    }

    /**
     * Materialises the list once so that later mutations of the caller's array
     * cannot corrupt an in-flight task. Traversables are consumed eagerly,
     * which also means generators have to be finite.
     *
     * @param iterable<mixed> $list
     *
     * @return list<mixed>
     */
    private static function flatten(iterable $list): array
    {
        if (is_array($list)) {
            return array_values($list);
        }

        $entries = [];

        foreach ($list as $entry) {
            $entries[] = $entry;
        }

        return $entries;
    }
}