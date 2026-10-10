<?php

declare(strict_types=1);

namespace AM\SkyMineZ\composer;

use AM\SkyMineZ\Main;
use AM\SkyMineZ\config\Messages;
use AM\SkyMineZ\useless\Items;
use AM\SkyMineZ\useless\VirtualInventory;
use pocketmine\inventory\Inventory;
use pocketmine\item\Item;
use pocketmine\math\Vector3;
use pocketmine\player\Player;

/**
 * The Composer: an interactive material processor.
 *
 * Each player gets their own 27-slot virtual workbench (`/composer` opens it
 * after picking a recipe). They put materials in, close the window, and the
 * recipe is validated at close time: on success the exact inputs are consumed
 * and the result is handed over; on any failure everything is handed straight
 * back. Nothing is ever voided on any path — close, quit, death, timeout —
 * because every exit funnels through {@link settle()}, which always moves each
 * leftover somewhere the player can reach.
 *
 * Recipes come from config.yml (`composer.recipes`) as multiset matches:
 * order and stacking do not matter, extra items are simply returned.
 */
final class ComposerManager
{
    /**
     * Open workbenches: spl_object_id => owner + recipe + where to drop
     * leftovers if the owner vanishes mid-craft.
     *
     * @var array<int, array{player: string, recipe: string, world: string, x: float, y: float, z: float}>
     */
    private array $sessions = [];

    /** @var array<int, VirtualInventory> */
    private array $inventories = [];

    public function __construct(
        private Main $main
    ) {
    }

    /**
     * Validated recipe table from config.
     *
     * @return array<string, array{id: string, name: string, inputs: list<array{item: Item, count: int}>, output: array{item: Item, count: int}}>
     */
    public function getRecipes(): array
    {
        $result = [];

        $raw = $this->main->getConfigManager()->get('composer.recipes', []);

        if (!is_array($raw)) {
            return [];
        }
        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $id = isset($entry['id']) && is_string($entry['id']) ? trim($entry['id']) : '';

            if ($id === '' || isset($result[$id])) {
                continue;
            }

            $inputs = $this->readStackList((array) ($entry['inputs'] ?? []));

            $outputRaw = $entry['output'] ?? null;
            $output = null;

            if (is_array($outputRaw) && isset($outputRaw['item']) && is_string($outputRaw['item'])) {
                $item = Items::parse($outputRaw['item']);

                if ($item !== null) {
                    $item->setCount(max(
                        1,
                        min(
                            $item->getMaxStackSize(),
                            isset($outputRaw['count']) && is_numeric($outputRaw['count'])
                                ? (int) $outputRaw['count']
                                : 1
                        )
                    ));

                    $output = ['item' => $item, 'count' => $item->getCount()];
                }
            }

            if ($inputs === [] || $output === null) {
                $this->main->getLogger()->warning(
                    "Composer: recipe '{$id}' has no usable inputs or output, skipped."
                );

                continue;
            }

            $result[$id] = [
                'id' => $id,
                'name' => isset($entry['name']) && is_string($entry['name']) && $entry['name'] !== ''
                    ? $entry['name']
                    : $id,
                'inputs' => $inputs,
                'output' => $output
            ];
        }

