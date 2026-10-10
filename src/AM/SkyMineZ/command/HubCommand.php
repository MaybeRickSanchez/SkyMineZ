<?php

declare(strict_types=1);

namespace AM\SkyMineZ\command;

use AM\SkyMineZ\Main;
use AM\SkyMineZ\config\Messages;
use AM\SkyMineZ\useless\Positions;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;

/**
 * /hub (alias /lobby): teleport to the lobby, plus the lobby admin setup.
 *
 * With no arguments anyone may teleport; every subcommand needs the admin
 * permission. A missing lobby is reported plainly instead of failing
 * silently, so players are never left wondering why nothing happened.
 */
final class HubCommand extends BaseCommand
{
    public function __construct(
        Main $plugin
    ) {
        parent::__construct(
            $plugin,
            'hub',
            'Teleport to the lobby and manage it',
            '/hub | /lobby [set|setmid|protect|unprotect|info]',
            ['lobby'],
            Main::PERMISSION_USE
        );
    }

    public function execute(
        CommandSender $sender,
        string $label,
        array $args
    ): bool {
        if (!$this->testPermission($sender)) {
            return true;
        }

        $sub = strtolower($args[0] ?? '');

        if ($sub === '') {
            return $this->handleTeleport($sender);
        }

        return match ($sub) {
            'set' => $this->handleSet($sender),
            'setmid' => $this->handleSetMid($sender),
            'unset', 'clear', 'unsetlobby' => $this->handleUnset($sender),
            'unsetmid', 'clearmid' => $this->handleUnsetMid($sender),
            'protect' => $this->handleProtect($sender),
            'unprotect' => $this->handleUnprotect($sender),
            'info' => $this->handleInfo($sender),
            default => $this->handleTeleport($sender)
        };
    }

    private function handleTeleport(
        CommandSender $sender
    ): bool {
        if (!$sender instanceof Player) {
            $this->error($sender, Messages::get($this->plugin, Messages::HUB_ONLY_PLAYERS));

            return true;
        }

        $lobby = $this->plugin->getLobbyManager()->getHub();

        if ($lobby === null) {
            $this->error(
                $sender,
                Messages::get($this->plugin, Messages::HUB_NO_LOBBY)
            );

            return true;
        }

        $sender->teleport($lobby);
        $this->success($sender, Messages::get($this->plugin, Messages::HUB_TELEPORTED));

        return true;
    }

    private function handleSet(
        CommandSender $sender
    ): bool {
        return $this->handleSetSide($sender, false);
    }

    private function handleSetMid(
        CommandSender $sender
    ): bool {
        return $this->handleSetSide($sender, true);
    }

    /**
     * Records the lobby (or mid-lobby) at the admin's feet. One method for
     * both because the bodies differ only in the setter and message key.
     */
    private function handleSetSide(
        CommandSender $sender,
        bool $mid
    ): bool {
        return $this->runPlayerSubCommand(
            $sender,
            [],
            function(Player $player, array $args) use ($mid): void {
                if (!$player->hasPermission(Main::PERMISSION_ADMIN)) {
                    $this->error($player, Messages::get($this->plugin, Messages::COMMON_NO_PERMISSION));

                    return;
                }

                $location = $player->getLocation();

                if ($mid) {
                    $this->plugin->getLobbyManager()->setMidLobby($location);
                } else {
                    $this->plugin->getLobbyManager()->setLobby($location);
                }

                $this->success(
                    $player,
                    Messages::get(
                        $this->plugin,
                        $mid ? Messages::HUB_SET_MID : Messages::HUB_SET,
                        ['where' => Positions::describe($location)]
                    )
                );
            }
        );
    }

    private function handleProtect(
        CommandSender $sender
    ): bool {
        return $this->runPlayerSubCommand(
            $sender,
            [],
            function(Player $player, array $args): void {
                if (!$player->hasPermission(Main::PERMISSION_ADMIN)) {
                    $this->error($player, Messages::get($this->plugin, Messages::COMMON_NO_PERMISSION));

                    return;
                }

                $region = $this->selectionRegion($player, Messages::COMMON_SELECT_AREA);

                if ($region === null) {
                    return;
                }

                [$pos1, $pos2] = $region;

                $this->plugin->getLobbyManager()->setProtection($pos1, $pos2);

                $this->success($player, Messages::get($this->plugin, Messages::HUB_PROTECT_SET));
            }
        );
    }

    private function handleUnprotect(
        CommandSender $sender
    ): bool {
        return $this->clearLobbySetting(
            $sender,
            fn(): mixed => $this->plugin->getLobbyManager()->clearProtection(),
            Messages::HUB_PROTECT_REMOVED
        );
    }

    private function handleUnset(
        CommandSender $sender
    ): bool {
        return $this->clearLobbySetting(
            $sender,
            fn(): mixed => $this->plugin->getLobbyManager()->clearLobby(),
            Messages::HUB_REMOVED
        );
    }

    private function handleUnsetMid(
        CommandSender $sender
    ): bool {
        return $this->clearLobbySetting(
            $sender,
            fn(): mixed => $this->plugin->getLobbyManager()->clearMidLobby(),
            Messages::HUB_MID_REMOVED
        );
    }

    /**
     * Clears one lobby setting. One method for all three because the bodies
     * differ only in the clearer and the confirmation key.
     *
     * @param callable(): mixed $clear
     */
    private function clearLobbySetting(
        CommandSender $sender,
        callable $clear,
        string $doneKey
    ): bool {
        if (!$sender->hasPermission(Main::PERMISSION_ADMIN)) {
            $this->error($sender, Messages::get($this->plugin, Messages::COMMON_NO_PERMISSION));

            return true;
        }

        $clear();
        $this->success($sender, Messages::get($this->plugin, $doneKey));

        return true;
    }

    private function handleInfo(
        CommandSender $sender
    ): bool {
        $manager = $this->plugin->getLobbyManager();

        $lobby = $manager->getLobby();
        $mid = $manager->getMidLobby();

        $sender->sendMessage(
            $this->prefixed(
                Messages::get(
                    $this->plugin,
                    Messages::HUB_INFO_LOBBY,
                    ['where' => $lobby === null ? 'not set' : Positions::describe($lobby)]
                )
            )
        );
        $sender->sendMessage(
            $this->prefixed(
                Messages::get(
                    $this->plugin,
                    Messages::HUB_INFO_MID,
                    ['where' => $mid === null ? 'not set' : Positions::describe($mid)]
                )
            )
        );
        $sender->sendMessage(
            $this->prefixed(
                Messages::get(
                    $this->plugin,
                    Messages::HUB_INFO_PROTECTION,
                    ['state' => $manager->hasProtection()
                        ? ($manager->protectionEnabled() ? 'on' : 'set, disabled in config')
                        : 'not set']
                )
            )
        );

        return true;
    }
}