<?php

declare(strict_types=1);

namespace AM\SkyMineZ\command;

use AM\SkyMineZ\economy\BaseEconomy;
use AM\SkyMineZ\economy\EconomyChangeEventReason;
use AM\SkyMineZ\lagmaker\LagMaker;
use AM\SkyMineZ\Main;
use AM\SkyMineZ\config\Messages;
use AM\SkyMineZ\wand\PositionWand;
use AM\SkyMineZ\useless\NumberFormatter;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;

/**
 * /skymine - the umbrella command: player settings, the admin utilities and the
 * main menu form.
 *
 * Everything a player needs (PvP toggle, sidebar toggle, stats) is reachable
 * without a permission, because it only ever affects their own session.
 */
final class SkyMineCommand extends BaseCommand
{
    public function __construct(
        Main $plugin
    ) {
        parent::__construct(
            $plugin,
            'skymine',
            'SkyMineZ main command',
            '/skymine <menu|pvp|hud|stats|money|gold|pos1|pos2|wand|reload|lagmaker|save> ...',
            ['skyminesz', 'smz'],
            Main::PERMISSION_USE
        );
    }

    /**
     * @param list<string> $args
     */
    public function execute(
        CommandSender $sender,
        string $label,
        array $args
    ): bool {
        $sub = strtolower(
            $args[0] ?? 'menu'
        );

        /*
         * Player-facing subcommands work without any permission; the admin ones
         * check their own.
         */
        return match ($sub) {
            'menu' => $this->handleMenu($sender),
            'pvp' => $this->handlePvp($sender, $args),
            'hud', 'sidebar' => $this->handleHud($sender),
            'stats' => $this->handleStats($sender, $args),
            'money' => $this->handleEconomy(
                $sender,
                $args,
                $this->plugin->getMoneyEconomy()
            ),
            'gold' => $this->handleEconomy(
                $sender,
                $args,
                $this->plugin->getGoldEconomy()
            ),
            'pos1' => $this->handlePosition($sender, $args, 'pos1'),
            'pos2' => $this->handlePosition($sender, $args, 'pos2'),
            'reset' => $this->handleResetSelection($sender),
            'wand' => $this->handleWand($sender),
            'reload' => $this->handleReload($sender),
            'lagmaker' => $this->handleLagMaker($sender, $args),
            'save' => $this->handleSave($sender),
            default => $this->handleHelp($sender)
        };
    }

    private function handleMenu(
        CommandSender $sender
    ): bool {
        if (!$sender instanceof Player) {
            $this->error(
                $sender,
                Messages::get($this->plugin, Messages::SKYMINE_MENU_ONLY)
            );

            return true;
        }

        (new MainMenuForm(
            $this->plugin
        ))->send($sender);

        return true;
    }

    /**
     * @param list<string> $args
     */
    private function handlePvp(
        CommandSender $sender,
        array $args
    ): bool {
        $raw1 = strtolower($args[1] ?? 'toggle');
        $raw2 = strtolower($args[2] ?? '');

        $keywords = ['on', 'off', 'toggle', 'true', 'false'];

        // Forms: /skymine pvp [on|off], /skymine pvp <player>, /skymine pvp <player> <on|off|toggle>.
        if (in_array($raw1, $keywords, true)) {
            $target = ($sender instanceof Player) ? $sender : null;
            $requested = $raw1;
        } elseif (($playerArg = $this->plugin->getServer()->getPlayerExact($args[1])) !== null) {
            $target = $playerArg;
            $requested = in_array($raw2, $keywords, true) ? $raw2 : 'toggle';
        } elseif ($sender instanceof Player) {
            // Unknown first arg on the non-admin path: treat as toggle-self
            // rather than "player not online" spam. (Reaching here means
            // $raw1 matched no keyword and no online player.)
            $target = $sender;
            $requested = 'toggle';
        } else {
            $this->error($sender, Messages::get($this->plugin, Messages::COMMON_PLAYER_OFFLINE));

            return true;
        }

        if ($target === null) {
            $this->error(
                $sender,
                Messages::get($this->plugin, Messages::COMMON_PLAYER_OFFLINE)
            );

            return true;
        }

        $manager = $this->plugin->getPvpManager();

        if (!$sender->hasPermission(Main::PERMISSION_ADMIN)) {
            // Normal players may only change their own flag, never another's.
            if ($sender instanceof Player && $target->getName() !== $sender->getName()) {
                $this->error($sender, Messages::get($this->plugin, Messages::SKYMINE_PVP_SELF_ONLY));

                return true;
            }

            $newState = $manager->toggle(
                $target->getName()
            );

            $this->success(
                $target,
                Messages::get(
                    $this->plugin,
                    Messages::SKYMINE_PVP_NOW,
                    ['state' => $newState ? 'ON' : 'OFF']
                )
            );

            return true;
        }

        $state = match ($requested) {
            'on', 'true' => true,
            'off', 'false' => false,
            default => !$manager->getState($target->getName())
        };

        $result = $manager->setState(
            $target->getName(),
            $state
        );

        $this->success(
            $sender,
            Messages::get(
                $this->plugin,
                Messages::SKYMINE_PVP_FOR,
                ['player' => $target->getName(), 'state' => $result ? 'ON' : 'OFF']
            )
        );

        return true;
    }

