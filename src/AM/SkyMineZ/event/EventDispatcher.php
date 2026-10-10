<?php

declare(strict_types=1);

namespace AM\SkyMineZ\event;

use pocketmine\event\Event;

/**
 * Thin wrapper around {@link Event::call()} with a name that reads better at the
 * call site.
 *
 * The plugin never registers its own listeners for these events, so this class
 * exists purely for other plugins.
 */
final class EventDispatcher
{
    public static function dispatch(Event $event): void
    {
        /*
         * hasHandlers() keeps the hot paths (block breaks, sidebar refreshes)
         * from allocating events nobody listens to. It must be called on the
         * concrete event, because the check is late-static-bound.
         */
        if (!$event::hasHandlers()) {
            return;
        }

        $event->call();
    }
}