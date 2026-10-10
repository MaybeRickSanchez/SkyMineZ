<?php

declare(strict_types=1);

namespace AM\SkyMineZ\shop;

use AM\SkyMineZ\economy\EconomyChangeEventReason;
use AM\SkyMineZ\Main;
use AM\SkyMineZ\useless\Items;
use pocketmine\item\Item;
use pocketmine\player\Player;

/**
 * Category shop backed by config.yml (`shop.categories`).
 *
 * Money flows only through the economy managers, and items only through the
 * player's inventory plus {@link Items} helpers, so there is no second economy
 * implementation hiding in here. Every trade validates first and mutates
 * second: funds are taken before items are given (leftovers drop at the
 * player's feet, never voided), and items are taken before money is granted.
 * Nothing here can duplicate or lose anything.
 */
final class ShopManager
{
    /** @var list<array{id: string, name: string, items: list<array{item: Item, count: int, buy: int, sell: int}>}>|null */
    private ?array $categories = null;

    public function __construct(
        private Main $main
    ) {
    }

    public function reloadDefinitions(): void
    {
        $this->categories = null;
    }

    /**
     * Validated shop catalogue. Unknown item names are skipped with a warning
     * instead of breaking the whole shop.
     *
     * @return list<array{id: string, name: string, items: list<array{item: Item, count: int, buy: int, sell: int}>}>
     */
    public function getCategories(): array
    {
        if ($this->categories !== null) {
            return $this->categories;
        }

        $result = [];

        $raw = $this->main->getConfigManager()->get('shop.categories', []);

        if (!is_array($raw)) {
            $this->categories = [];

            return [];
        }

        foreach ($raw as $category) {
            if (!is_array($category)) {
                continue;
            }

            $id = isset($category['id']) && is_string($category['id']) ? trim($category['id']) : '';

            if ($id === '') {
                continue;
            }

            $items = [];

            foreach ((array) ($category['items'] ?? []) as $entry) {
                if (!is_array($entry) || !isset($entry['item']) || !is_string($entry['item'])) {
                    continue;
                }

                $item = Items::parse($entry['item']);

                if ($item === null) {
                    $this->main->getLogger()->warning(
                        "Shop: unknown item '{$entry['item']}' in category '{$id}', skipped."
                    );

                    continue;
                }

                $count = isset($entry['count']) && is_numeric($entry['count'])
                    ? max(1, min($item->getMaxStackSize(), (int) $entry['count']))
                    : 1;

                $buy = isset($entry['buy']) && is_numeric($entry['buy'])
                    ? max(0, (int) $entry['buy'])
                    : 0;

                $sell = isset($entry['sell']) && is_numeric($entry['sell'])
                    ? max(0, (int) $entry['sell'])
                    : 0;

                if ($buy <= 0 && $sell <= 0) {
                    continue;
                }

                $item->setCount($count);

                $items[] = [
                    'item' => $item,
                    'count' => $count,
                    'buy' => $buy,
                    'sell' => $sell
                ];
            }

            if ($items === []) {
                continue;
            }

            $result[] = [
                'id' => $id,
                'name' => isset($category['name']) && is_string($category['name']) && $category['name'] !== ''
                    ? $category['name']
                    : $id,
                'items' => $items
            ];
        }

        $this->categories = $result;

        return $result;
    }

    /**
     * Buys one listing for a player. Money is taken first; the items always
     * arrive (inventory or feet), so a failed give can never strand paid money.
     */
    public function buy(
        Player $player,
        string $categoryId,
        int $index
    ): bool {
        $entry = $this->entry($categoryId, $index);

        if ($entry === null || $entry['buy'] <= 0) {
            return false;
        }

        $economy = $this->main->getMoneyEconomy();
        $name = $player->getName();

        if (!$economy->has($name, $entry['buy'])) {
            return false;
        }

        $economy->reduce($name, $entry['buy'], EconomyChangeEventReason::COMMAND);

        Items::give($player, clone $entry['item']);

        return true;
    }

    /**
     * Sells one listing worth of matching items. Items leave the inventory
     * first; money is granted after, and only for what was actually taken.
     */
    public function sell(
        Player $player,
        string $categoryId,
        int $index
    ): bool {
        return $this->sellUnits($player, $categoryId, $index, 1) > 0;
    }

    /**
     * Sells everything the player carries that matches a listing.
     *
     * @return int money earned
     */
    public function sellAll(
        Player $player,
        string $categoryId,
        int $index
    ): int {
        return $this->sellUnits($player, $categoryId, $index, PHP_INT_MAX);
    }

    /**
     * @return int money earned
     */
    private function sellUnits(
        Player $player,
        string $categoryId,
        int $index,
        int $maxUnits
    ): int {
        $entry = $this->entry($categoryId, $index);

        if ($entry === null || $entry['sell'] <= 0 || $maxUnits <= 0) {
            return 0;
        }

        $inventory = $player->getInventory();

        $units = intdiv(
            Items::countOf($inventory, $entry['item']),
            $entry['count']
        );

        $units = min($units, $maxUnits);

        if ($units <= 0) {
            return 0;
        }

        $taken = Items::take($inventory, $entry['item'], $units * $entry['count']);

        $soldUnits = intdiv($taken, $entry['count']);

        if ($soldUnits <= 0) {
            return 0;
        }

        $earned = $soldUnits * $entry['sell'];

        $this->main->getMoneyEconomy()->add(
            $player->getName(),
            $earned,
            EconomyChangeEventReason::COMMAND
        );

        return $earned;
    }

    /**
     * @return array{id: string, name: string, items: list<array{item: Item, count: int, buy: int, sell: int}>}|null
     */
    public function getCategory(
        string $categoryId
    ): ?array {
        foreach ($this->getCategories() as $category) {
            if ($category['id'] === $categoryId) {
                return $category;
            }
        }

        return null;
    }

    /**
     * @return array{item: Item, count: int, buy: int, sell: int}|null
     */
    public function getEntry(
        string $categoryId,
        int $index
    ): ?array {
        return $this->getCategory($categoryId)['items'][$index] ?? null;
    }

    /**
     * @return array{item: Item, count: int, buy: int, sell: int}|null
     */
    private function entry(
        string $categoryId,
        int $index
    ): ?array {
        return $this->getEntry($categoryId, $index);
    }
}