    private function handleHud(
        CommandSender $sender
    ): bool {
        if (!$sender instanceof Player) {
            $this->error(
                $sender,
                Messages::get($this->plugin, Messages::SKYMINE_HUD_ONLY)
            );

            return true;
        }

        $enabled = $this->plugin->getScoreHud()->toggle(
            $sender
        );

        $this->success(
            $sender,
            Messages::get(
                $this->plugin,
                Messages::SKYMINE_HUD_STATE,
                ['state' => $enabled ? 'enabled' : 'disabled']
            )
        );

        return true;
    }

    /**
     * @param list<string> $args
     */
    private function handleStats(
        CommandSender $sender,
        array $args
    ): bool {
        $targetName = $this->resolveStatsTarget(
            $sender,
            $args[1] ?? null
        );

        if ($targetName === null) {
            return true;
        }

        $miner = $this->plugin->getMinerManager()->getOrLoad(
            $targetName
        );

        $sender->sendMessage(
            $this->prefixed(
                Messages::get($this->plugin, Messages::SKYMINE_STATS_TITLE, ['player' => $targetName])
            )
        );
        $sender->sendMessage(
            $this->prefixed(
                Messages::get(
                    $this->plugin,
                    Messages::SKYMINE_STATS_MINED,
                    ['mined' => NumberFormatter::short($miner->getMined())]
                )
            )
        );
        $sender->sendMessage(
            $this->prefixed(
                Messages::get(
                    $this->plugin,
                    Messages::SKYMINE_STATS_DEATHS,
                    ['deaths' => NumberFormatter::short($miner->getDeaths())]
                )
            )
        );
        $sender->sendMessage(
            $this->prefixed(
                Messages::get(
                    $this->plugin,
                    Messages::SKYMINE_STATS_KILLS,
                    ['kills' => NumberFormatter::short($miner->getKills())]
                )
            )
        );
        $sender->sendMessage(
            $this->prefixed(
                Messages::get(
                    $this->plugin,
                    Messages::SKYMINE_STATS_STREAK,
                    ['streak' => $miner->getKillStreak()]
                )
            )
        );
        $sender->sendMessage(
            $this->prefixed(
                Messages::get(
                    $this->plugin,
                    Messages::SKYMINE_STATS_MONEY,
                    ['money' => NumberFormatter::short($this->plugin->getMoneyEconomy()->get($targetName))]
                )
            )
        );
        $sender->sendMessage(
            $this->prefixed(
                Messages::get(
                    $this->plugin,
                    Messages::SKYMINE_STATS_GOLD,
                    ['gold' => NumberFormatter::short($this->plugin->getGoldEconomy()->get($targetName))]
                )
            )
        );

        return true;
    }

    /**
     * @param list<string> $args
     */
    private function handleEconomy(
        CommandSender $sender,
        array $args,
        BaseEconomy $economy
    ): bool {
        if (!$sender->hasPermission(Main::PERMISSION_ADMIN)) {
            $this->error(
                $sender,
                Messages::get($this->plugin, Messages::COMMON_NO_PERMISSION)
            );

            return true;
        }

        $action = strtolower(
            $args[1] ?? 'check'
        );

        $targetName = $args[2] ?? (
            $sender instanceof Player
                ? $sender->getName()
                : null
        );

        if ($targetName === null) {
            $this->error(
                $sender,
                Messages::get(
                    $this->plugin,
                    Messages::SKYMINE_ECONOMY_USAGE,
                    ['type' => $economy->getType()]
                )
            );

            return true;
        }

        $current = $economy->get($targetName);

        if ($action === 'check') {
            $this->success(
                $sender,
                Messages::get(
                    $this->plugin,
                    Messages::SKYMINE_ECONOMY_CHECK,
                    [
                        'player' => $targetName,
                        'balance' => NumberFormatter::short($current),
                        'type' => $economy->getType()
                    ]
                )
            );

            return true;
        }

        $amount = isset($args[3]) && is_numeric($args[3])
            ? (int) $args[3]
            : 0;

        if ($amount <= 0) {
            $this->error(
                $sender,
                Messages::get($this->plugin, Messages::SKYMINE_ECONOMY_AMOUNT)
            );

            return true;
        }

        $reason = EconomyChangeEventReason::COMMAND;

        $new = match ($action) {
            'give', 'add' => $economy->add(
                $targetName,
                $amount,
                $reason
            ),
            'take', 'remove' => $economy->reduce(
                $targetName,
                $amount,
                $reason
            ),
            'set' => $this->setBalance(
                $economy,
                $targetName,
                $amount,
                $reason
            ),
            default => -1
        };

        if ($new < 0) {
            $this->error(
                $sender,
                Messages::get($this->plugin, Messages::SKYMINE_ECONOMY_ACTIONS)
            );

            return true;
        }

        $this->success(
            $sender,
            Messages::get(
                $this->plugin,
                Messages::SKYMINE_ECONOMY_SET,
                [
                    'player' => $targetName,
                    'balance' => NumberFormatter::short($new),
                    'type' => $economy->getType()
                ]
            )
        );

        $player = $this->plugin->getServer()->getPlayerExact(
            $targetName
        );

        if ($player !== null) {
            $player->sendMessage(
                $this->prefixed(
                    Messages::get(
                        $this->plugin,
                        Messages::SKYMINE_ECONOMY_CHANGED,
                        ['type' => $economy->getType(), 'balance' => NumberFormatter::short($new)]
                    )
                )
            );
        }

        return true;
    }

