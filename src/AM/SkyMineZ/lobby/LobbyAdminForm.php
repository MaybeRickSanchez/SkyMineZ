<?php

declare(strict_types=1);

namespace AM\SkyMineZ\lobby;

use AM\SkyMineZ\Main;
use AM\SkyMineZ\config\Messages;
use AM\SkyMineZ\ui\Ui;
use AM\SkyMineZ\useless\Positions;
use pocketmine\player\Player;

/**
 * Lobby management window: set the hub and mid-lobby spots, fence the
 * mid-lobby scoreboard zone from the wand selection, or tear settings down.
 */
final class LobbyAdminForm
{
    public function __construct(
        private Main $plugin
    ) {
    }

    public function send(
        Player $player
    ): bool {
        $manager = $this->plugin->getLobbyManager();

        $lobby = $manager->getLobby();
        $mid = $manager->getMidLobby();
        $zone = $manager->getMidZone();

        $config = $this->plugin->getConfigManager();

        Ui::menu(
            $this->plugin,
            $player,
            'Lobby',
            '§7Hub: §f' . ($lobby === null ? 'not set' : Positions::describe($lobby))
            . "\n§7Mid-lobby: §f" . ($mid === null ? 'not set' : Positions::describe($mid))
            . "\n§7Mid-zone: §f" . ($zone === null ? 'not set' : 'set')
            . "\n§7Map protection: §f" . ($lobby === null
                ? 'not set'
                : ($manager->protectionEnabled() ? 'on' : 'set, disabled in config'))
            . "\n§7Join teleport: §f" . $manager->joinTeleportMode()
            . ' §8(config: lobby.join-teleport)'
            . "\n§7No fall damage: §f" . ($manager->noFallDamage() ? 'on' : 'off')
            . ' §8| §7Void rescue: §f' . ($manager->voidRescue() ? 'on' : 'off')
            . ' §8| §7No hunger: §f' . ($manager->noHunger() ? 'on' : 'off'),
            [
                '§aSet hub here' => function(Player $who): void {
                    $this->plugin->getLobbyManager()->setLobby($who->getLocation());

                    Ui::success($this->plugin, $who, Messages::get($this->plugin, Messages::LOBBY_HUB_SET));
                },
                '§aSet mid-lobby here' => function(Player $who): void {
                    $this->plugin->getLobbyManager()->setMidLobby($who->getLocation());

                    Ui::success($this->plugin, $who, Messages::get($this->plugin, Messages::LOBBY_MID_SET));
                },
                '§eSet mid zone from selection' => function(Player $who): void {
                    $region = $this->plugin->getSelectionManager()->getRegion($who);

                    if ($region === null) {
                        Ui::error(
                            $this->plugin,
                            $who,
                            Messages::get($this->plugin, Messages::COMMON_SELECT_AREA)
                        );

                        return;
                    }

                    $this->plugin->getLobbyManager()->setMidZone($region[0], $region[1]);

                    Ui::success($this->plugin, $who, Messages::get($this->plugin, Messages::LOBBY_MIDZONE_SET));
                },
                '§cClear mid zone' => function(Player $who): void {
                    $this->plugin->getLobbyManager()->clearMidZone();

                    Ui::success($this->plugin, $who, Messages::get($this->plugin, Messages::LOBBY_MIDZONE_REMOVED));
                }
            ]
        );

        return true;
    }
}