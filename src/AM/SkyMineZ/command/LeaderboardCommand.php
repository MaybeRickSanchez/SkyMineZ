<?php

declare(strict_types=1);

namespace AM\SkyMineZ\command;

use AM\SkyMineZ\leaderboard\Leaderboard;
use AM\SkyMineZ\Main;
use AM\SkyMineZ\config\Messages;
use AM\SkyMineZ\leaderboard\LeaderboardAdminForm;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;

/**
 * /lb - creates leaderboards and edits their title and position.
 */
final class LeaderboardCommand extends BaseCommand
{
    public function __construct(
        Main $plugin
    ) {
        parent::__construct(
            $plugin,
            'lb',
            'Manage SkyMineZ leaderboards',
            '/lb <create|remove|list|info|title|setpos|refresh> ...',
            ['leaderboard', 'leaderboards']
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
        if (!$this->testPermission($sender)) {
            return true;
        }

        $sub = strtolower(
            $args[0] ?? 'help'
        );

        return match ($sub) {
            'create', 'new' => $this->handleCreate($sender, $args),
            'remove', 'delete' => $this->handleRemove($sender, $args),
            'list' => $this->handleList($sender),
            'info' => $this->handleInfo($sender, $args),
            'title' => $this->handleTitle($sender, $args),
            'setpos' => $this->handleSetPosition($sender, $args),
            'refresh' => $this->handleRefresh($sender),
            'menu' => $this->handleMenu($sender),
            default => $this->handleHelp($sender)
        };
    }

    /**
     * @param list<string> $args
     */
    private function handleCreate(
        CommandSender $sender,
        array $args
    ): bool {
        return $this->runPlayerSubCommand(
            $sender,
            $args,
            function(
                Player $player,
                array $args
            ): void {
                $name = $args[1] ?? null;
                $type = strtolower(
                    $args[2] ?? ''
                );

                if ($name === null || $type === '') {
                    $this->error(
                        $player,
                        Messages::get($this->plugin, Messages::BOARD_CREATE_USAGE)
                    );

                    $player->sendMessage(
                        $this->prefixed(
                            Messages::get($this->plugin, Messages::BOARD_TYPES, ['types' => implode(', ', Leaderboard::getTypes())])
                        )
                    );

                    return;
                }

                if (!Leaderboard::isValidType($type)) {
                    $this->error(
                        $player,
                        Messages::get($this->plugin, Messages::BOARD_BAD_TYPE, ['types' => implode(', ', Leaderboard::getTypes())])
                    );

                    return;
                }

                $position = TargetResolver::playerMiddle($player);

                $title = $this->joinArguments(
                    $args,
                    3
                );

                try {
                    $this->plugin->getLeaderboardManager()->add(
                        $name,
                        $type,
                        $position,
                        $title === '' ? null : $title
                    );
                } catch (\Throwable $exception) {
                    $this->fail(
                        $player,
                        $exception
                    );

                    return;
                }

                $this->success(
                    $player,
                    Messages::get($this->plugin, Messages::BOARD_CREATED, ['name' => $name])
                );
            }
        );
    }

    /**
     * @param list<string> $args
     */
    private function handleRemove(
        CommandSender $sender,
        array $args
    ): bool {
        $name = $args[1] ?? null;

        if ($name === null) {
            $this->error(
                $sender,
                Messages::get($this->plugin, Messages::BOARD_REMOVE_USAGE)
            );

            return true;
        }

        $removed = $this->plugin->getLeaderboardManager()->remove(
            $name
        );

        $removed
            ? $this->success(
                $sender,
                Messages::get($this->plugin, Messages::BOARD_REMOVED, ['name' => $name])
            )
            : $this->error(
                $sender,
                Messages::get($this->plugin, Messages::BOARD_UNKNOWN, ['name' => $name])
            );

        return true;
    }

    private function handleList(
        CommandSender $sender
    ): bool {
        $manager = $this->plugin->getLeaderboardManager();

        if ($manager->count() === 0) {
            $this->info(
                $sender,
                Messages::get($this->plugin, Messages::BOARD_NONE)
            );

            return true;
        }

        $sender->sendMessage(
            $this->prefixed(
                Messages::get($this->plugin, Messages::BOARD_LIST_TITLE, ['count' => $manager->count()])
            )
        );

        foreach (
            $manager->getAll() as $name => $leaderboard
        ) {
            $position = $leaderboard->getPosition();

            $sender->sendMessage(
                $this->prefixed(
                    Messages::get($this->plugin, Messages::BOARD_LIST_ROW, ['name' => $name, 'type' => $leaderboard->getType(), 'world' => $leaderboard->getWorld()->getFolderName(), 'x' => $position->getFloorX(), 'y' => $position->getFloorY(), 'z' => $position->getFloorZ()])
                )
            );
        }

        return true;
    }

    /**
     * @param list<string> $args
     */
    private function handleInfo(
        CommandSender $sender,
        array $args
    ): bool {
        $leaderboard = $this->resolveLeaderboard($sender, $args[1] ?? null);

        if ($leaderboard === null) {
            return true;
        }

        $sender->sendMessage(
            $this->prefixed(
                Messages::get($this->plugin, Messages::BOARD_INFO_TITLE, ['name' => $leaderboard->getName()])
            )
        );
        $sender->sendMessage(
            $this->prefixed(
                Messages::get($this->plugin, Messages::BOARD_INFO_TYPE, ['type' => $leaderboard->getType()])
            )
        );
        $sender->sendMessage(
            $this->prefixed(
                Messages::get($this->plugin, Messages::BOARD_INFO_TITLE_IS, ['title' => $leaderboard->getTitle()])
            )
        );
        $sender->sendMessage(
            $this->prefixed(
                Messages::get($this->plugin, Messages::BOARD_INFO_WORLD, ['world' => $leaderboard->getWorld()->getFolderName()])
            )
        );
        $sender->sendMessage(
            $this->prefixed(
                Messages::get($this->plugin, Messages::BOARD_INFO_POS, ['where' => \AM\SkyMineZ\useless\Positions::describe($leaderboard->getPosition())])
            )
        );

        return true;
    }

    /**
     * @param list<string> $args
     */
    private function handleTitle(
        CommandSender $sender,
        array $args
    ): bool {
        $name = $args[1] ?? null;
        $title = $this->joinArguments(
            $args,
            2
        );

        if ($name === null || $title === '') {
            $this->error(
                $sender,
                Messages::get($this->plugin, Messages::BOARD_TITLE_USAGE)
            );

            return true;
        }

        $leaderboard = $this->resolveLeaderboard($sender, $name);

        if ($leaderboard === null) {
            return true;
        }

        $leaderboard->setTitle($title);

        $this->plugin->getLeaderboardManager()->refreshAll();
        $this->plugin->getLeaderboardManager()->save($name);

        $this->success(
            $sender,
            Messages::get($this->plugin, Messages::BOARD_TITLE_SET)
        );

        return true;
    }

    /**
     * @param list<string> $args
     */
    private function handleSetPosition(
        CommandSender $sender,
        array $args
    ): bool {
        return $this->runPlayerSubCommand(
            $sender,
            $args,
            function(
                Player $player,
                array $args
            ): void {
                $leaderboard = $this->resolveLeaderboard($player, $args[1] ?? null);

                if ($leaderboard === null) {
                    return;
                }

                $position = TargetResolver::playerMiddle($player);

                $leaderboard->setPosition($position);

                $this->plugin->getLeaderboardManager()->refreshAll();
                $this->plugin->getLeaderboardManager()->save(
                    $leaderboard->getName()
                );

                $this->success(
                    $player,
                    Messages::get($this->plugin, Messages::BOARD_MOVED_TO, ['where' => \AM\SkyMineZ\useless\Positions::describe($position)])
                );
            }
        );
    }

    private function handleRefresh(
        CommandSender $sender
    ): bool {
        $this->plugin->getLeaderboardManager()->refreshAll();

        $this->success(
            $sender,
            Messages::get($this->plugin, Messages::BOARD_REFRESHED_ALL)
        );

        return true;
    }

    /**
     * Looks a leaderboard up by name, reporting the miss to the sender.
     */
    private function resolveLeaderboard(
        CommandSender $sender,
        ?string $name
    ): ?Leaderboard {
        return $this->resolveNamed(
            $sender,
            $name,
            fn(string $id): ?Leaderboard => $this->plugin->getLeaderboardManager()->get($id),
            Messages::BOARD_UNKNOWN
        );
    }

    private function handleMenu(
        CommandSender $sender
    ): bool {
        if (!$sender instanceof Player) {
            $this->error($sender, Messages::get($this->plugin, Messages::BOARD_MENU_ONLY));

            return true;
        }

        (new LeaderboardAdminForm($this->plugin))->send($sender);

        return true;
    }

    private function handleHelp(
        CommandSender $sender
    ): bool {
        $lines = [
            Messages::get($this->plugin, Messages::BOARD_HELP_CREATE),
            Messages::get($this->plugin, Messages::BOARD_HELP_TYPES, ['types' => implode(', ', Leaderboard::getTypes())]),
            Messages::get($this->plugin, Messages::BOARD_HELP_REMOVE),
            Messages::get($this->plugin, Messages::BOARD_HELP_TITLE_SET),
            Messages::get($this->plugin, Messages::BOARD_HELP_SETPOS),
            Messages::get($this->plugin, Messages::BOARD_HELP_REFRESH),
            Messages::get($this->plugin, Messages::BOARD_HELP_INFO),
            Messages::get($this->plugin, Messages::BOARD_HELP_LIST)
        ];

        $sender->sendMessage(
            $this->prefixed(
                Messages::get($this->plugin, Messages::BOARD_HELP_TITLE)
            )
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