    /**
     * @param list<string> $args
     */
    private function handlePosition(
        CommandSender $sender,
        array $args,
        string $which
    ): bool {
        if (!$sender instanceof Player) {
            $this->error(
                $sender,
                Messages::get($this->plugin, Messages::SKYMINE_POS_ONLY)
            );

            return true;
        }

        if (!$sender->hasPermission(Main::PERMISSION_ADMIN)) {
            $this->error(
                $sender,
                Messages::get($this->plugin, Messages::COMMON_NO_PERMISSION)
            );

            return true;
        }

        $position = TargetResolver::playerMiddle($sender);

        $selection = $this->plugin->getSelectionManager();

        if ($which === 'pos1') {
            $selection->setPos1(
                $sender,
                $position
            );
        } else {
            $selection->setPos2(
                $sender,
                $position
            );
        }

        $this->success(
            $sender,
            Messages::get(
                $this->plugin,
                Messages::SKYMINE_POS_SET,
                [
                    'which' => $which,
                    'world' => $position->getWorld()->getFolderName(),
                    'x' => $position->getFloorX(),
                    'y' => $position->getFloorY(),
                    'z' => $position->getFloorZ()
                ]
            )
        );

        return true;
    }

    private function handleWand(
        CommandSender $sender
    ): bool {
        if (!$sender instanceof Player) {
            $this->error(
                $sender,
                Messages::get($this->plugin, Messages::SKYMINE_WAND_ONLY)
            );

            return true;
        }

        if (!$sender->hasPermission(Main::PERMISSION_ADMIN)) {
            $this->error(
                $sender,
                Messages::get($this->plugin, Messages::COMMON_NO_PERMISSION)
            );

            return true;
        }

        foreach (
            $sender->getInventory()->addItem(
                PositionWand::create()
            ) as $leftover
        ) {
            $sender->dropItem($leftover);
        }

        $this->success(
            $sender,
            Messages::get($this->plugin, Messages::SKYMINE_WAND_GIVEN)
        );

        return true;
    }

    private function handleResetSelection(
        CommandSender $sender
    ): bool {
        if (!$sender instanceof Player) {
            $this->error(
                $sender,
                Messages::get($this->plugin, Messages::SKYMINE_SELECTION_CLEAR_ONLY)
            );

            return true;
        }

        if (!$sender->hasPermission(Main::PERMISSION_ADMIN)) {
            $this->error(
                $sender,
                Messages::get($this->plugin, Messages::COMMON_NO_PERMISSION)
            );

            return true;
        }

        $this->plugin->getSelectionManager()->clear(
            $sender
        );

        $this->success(
            $sender,
            Messages::get($this->plugin, Messages::SKYMINE_SELECTION_CLEARED)
        );

        return true;
    }

    private function handleReload(
        CommandSender $sender
    ): bool {
        if (!$sender->hasPermission(Main::PERMISSION_ADMIN)) {
            $this->error(
                $sender,
                Messages::get($this->plugin, Messages::COMMON_NO_PERMISSION)
            );

            return true;
        }

        try {
            $this->plugin->reload();
        } catch (\Throwable $exception) {
            $this->fail(
                $sender,
                $exception
            );

            return true;
        }

        $this->success(
            $sender,
            Messages::get($this->plugin, Messages::SKYMINE_RELOADED)
        );

        return true;
    }

