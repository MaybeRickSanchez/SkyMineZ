<?php

declare(strict_types=1);

namespace AM\SkyMineZ\crate;

use AM\SkyMineZ\command\TargetResolver;
use AM\SkyMineZ\Main;
use AM\SkyMineZ\config\Messages;
use AM\SkyMineZ\useless\Items;
use AM\SkyMineZ\ui\Ui;
use AM\SkyMineZ\useless\NumberFormatter;
use pocketmine\player\Player;

/**
 * Crate management window: inspect a crate, add the held item as a reward,
 * tweak weights, hand out keys, recolor, move and delete.
 *
 * Everything reuses {@link Ui} primitives. Destructive actions confirm first;
 * numeric input is validated before anything is written.
 */
final class CrateAdminForm
{
    public function __construct(
        private Main $plugin
    ) {
    }

    public function send(
        Player $player
    ): bool {
        $crates = $this->plugin->getCrateManager()->getCrates();

        if ($crates === []) {
            Ui::error($this->plugin, $player, Messages::get($this->plugin, Messages::CRATE_NONE));

            return true;
        }

        $handlers = [];

        foreach ($crates as $name => $crate) {
            $handlers['§d' . $name . ' §8(' . count($crate->getRewards()) . ')'] =
                function(Player $who) use ($name): void {
                    $this->sendCrate($who, $name);
                };
        }

        return Ui::menu(
            $this->plugin,
            $player,
            'Crates',
            '§7Pick a crate to manage it.',
            $handlers
        );
    }

    private function sendCrate(
        Player $player,
        string $name
    ): void {
        $crate = $this->plugin->getCrateManager()->getCrate($name);

        if ($crate === null) {
            Ui::error($this->plugin, $player, Messages::get($this->plugin, Messages::CRATE_GONE));

            return;
        }

        Ui::menu(
            $this->plugin,
            $player,
            $name,
            '§7Rewards: §f' . count($crate->getRewards())
            . ' §8| §7Keys: §f' . implode(', ', $crate->getKeys())
            . "\n§7Color: §f" . strtolower($crate->getColor()->name),
            [
                '§aAdd held item as reward' => function(Player $who) use ($name): void {
                    $this->addHeld($who, $name);
                },
                '§eRewards' => function(Player $who) use ($name): void {
                    $this->sendRewards($who, $name);
                },
                '§eGive me a key' => function(Player $who) use ($name): void {
                    $crate = $this->plugin->getCrateManager()->getCrate($name);

                    if ($crate === null) {
                        return;
                    }

                    $keyId = $crate->getKeys()[0] ?? $crate->getName();

                    Items::give(
                        $who,
                        Key::create($keyId, "§d" . $crate->getName() . " Key", 1)
                    );

                    Ui::success($this->plugin, $who, Messages::get($this->plugin, Messages::CRATE_KEY_GIVEN));
                },
                '§dNext color' => function(Player $who) use ($name): void {
                    $crate = $this->plugin->getCrateManager()->getCrate($name);

                    if ($crate === null) {
                        return;
                    }

                    $names = Crate::colorNames();
                    $current = array_search(
                        strtolower($crate->getColor()->name),
                        $names,
                        true
                    );

                    $next = Crate::colorFromName(
                        $names[($current === false ? 0 : $current + 1) % count($names)]
                    ) ?? $crate->getColor();

                    $crate->setColor($next);
                    $this->plugin->getCrateManager()->save($name);

                    Ui::success(
                        $this->plugin,
                        $who,
                        Messages::get(
                            $this->plugin,
                            Messages::CRATE_COLOR_SET,
                            [
                                'name' => $name,
                                'color' => $names[array_search(strtolower($next->name), $names, true)]
                            ]
                        )
                    );

                    $this->sendCrate($who, $name);
                },
                '§eMove here' => function(Player $who) use ($name): void {
                    $crate = $this->plugin->getCrateManager()->getCrate($name);

                    if ($crate === null) {
                        return;
                    }

                    if ($crate->isBusy() || $crate->hasPreviewViewers()) {
                        Ui::error($this->plugin, $who, Messages::get($this->plugin, Messages::CRATE_IN_USE));

                        return;
                    }

                    $position = TargetResolver::playerMiddle($who);

                    $color = $crate->getColor();
                    $keys = $crate->getKeys();
                    $rewards = $crate->getRewards();

                    $this->plugin->getCrateManager()->remove($name);

                    $fresh = $this->plugin->getCrateManager()->create(
                        $name,
                        $position,
                        $position->getWorld()
                    );

                    $fresh->setColor($color);

                    foreach ($keys as $keyId) {
                        $fresh->addKey($keyId);
                    }

                    foreach ($rewards as $reward) {
                        $fresh->addRewardObject(clone $reward);
                    }

                    $this->plugin->getCrateManager()->save($name);

                    Ui::success($this->plugin, $who, Messages::get($this->plugin, Messages::CRATE_MOVED, ['name' => $name]));
                },
                '§cDelete crate' => function(Player $who) use ($name): void {
                    Ui::confirm(
                        $this->plugin,
                        $who,
                        'Delete crate?',
                        "This removes '{$name}', its rewards and its block. Keys in inventories stop working.",
                        function(Player $w) use ($name): void {
                            $this->plugin->getCrateManager()->remove($name);

                            Ui::success($this->plugin, $w, Messages::get($this->plugin, Messages::COMMON_DELETED, ['name' => $name]));
                        }
                    );
                },
                '§7Back' => function(Player $who): void {
                    $this->send($who);
                }
            ]
        );
    }

