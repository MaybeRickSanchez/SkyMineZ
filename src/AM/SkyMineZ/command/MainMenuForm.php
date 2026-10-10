<?php

declare(strict_types=1);

namespace AM\SkyMineZ\command;

use AM\SkyMineZ\composer\ComposerMenuForm;
use AM\SkyMineZ\crate\CrateAdminForm;
use AM\SkyMineZ\leaderboard\LeaderboardAdminForm;
use AM\SkyMineZ\lobby\LobbyAdminForm;
use AM\SkyMineZ\Main;
use AM\SkyMineZ\config\Messages;
use AM\SkyMineZ\mine\MineAdminForm;
use AM\SkyMineZ\outpost\OutpostAdminForm;
use AM\SkyMineZ\quest\QuestMenuForm;
use AM\SkyMineZ\shop\ShopMenuForm;
use AM\SkyMineZ\slapper\SlapperAdminForm;
use AM\SkyMineZ\team\TeamMenuForm;
use AM\SkyMineZ\tools\ToolUpgradeForm;
use AM\SkyMineZ\ui\Ui;
use AM\SkyMineZ\useless\NumberFormatter;
use pocketmine\player\Player;

/**
 * The `/skymine menu` window: the server hub.
 *
 * Player entries open the feature windows (warps, shop, quests, teams,
 * composer, gear); admin entries open each system's management window. Every
 * entry shows live state, and toggling anything reopens the menu so the new
 * state is visible straight away.
 */
final class MainMenuForm
{
    public function __construct(
        private Main $plugin
    ) {
    }

    public function send(
        Player $player
    ): bool {
        $pvpEnabled = $this->plugin->getPvpManager()->getState(
            $player->getName()
        );

        $sidebarEnabled = $this->plugin->getScoreHud()->isEnabledFor(
            $player
        );

        $isAdmin = $player->hasPermission(
            Main::PERMISSION_ADMIN
        );

        $handlers = [
            ($pvpEnabled ? '§aPvP: ON' : '§cPvP: OFF') => function(Player $who): void {
                $state = $this->plugin->getPvpManager()->toggle($who->getName());

                Ui::success(
                    $this->plugin,
                    $who,
                    Messages::get(
                        $this->plugin,
                        $state ? Messages::MENU_PVP_ON : Messages::MENU_PVP_OFF
                    )
                );

                $this->send($who);
            },
            ($sidebarEnabled ? '§aSidebar: ON' : '§cSidebar: OFF') => function(Player $who): void {
                $enabled = $this->plugin->getScoreHud()->toggle($who);

                Ui::success(
                    $this->plugin,
                    $who,
                    Messages::get(
                        $this->plugin,
                        $enabled ? Messages::MENU_SIDEBAR_ON : Messages::MENU_SIDEBAR_OFF
                    )
                );

                $this->send($who);
            },
            '§eMy stats' => function(Player $who): void {
                $this->sendStats($who);
            },
            '§eWarps' => function(Player $who): void {
                $this->sendWarps($who);
            },
            '§eShop' => function(Player $who): void {
                (new ShopMenuForm($this->plugin))->send($who);
            },
            '§eDaily quests' => function(Player $who): void {
                (new QuestMenuForm($this->plugin))->send($who);
            },
            '§eTeams' => function(Player $who): void {
                (new TeamMenuForm($this->plugin))->send($who);
            },
            '§eComposer' => function(Player $who): void {
                (new ComposerMenuForm($this->plugin))->send($who);
            },
            '§eGear upgrade' => function(Player $who): void {
                (new ToolUpgradeForm($this->plugin))->send($who);
            },
            '§eHub' => function(Player $who): void {
                $lobby = $this->plugin->getLobbyManager()->getHub();

                if ($lobby === null) {
                    Ui::error($this->plugin, $who, Messages::get($this->plugin, Messages::MENU_NO_LOBBY));

                    return;
                }

                $who->teleport($lobby);

                Ui::success($this->plugin, $who, Messages::get($this->plugin, Messages::MENU_HUB));
            }
        ];

        if ($isAdmin) {
            $handlers['§6Manage...'] = function(Player $who): void {
                $this->sendManage($who);
            };
        }

        return Ui::menu(
            $this->plugin,
            $player,
            'Menu',
            $this->describe($player, $pvpEnabled),
            $handlers
        );
    }