    /**
     * @param list<string> $args
     */
    private function handleLagMaker(
        CommandSender $sender,
        array $args
    ): bool {
        if (!$sender->hasPermission(Main::PERMISSION_ADMIN)) {
            $this->error(
                $sender,
                Messages::get($this->plugin, Messages::COMMON_NO_PERMISSION)
            );

            return true;
        }

        $lagMaker = $this->plugin->getLagMaker();

        $action = strtolower(
            $args[1] ?? 'status'
        );

        if ($action === 'status') {
            $this->info(
                $sender,
                Messages::get(
                    $this->plugin,
                    Messages::SKYMINE_LAG_STATUS,
                    [
                        'enabled' => $lagMaker->isEnabled() ? 'yes' : 'no',
                        'mode' => $lagMaker->getMode(),
                        'stack' => $this->plugin->getConfigManager()->getBool('lagmaker.auto-stack', true) ? 'yes' : 'no'
                    ]
                )
            );

            return true;
        }

        if ($action === 'toggle') {
            $enabled = !$lagMaker->isEnabled();

            $lagMaker->setEnabled($enabled);

            $this->success(
                $sender,
                Messages::get(
                    $this->plugin,
                    Messages::SKYMINE_LAG_TOGGLED,
                    ['state' => $enabled ? 'enabled' : 'disabled']
                )
            );

            return true;
        }

        if ($action === 'cleanup') {
            $mode = strtolower(
                $args[2] ?? ''
            );

            if (
                !in_array(
                    $mode,
                    [
                        LagMaker::MODE_OFF,
                        LagMaker::MODE_TTL,
                        LagMaker::MODE_ALL
                    ],
                    true
                )
            ) {
                $this->error(
                    $sender,
                    Messages::get($this->plugin, Messages::SKYMINE_LAG_MODE)
                );

                return true;
            }

            $this->plugin->getConfigManager()->set(
                'lagmaker.cleanup.mode',
                $mode
            );
            $this->plugin->getConfigManager()->save();

            $this->success(
                $sender,
                Messages::get($this->plugin, Messages::SKYMINE_LAG_MODE_SET, ['mode' => $mode])
            );

            return true;
        }

        $this->error(
            $sender,
            Messages::get($this->plugin, Messages::SKYMINE_LAG_USAGE)
        );

        return true;
    }

    private function handleSave(
        CommandSender $sender
    ): bool {
        if (!$sender->hasPermission(Main::PERMISSION_ADMIN)) {
            $this->error(
                $sender,
                Messages::get($this->plugin, Messages::COMMON_NO_PERMISSION)
            );

            return true;
        }

        try {
            $this->plugin->saveAllData();
        } catch (\Throwable $exception) {
            $this->fail(
                $sender,
                $exception
            );

            return true;
        }

        $this->success(
            $sender,
            Messages::get($this->plugin, Messages::SKYMINE_SAVED)
        );

        return true;
    }

    /**
     * Resolves the player a stats query is about.
     *
     * Stats are read from the stored file, so an offline name is fine here;
     * unlike PvP, nothing about them needs a live session.
     */
    private function resolveStatsTarget(
        CommandSender $sender,
        ?string $name
    ): ?string {
        if (
            $name === null
            || $name === ''
        ) {
            if (!$sender instanceof Player) {
                $this->error(
                    $sender,
                    Messages::get($this->plugin, Messages::SKYMINE_STATS_CONSOLE)
                );

                return null;
            }

            return $sender->getName();
        }

        return $name;
    }

    private function setBalance(
        BaseEconomy $economy,
        string $playerName,
        int $amount,
        string $reason
    ): int {
        $economy->set(
            $playerName,
            $amount,
            $reason
        );

        return $economy->get(
            $playerName
        );
    }

    private function handleHelp(
        CommandSender $sender
    ): bool {
        $lines = [
            Messages::get($this->plugin, Messages::SKYMINE_HELP_MENU),
            Messages::get($this->plugin, Messages::SKYMINE_HELP_PVP),
            Messages::get($this->plugin, Messages::SKYMINE_HELP_HUD),
            Messages::get($this->plugin, Messages::SKYMINE_HELP_STATS),
            Messages::get($this->plugin, Messages::SKYMINE_HELP_MONEY),
            Messages::get($this->plugin, Messages::SKYMINE_HELP_GOLD),
            Messages::get($this->plugin, Messages::SKYMINE_HELP_POS1),
            Messages::get($this->plugin, Messages::SKYMINE_HELP_POS2),
            Messages::get($this->plugin, Messages::SKYMINE_HELP_WAND),
            Messages::get($this->plugin, Messages::SKYMINE_HELP_LAGMAKER),
            Messages::get($this->plugin, Messages::SKYMINE_HELP_RELOAD),
            Messages::get($this->plugin, Messages::SKYMINE_HELP_SAVE)
        ];

        $sender->sendMessage(
            $this->prefixed(Messages::get($this->plugin, Messages::SKYMINE_HELP_TITLE))
        );

        foreach (
            $lines as $line
        ) {
            $sender->sendMessage(
                $this->prefixed($line)
            );
        }

        return true;
    }
}