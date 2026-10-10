<?php

declare(strict_types=1);

namespace AM\SkyMineZ\useless;

use pocketmine\inventory\Inventory;
use pocketmine\item\Item;
use pocketmine\item\StringToItemParser;
use pocketmine\math\Vector3;
use pocketmine\player\Player;

/**
 * Shared item helpers: parsing admin-typed item names, stacking checks and
 * safe giving (inventory first, feet when full, never voided).
 */
final class Items
{
    private function __construct()
    {
    }

    /**
     * Parses `/give` style syntax, with or without metadata.
     */
    public static function parse(
        string $spec
    ): ?Item {
        try {
            $item = StringToItemParser::getInstance()->parse($spec);
        } catch (\Throwable) {
            return null;
        }

        if ($item === null || $item->isNull()) {
            return null;
        }

        return $item;
    }

    /**
     * Total amount of items stackable with $sample across an inventory.
     */
    public static function countOf(
        Inventory $inventory,
        Item $sample
    ): int {
        $total = 0;

        foreach ($inventory->getContents() as $slot) {
            if ($slot->canStackWith($sample)) {
                $total += $slot->getCount();
            }
        }

        return $total;
    }

    /**
     * Removes up to $amount items stackable with $sample. Returns how many
     * were actually removed.
     */
    public static function take(
        Inventory $inventory,
        Item $sample,
        int $amount
    ): int {
        $removed = 0;

        foreach ($inventory->getContents() as $slot => $stack) {
            if ($removed >= $amount) {
                break;
            }

            if (!$stack->canStackWith($sample)) {
                continue;
            }

            $take = min($stack->getCount(), $amount - $removed);

            $cut = clone $stack;
            $cut->setCount($take);

            foreach ($inventory->removeItem($cut) as $leftover) {
                $take -= $leftover->getCount();
            }

            $removed += $take;
        }

        return $removed;
    }

    /**
     * Gives items to a player: inventory first, leftovers dropped at their
     * feet so a full inventory never eats anything.
     */
    public static function give(
        Player $player,
        Item ...$items
    ): void {
        foreach ($player->getInventory()->addItem(...$items) as $leftover) {
            $player->getWorld()->dropItem(
                $player->getPosition(),
                $leftover,
                new Vector3(0, 0, 0)
            );
        }
    }

    /**
     * Takes every stack out of an inventory, empties it, and returns clones.
     * One canonical drain shared by every settle/return path so leftovers can
     * never be stranded by two slightly different loops.
     *
     * @return list<Item>
     */
    public static function drain(
        Inventory $inventory
    ): array {
        $items = [];

        foreach ($inventory->getContents() as $slot) {
            if (!$slot->isNull()) {
                $items[] = clone $slot;
            }
        }

        $inventory->clearAll();

        return $items;
    }
}