    private function sendStats(
        Player $player
    ): void {
        $miner = $this->plugin->getMinerManager()->getOrLoad(
            $player->getName()
        );

        Ui::menu(
            $this->plugin,
            $player,
            'My stats',
            '§7Mined: §f' . NumberFormatter::short($miner->getMined())
            . "\n§7Deaths: §f" . NumberFormatter::short($miner->getDeaths())
            . "\n§7Kills: §f" . NumberFormatter::short($miner->getKills())
            . ' §8| §7Kill streak: §f' . $miner->getKillStreak()
            . "\n§7Money: §f" . NumberFormatter::short(
                $this->plugin->getMoneyEconomy()->get($player->getName())
            )
            . ' §8| §7Gold: §f' . NumberFormatter::short(
                $this->plugin->getGoldEconomy()->get($player->getName())
            ),
            [
                '§7Back' => function(Player $who): void {
                    $this->send($who);
                }
            ]
        );
    }

    private function sendWarps(
        Player $player
    ): void {
        $warps = $this->plugin->getWarpManager()->getAll();

        if ($warps === []) {
            Ui::error($this->plugin, $player, Messages::get($this->plugin, Messages::MENU_NO_WARPS));

            return;
        }

        $handlers = [];

        foreach ($warps as $name => $_) {
            $handlers['§e' . $name] = function(Player $who) use ($name): void {
                if (!$this->plugin->getWarpManager()->teleport($who, $name)) {
                    Ui::error($this->plugin, $who, Messages::get($this->plugin, Messages::MENU_WARP_GONE));

                    return;
                }

                Ui::success($this->plugin, $who, Messages::get($this->plugin, Messages::MENU_WARPED, ['name' => $name]));
            };
        }

        $handlers['§7Back'] = function(Player $who): void {
            $this->send($who);
        };

        Ui::menu(
            $this->plugin,
            $player,
            'Warps',
            '§7Where to?',
            $handlers
        );
    }

    private function sendManage(
        Player $player
    ): void {
        if (!$player->hasPermission(Main::PERMISSION_ADMIN)) {
            Ui::error($this->plugin, $player, Messages::get($this->plugin, Messages::COMMON_NO_PERMISSION));

            return;
        }

        Ui::menu(
            $this->plugin,
            $player,
            'Manage',
            '§7Pick a system to manage it.',
            [
                '§6Mines' => function(Player $who): void {
                    (new MineAdminForm($this->plugin))->send($who);
                },
                '§6Crates' => function(Player $who): void {
                    (new CrateAdminForm($this->plugin))->send($who);
                },
                '§6Outposts' => function(Player $who): void {
                    (new OutpostAdminForm($this->plugin))->send($who);
                },
                '§6Slappers' => function(Player $who): void {
                    (new SlapperAdminForm($this->plugin))->send($who);
                },
                '§6Leaderboards' => function(Player $who): void {
                    (new LeaderboardAdminForm($this->plugin))->send($who);
                },
                '§6Lobby' => function(Player $who): void {
                    (new LobbyAdminForm($this->plugin))->send($who);
                },
                '§6Warps' => function(Player $who): void {
                    $this->plugin->getServer()->getCommandMap()->dispatch($who, 'warp');
                },
                '§6Teams' => function(Player $who): void {
                    (new \AM\SkyMineZ\team\TeamMenuForm($this->plugin))->send($who);
                },
                '§6Labels' => function(Player $who): void {
                    $this->plugin->getServer()->getCommandMap()->dispatch($who, 'label');
                },
                '§6Composer' => function(Player $who): void {
                    (new \AM\SkyMineZ\composer\ComposerAdminForm($this->plugin))->send($who);
                },
                '§6Reload plugin' => function(Player $who): void {
                    try {
                        $this->plugin->reload();
                    } catch (\Throwable $exception) {
                        Ui::error($this->plugin, $who, Messages::get($this->plugin, Messages::MENU_RELOAD_FAIL, ['error' => $exception->getMessage()]));

                        $this->plugin->getLogger()->logException($exception);

                        return;
                    }

                    Ui::success($this->plugin, $who, Messages::get($this->plugin, Messages::MENU_RELOADED));
                },
                '§6Save everything' => function(Player $who): void {
                    try {
                        $this->plugin->saveAllData();
                    } catch (\Throwable $exception) {
                        Ui::error($this->plugin, $who, Messages::get($this->plugin, Messages::MENU_SAVE_FAIL, ['error' => $exception->getMessage()]));

                        $this->plugin->getLogger()->logException($exception);

                        return;
                    }

                    Ui::success($this->plugin, $who, Messages::get($this->plugin, Messages::MENU_SAVED));
                },
                '§7Back' => function(Player $who): void {
                    $this->send($who);
                }
            ]
        );
    }

    private function describe(
        Player $player,
        bool $pvpEnabled
    ): string {
        return '§7Welcome, §f' . $player->getName() . '§7.'
            . "\n§7PvP: §f" . ($pvpEnabled ? 'ON' : 'OFF')
            . ' §8| §7Server: §f' . \AM\SkyMineZ\scorehud\ServerAddress::of($this->plugin);
    }
}