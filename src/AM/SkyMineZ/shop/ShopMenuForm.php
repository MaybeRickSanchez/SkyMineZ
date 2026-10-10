<?php

declare(strict_types=1);

namespace AM\SkyMineZ\shop;

use AM\SkyMineZ\Main;
use AM\SkyMineZ\config\Messages;
use AM\SkyMineZ\ui\Ui;
use AM\SkyMineZ\useless\NumberFormatter;
use pocketmine\player\Player;

/**
 * The shop window: categories, then items, then buy/sell confirmation.
 *
 * Prices always show both directions where available, and every destructive
 * step (spending money, giving up items) asks first. Failed trades explain
 * themselves instead of failing silently.
 */
final class ShopMenuForm
{
    public function __construct(
        private Main $plugin
    ) {
    }

    public function send(
        Player $player
    ): bool {
        $categories = $this->plugin->getShopManager()->getCategories();

        if ($categories === []) {
            Ui::error($this->plugin, $player, Messages::get($this->plugin, Messages::SHOP_EMPTY));

            return true;
        }

        $handlers = [];

        foreach ($categories as $category) {
            $handlers['§e' . $category['name']] = function(Player $who) use ($category): void {
                $this->sendCategory($who, $category['id']);
            };
        }

        return Ui::menu(
            $this->plugin,
            $player,
            'Shop',
            $this->balanceLine($player),
            $handlers
        );
    }

    private function sendCategory(
        Player $player,
        string $categoryId
    ): void {
        $category = $this->plugin->getShopManager()->getCategory($categoryId);

        if ($category === null) {
            Ui::error($this->plugin, $player, Messages::get($this->plugin, Messages::SHOP_NO_CATEGORY));

            return;
        }

        $handlers = [];

        foreach ($category['items'] as $index => $entry) {
            $label = '§f' . $entry['count'] . 'x ' . $entry['item']->getName();

            $prices = [];

            if ($entry['buy'] > 0) {
                $prices[] = '§aBuy ' . NumberFormatter::short($entry['buy']);
            }

            if ($entry['sell'] > 0) {
                $prices[] = '§cSell ' . NumberFormatter::short($entry['sell']);
            }

            $handlers[$label . ' §8| §7' . implode(' §8| §7', $prices)] =
                function(Player $who) use ($categoryId, $index): void {
                    $this->sendItem($who, $categoryId, $index);
                };
        }

        $handlers['§7Back'] = function(Player $who): void {
            $this->send($who);
        };

        Ui::menu(
            $this->plugin,
            $player,
            $category['name'],
            $this->balanceLine($player),
            $handlers
        );
    }

    private function sendItem(
        Player $player,
        string $categoryId,
        int $index
    ): void {
        $manager = $this->plugin->getShopManager();

        $entry = $manager->getEntry($categoryId, $index);

        if ($entry === null) {
            Ui::error($this->plugin, $player, Messages::get($this->plugin, Messages::SHOP_NO_OFFER));

            return;
        }

        $handlers = [];
        $itemName = $entry['count'] . 'x ' . $entry['item']->getName();

        if ($entry['buy'] > 0) {
            $handlers['§aBuy for ' . NumberFormatter::short($entry['buy'])] =
                function(Player $who) use ($categoryId, $index, $itemName): void {
                    Ui::confirm(
                        $this->plugin,
                        $who,
                        'Buy ' . $itemName . '?',
                        'This spends your money right away.',
                        function(Player $w) use ($categoryId, $index): void {
                            if (!$this->plugin->getShopManager()->buy($w, $categoryId, $index)) {
                                Ui::error($this->plugin, $w, Messages::get($this->plugin, Messages::SHOP_NO_MONEY));

                                return;
                            }

                            Ui::success($this->plugin, $w, Messages::get($this->plugin, Messages::SHOP_BOUGHT));
                        }
                    );
                };
        }

        if ($entry['sell'] > 0) {
            $handlers['§cSell for ' . NumberFormatter::short($entry['sell'])] =
                function(Player $who) use ($categoryId, $index, $itemName): void {
                    Ui::confirm(
                        $this->plugin,
                        $who,
                        'Sell ' . $itemName . '?',
                        'The items leave your inventory right away.',
                        function(Player $w) use ($categoryId, $index): void {
                            if (!$this->plugin->getShopManager()->sell($w, $categoryId, $index)) {
                                Ui::error($this->plugin, $w, Messages::get($this->plugin, Messages::SHOP_NO_ITEMS));

                                return;
                            }

                            Ui::success($this->plugin, $w, Messages::get($this->plugin, Messages::SHOP_SOLD));
                        }
                    );
                };

            $handlers['§cSell everything'] =
                function(Player $who) use ($categoryId, $index, $itemName): void {
                    Ui::confirm(
                        $this->plugin,
                        $who,
                        'Sell all ' . $itemName . '?',
                        'Every matching item leaves your inventory right away.',
                        function(Player $w) use ($categoryId, $index): void {
                            $earned = $this->plugin->getShopManager()->sellAll($w, $categoryId, $index);

                            if ($earned <= 0) {
                                Ui::error($this->plugin, $w, Messages::get($this->plugin, Messages::SHOP_NOTHING));

                                return;
                            }

                            Ui::success(
                                $this->plugin,
                                $w,
                                Messages::get(
                                    $this->plugin,
                                    Messages::SHOP_SOLD_ALL,
                                    ['amount' => NumberFormatter::short($earned)]
                                )
                            );
                        }
                    );
                };
        }

        $handlers['§7Back'] = function(Player $who) use ($categoryId): void {
            $this->sendCategory($who, $categoryId);
        };

        Ui::menu(
            $this->plugin,
            $player,
            $itemName,
            $this->balanceLine($player),
            $handlers
        );
    }

    private function balanceLine(
        Player $player
    ): string {
        return '§7Balance: §f' . NumberFormatter::short(
            $this->plugin->getMoneyEconomy()->get($player->getName())
        ) . ' money';
    }
}