    private function addHeld(
        Player $player,
        string $name
    ): void {
        $crate = $this->plugin->getCrateManager()->getCrate($name);

        if ($crate === null) {
            return;
        }

        $held = $player->getInventory()->getItemInHand();

        if ($held->isNull()) {
            Ui::error($this->plugin, $player, Messages::get($this->plugin, Messages::CRATE_HOLD_ITEM));

            return;
        }

        Ui::input(
            $this->plugin,
            $player,
            'Reward weight',
            'Weight number (higher = more common)',
            function(Player $who, string $text) use ($name): void {
                $crate = $this->plugin->getCrateManager()->getCrate($name);

                if ($crate === null) {
                    return;
                }

                $weight = is_numeric($text) ? (float) $text : -1.0;

                if ($weight <= 0) {
                    Ui::error($this->plugin, $who, Messages::get($this->plugin, Messages::CRATE_BAD_WEIGHT));

                    return;
                }

                $held = $who->getInventory()->getItemInHand();

                if ($held->isNull()) {
                    Ui::error($this->plugin, $who, Messages::get($this->plugin, Messages::CRATE_HOLD_ITEM));

                    return;
                }

                $held->setCount(1);

                $crate->addReward($held, $weight);
                $this->plugin->getCrateManager()->save($name);

                Ui::success(
                    $this->plugin,
                    $who,
                    Messages::get(
                        $this->plugin,
                        Messages::CRATE_REWARD_ADDED_FULL,
                        ['item' => $held->getName(), 'weight' => $weight]
                    )
                );

                $this->sendRewards($who, $name);
            },
            '10'
        );
    }

    private function sendRewards(
        Player $player,
        string $name
    ): void {
        $crate = $this->plugin->getCrateManager()->getCrate($name);

        if ($crate === null) {
            return;
        }

        $handlers = [];
        $lines = [];

        foreach ($crate->getRewards() as $index => $reward) {
            $item = $reward->getItem();
            $chance = $reward->getChancePercent($crate->getTotalWeight());

            $lines[] = '§f#' . $index . ' §f' . $item->getCount() . 'x ' . $item->getName()
                . ' §8| §7' . $reward->getType()
                . ' §8| §e' . NumberFormatter::trim($chance) . '%';

            $handlers['§cRemove #' . $index . ' ' . $item->getName()] =
                function(Player $who) use ($name, $index): void {
                    $crate = $this->plugin->getCrateManager()->getCrate($name);

                    if ($crate === null) {
                        return;
                    }

                    $crate->removeReward($index);
                    $this->plugin->getCrateManager()->save($name);

                    Ui::success($this->plugin, $who, Messages::get($this->plugin, Messages::CRATE_REWARD_REMOVED));
                    $this->sendRewards($who, $name);
                };
        }

        if ($handlers === []) {
            $lines[] = '§7No rewards yet. Add the held item to start.';
        }

        $handlers['§7Back'] = function(Player $who) use ($name): void {
            $this->sendCrate($who, $name);
        };

        Ui::menu(
            $this->plugin,
            $player,
            $name . ' rewards',
            implode("\n", $lines),
            $handlers
        );
    }
}