        return $result;
    }

    /**
     * Creates an empty recipe (no inputs, no output yet). The admin fills it
     * through the management UI. Returns false for blank/duplicate ids.
     */
    public function addRecipe(
        string $id,
        string $name
    ): bool {
        $id = trim($id);

        if ($id === '' || !preg_match('/^[A-Za-z0-9_-]{1,32}$/', $id)) {
            return false;
        }

        $recipes = $this->getRecipes();

        if (isset($recipes[$id])) {
            return false;
        }

        $recipes[$id] = [
            'id' => $id,
            'name' => trim($name) !== '' ? trim($name) : $id,
            'inputs' => [],
            'output' => null
        ];

        $this->writeRecipes($recipes);

        return true;
    }

    public function removeRecipe(
        string $id
    ): bool {
        $recipes = $this->getRecipes();

        if (!isset($recipes[$id])) {
            return false;
        }

        unset($recipes[$id]);

        $this->writeRecipes($recipes);

        return true;
    }

    public function renameRecipe(
        string $id,
        string $name
    ): bool {
        $recipes = $this->getRecipes();

        if (!isset($recipes[$id]) || trim($name) === '') {
            return false;
        }

        $recipes[$id]['name'] = trim($name);

        $this->writeRecipes($recipes);

        return true;
    }

    /**
     * Appends the held item as an input stack. Only items that survive a
     * name round-trip are accepted, so the recipe stays valid after a
     * restart (enchanted/renamed one-offs are refused instead of rotting).
     */
    public function addInput(
        string $id,
        Item $sample,
        int $count
    ): bool {
        $recipes = $this->getRecipes();

        if (!isset($recipes[$id]) || $count < 1) {
            return false;
        }

        $name = self::itemName($sample);

        if ($name === null) {
            return false;
        }

        $recipes[$id]['inputs'][] = ['item' => $sample, 'count' => $count];

        $this->writeRecipes($recipes);

        return true;
    }

    public function removeInput(
        string $id,
        int $index
    ): bool {
        $recipes = $this->getRecipes();

        if (!isset($recipes[$id]['inputs'][$index])) {
            return false;
        }

        array_splice($recipes[$id]['inputs'], $index, 1);

        $this->writeRecipes($recipes);

        return true;
    }

    public function setOutput(
        string $id,
        Item $sample,
        int $count
    ): bool {
        $recipes = $this->getRecipes();

        if (!isset($recipes[$id]) || $count < 1) {
            return false;
        }

        if (self::itemName($sample) === null) {
            return false;
        }

        $recipes[$id]['output'] = ['item' => $sample, 'count' => $count];

        $this->writeRecipes($recipes);

        return true;
    }

    /**
     * A /give-style name that parses back to an item stackable with $item, or
     * null when the item cannot be expressed that way (custom NBT, renamed or
     * enchanted pieces). Round-trip verification is what keeps config recipes
     * restart-safe.
     */
    public static function itemName(
        Item $item
    ): ?string {
        if ($item->isNull()) {
            return null;
        }

        $candidates = [
            strtolower(str_replace(' ', '_', $item->getName()))
        ];

        foreach ($candidates as $candidate) {
            if ($candidate === '') {
                continue;
            }

            $parsed = Items::parse($candidate);

            if ($parsed !== null && $parsed->canStackWith($item)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param array<string, array{id: string, name: string, inputs: list<array{item: Item, count: int}>, output: array{item: Item, count: int}|null}> $recipes
     */
    private function writeRecipes(
        array $recipes
    ): void {
        $list = [];

        foreach ($recipes as $recipe) {
            $inputs = [];

            foreach ($recipe['inputs'] as $input) {
                $name = self::itemName($input['item']);

                if ($name === null) {
                    continue;
                }

                $inputs[] = ['item' => $name, 'count' => max(1, $input['count'])];
            }

            $output = null;

            if (is_array($recipe['output'] ?? null)) {
                $name = self::itemName($recipe['output']['item']);

                if ($name !== null) {
                    $output = [
                        'item' => $name,
                        'count' => max(1, $recipe['output']['count'])
                    ];
                }
            }

            $entry = ['id' => $recipe['id'], 'name' => $recipe['name'], 'inputs' => $inputs];

            if ($output !== null) {
                $entry['output'] = $output;
            }

            $list[] = $entry;
        }

        $config = $this->main->getConfigManager();
        $config->set('composer.recipes', $list);
        $config->save();
    }

    /**
     * Opens a fresh workbench for a recipe. One bench per player: opening
     * again settles (returns) the previous one first, so items can never be
     * stranded across two windows.
     */
    public function open(
        Player $player,
        string $recipeId
    ): bool {
        $recipes = $this->getRecipes();

        if (!isset($recipes[$recipeId])) {
            return false;
        }

        $this->settlePlayer($player);

        $inventory = new VirtualInventory($player->getPosition(), 27);

        $id = spl_object_id($inventory);

        $position = $player->getPosition();

        $this->inventories[$id] = $inventory;
        $this->sessions[$id] = [
            'player' => strtolower($player->getName()),
            'recipe' => $recipeId,
            'world' => $player->getWorld()->getFolderName(),
            'x' => $position->x,
            'y' => $position->y,
            'z' => $position->z
        ];

        try {
            $opened = $this->main->getVirtualWindow()->open($player, $inventory, $recipes[$recipeId]['name'] ?? 'Composer');
        } catch (\Error) {
            $opened = $player->setCurrentWindow($inventory);
        }

        if (!$opened) {
            unset($this->inventories[$id], $this->sessions[$id]);

            return false;
        }

        return true;
    }

    /**
     * Settles a closed workbench: validate the recipe against what is inside,
     * consume exact inputs and hand over the result on success, return
     * everything on failure. Idempotent — closing twice settles once.
     */
    public function settle(
        Inventory $inventory
    ): void {
        $id = spl_object_id($inventory);
        $session = $this->sessions[$id] ?? null;

        unset($this->sessions[$id], $this->inventories[$id]);

        if ($session === null) {
            return;
        }

        $player = $this->main->getServer()->getPlayerExact($session['player']);
        $recipe = $this->getRecipes()[$session['recipe']] ?? null;

        if ($player === null || !$player->isConnected()) {
            $this->dropAll($session, $inventory);

            return;
        }

        if ($recipe === null) {
            $this->returnAll($player, $inventory);

            return;
        }

        if (!$this->matches($inventory, $recipe['inputs'])) {
            $player->sendMessage(
                $this->main->getConfigManager()->getPrefix()
                . Messages::get(
                    $this->main,
                    Messages::COMPOSER_MISMATCH,
                    ['name' => $recipe['name']]
                )
            );

            $this->returnAll($player, $inventory);

            return;
        }

        foreach ($recipe['inputs'] as $input) {
            Items::take($inventory, $input['item'], $input['count']);
        }

        Items::give($player, clone $recipe['output']['item']);

        $player->sendMessage(
            $this->main->getConfigManager()->getPrefix()
            . Messages::get(
                $this->main,
                Messages::COMPOSER_DONE,
                [
                    'count' => $recipe['output']['count'],
                    'item' => $recipe['output']['item']->getName()
                ]
            )
        );

        $this->returnAll($player, $inventory);
    }

    /**
     * Settles (and closes) any bench the player currently has open, e.g.
     * before opening a new one. Safe to call when they have none.
     */
    public function settlePlayer(
        Player $player
    ): void {
        $current = $player->getCurrentWindow();

        if ($current === null) {
            return;
        }

        $id = spl_object_id($current);

        if (!isset($this->sessions[$id])) {
            return;
        }

        $player->removeCurrentWindow();

        $this->settle($current);
    }

    /**
     * @param list<array{item: Item, count: int}> $inputs
     */
    public function matches(
        Inventory $inventory,
        array $inputs
    ): bool {
        foreach ($inputs as $input) {
            if (Items::countOf($inventory, $input['item']) < $input['count']) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<mixed> $raw
     *
     * @return list<array{item: Item, count: int}>
     */
    private function readStackList(
        array $raw
    ): array {
        $result = [];

        foreach ($raw as $entry) {
            if (!is_array($entry) || !isset($entry['item']) || !is_string($entry['item'])) {
                continue;
            }

            $item = Items::parse($entry['item']);

            if ($item === null) {
                continue;
            }

            $count = isset($entry['count']) && is_numeric($entry['count'])
                ? max(1, (int) $entry['count'])
                : 1;

            $item->setCount(1);

            $result[] = ['item' => $item, 'count' => $count];
        }

        return $result;
    }

    private function returnAll(
        Player $player,
        Inventory $inventory
    ): void {
        $items = Items::drain($inventory);

        if ($items !== []) {
            Items::give($player, ...$items);
        }
    }

    /**
     * The owner is gone: drop everything where they stood so nothing is lost.
     */
    private function dropAll(
        array $session,
        Inventory $inventory
    ): void {
        $player = $this->main->getServer()->getPlayerExact($session['player']);

        if ($player !== null && $player->isConnected()) {
            $this->returnAll($player, $inventory);

            return;
        }

        $items = Items::drain($inventory);

        if ($items === []) {
            return;
        }

        $worldManager = $this->main->getServer()->getWorldManager();
        $world = $worldManager->getWorldByName($session['world']) ?? $worldManager->getDefaultWorld();

        if ($world === null) {
            return;
        }

        $spot = new Vector3($session['x'], $session['y'], $session['z']);

        foreach ($items as $item) {
            $world->dropItem($spot, $item, new Vector3(0, 0, 0));
        }
    }
}