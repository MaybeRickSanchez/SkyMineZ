<?php

declare(strict_types=1);

namespace AM\SkyMineZ\command;

use AM\SkyMineZ\Main;
use AM\SkyMineZ\config\Messages;
use AM\SkyMineZ\slapper\SlapperAdminForm;
use AM\SkyMineZ\slapper\Slapper;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;

/**
 * /slapper - creates NPC slappers and the blocks attached to them.
 *
 * A slapper runs a list of commands and prints a list of messages when
 * right-clicked. `{player}` is replaced with the clicker's name in both.
 */
final class SlapperCommand extends BaseCommand
{
    public function __construct(
        Main $plugin
    ) {
        parent::__construct(
            $plugin,
            'slapper',
            'Manage SkyMineZ slappers',
            '/slapper <create|remove|list|move|msg|cmd|block> ...',
            ['slappers', 'npc']
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
            'move' => $this->handleMove($sender, $args),
            'msg', 'message' => $this->handleMessage($sender, $args),
            'cmd', 'command' => $this->handleCommand($sender, $args),
            'block' => $this->handleBlock($sender, $args),
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

                if ($name === null || !self::isValidName($name)) {
                    $this->error(
                        $player,
                        Messages::get($this->plugin, Messages::SLAPPER_CREATE_USAGE)
                    );

                    return;
                }

                try {
                    $this->plugin->getSlapperManager()->addSlapper(
                        $name,
                        TargetResolver::playerMiddle($player),
                        $player
                    );
                } catch (\Throwable $exception) {
                    $this->fail(
                        $player,
                        $exception
                    );

                    return;
                }

                $this->plugin->getSlapperManager()->save(
                    $name
                );

                $this->success(
                    $player,
                    Messages::get($this->plugin, Messages::SLAPPER_CREATED, ['name' => $name])
                );

                $player->sendMessage(
                    $this->prefixed(
                        Messages::get($this->plugin, Messages::SLAPPER_CREATE_HINT, ['name' => $name])
                    )
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
                Messages::get($this->plugin, Messages::SLAPPER_REMOVE_USAGE)
            );

            return true;
        }

        $removed = $this->plugin->getSlapperManager()->removeSlapper(
            $name
        );

        if (!$removed) {
            $this->error(
                $sender,
                Messages::get($this->plugin, Messages::SLAPPER_UNKNOWN, ['name' => $name])
            );

            return true;
        }

        $this->plugin->getSlapperManager()->saveAll();

        $this->success(
            $sender,
            Messages::get($this->plugin, Messages::SLAPPER_REMOVED_FULL, ['name' => $name])
        );

        return true;
    }

    private function handleList(
        CommandSender $sender
    ): bool {
        $manager = $this->plugin->getSlapperManager();
        $slappers = $manager->getSlappers();

        if ($slappers === []) {
            $this->info(
                $sender,
                Messages::get($this->plugin, Messages::SLAPPER_LIST_NONE)
            );

            return true;
        }

        $sender->sendMessage(
            $this->prefixed(
                Messages::get($this->plugin, Messages::SLAPPER_LIST_TITLE, ['count' => count($slappers)])
            )
        );

        foreach (
            $slappers as $name => $slapper
        ) {
            $location = $slapper->getLocation();

            $sender->sendMessage(
                $this->prefixed(
                    Messages::get($this->plugin, Messages::SLAPPER_LIST_ROW, ['name' => $name, 'world' => $location->getWorld()->getFolderName(), 'commands' => count($slapper->getCommands()), 'messages' => count($slapper->getMessages())])
                )
            );
        }

        return true;
    }

    /**
     * @param list<string> $args
     */
    private function handleMove(
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

                if ($name === null) {
                    $this->error(
                        $player,
                        Messages::get($this->plugin, Messages::SLAPPER_MOVE_USAGE)
                    );

                    return;
                }

                $slapper = $this->resolveSlapper($player, $name);

                if ($slapper === null) {
                    return;
                }

                if (
                    !$this->plugin->getSlapperManager()->move(
                        $name,
                        TargetResolver::playerMiddle($player)
                    )
                ) {
                    $this->error(
                        $player,
                        Messages::get($this->plugin, Messages::SLAPPER_UNKNOWN, ['name' => $name])
                    );

                    return;
                }

                $this->success(
                    $player,
                    Messages::get($this->plugin, Messages::SLAPPER_MOVED_FULL, ['name' => $name])
                );
            }
        );
    }

    /**
     * @param list<string> $args
     */
    private function handleMessage(
        CommandSender $sender,
        array $args
    ): bool {
        return $this->handleStringList($sender, $args, true);
    }

    /**
     * @param list<string> $args
     */
    private function handleCommand(
        CommandSender $sender,
        array $args
    ): bool {
        return $this->handleStringList($sender, $args, false);
    }

    /**
     * Shared add/remove/clear/list flow for the slapper message and command
     * lists. One method for both because the control flow is identical; only
     * the Slapper accessors and message keys differ.
     *
     * @param list<string> $args
     */
    private function handleStringList(
        CommandSender $sender,
        array $args,
        bool $messages
    ): bool {
        $action = strtolower(
            $args[1] ?? 'list'
        );

        $slapper = $this->plugin->getSlapperManager()->getSlapper(
            $args[2] ?? ''
        );

        $usageKey = $messages ? Messages::SLAPPER_MSG_USAGE : Messages::SLAPPER_CMD_USAGE;
        $addUsageKey = $messages ? Messages::SLAPPER_MSG_ADD_USAGE : Messages::SLAPPER_CMD_ADD_USAGE;
        $rangeKey = $messages ? Messages::SLAPPER_MSG_RANGE : Messages::SLAPPER_CMD_RANGE;
        $noneKey = $messages ? Messages::SLAPPER_MSG_NONE : Messages::SLAPPER_CMD_NONE;
        $rowKey = $messages ? Messages::SLAPPER_MSG_ROW : Messages::SLAPPER_CMD_ROW;
        $updatedKey = $messages ? Messages::SLAPPER_MSG_UPDATED : Messages::SLAPPER_CMD_UPDATED;

        if ($slapper === null) {
            $this->error(
                $sender,
                Messages::get($this->plugin, $usageKey)
            );

            return true;
        }

        $text = $this->joinArguments(
            $args,
            3
        );

        switch ($action) {
            case 'add':
                if ($text === '') {
                    $this->error(
                        $sender,
                        Messages::get($this->plugin, $addUsageKey)
                    );

                    return true;
                }

                if ($messages) {
                    $slapper->addMessage($text);
                } else {
                    $slapper->addCommand($text);
                }
                break;

            case 'remove':
                $index = is_numeric($text)
                    ? (int) $text
                    : -1;

                $removed = $messages
                    ? $slapper->removeMessage($index)
                    : $slapper->removeCommand($index);

                if (!$removed) {
                    $this->error(
                        $sender,
                        Messages::get($this->plugin, $rangeKey)
                    );

                    return true;
                }
                break;

            case 'clear':
                if ($messages) {
                    $slapper->clearMessages();
                } else {
                    $slapper->clearCommands();
                }
                break;

            case 'list':
                $entries = $messages
                    ? $slapper->getMessages()
                    : $slapper->getCommands();

                if ($entries === []) {
                    $this->info(
                        $sender,
                        Messages::get($this->plugin, $noneKey)
                    );

                    return true;
                }

                foreach (
                    $entries as $index => $entry
                ) {
                    $sender->sendMessage(
                        $this->prefixed(
                            Messages::get($this->plugin, $rowKey, $messages
                                ? ['index' => $index, 'message' => $entry]
                                : ['index' => $index, 'command' => $entry])
                        )
                    );
                }

                return true;

            default:
                $this->error(
                    $sender,
                    Messages::get($this->plugin, Messages::SLAPPER_USE_LIST)
                );

                return true;
        }

        $this->plugin->getSlapperManager()->save(
            $slapper->getName()
        );

        $this->success(
            $sender,
            Messages::get($this->plugin, $updatedKey)
        );

        return true;
    }

    /**
     * @param list<string> $args
     */
    private function handleBlock(
        CommandSender $sender,
        array $args
    ): bool {
        $manager = $this->plugin->getSlapperManager();

        $action = strtolower(
            $args[1] ?? 'list'
        );

        switch ($action) {
            case 'add':
                return $this->runPlayerSubCommand(
                    $sender,
                    $args,
                    function(
                        Player $player,
                        array $args
                    ) use ($manager): void {
                        $slapperName = $args[2] ?? null;
                        $blockName = $args[3] ?? null;
                        $label = $args[4] ?? (
                            $slapperName ?? 'slapper'
                        );

                        if (
                            $slapperName === null
                            || $blockName === null
                        ) {
                            $this->error(
                                $player,
                                Messages::get($this->plugin, Messages::SLAPPER_BLOCK_ADD_USAGE)
                            );

                            return;
                        }

                        $slapper = $this->resolveSlapper($player, $slapperName);

                        if ($slapper === null) {
                            return;
                        }

                        $block = BlockParser::parse($blockName);

                        if ($block === null) {
                            $this->error(
                                $player,
                                Messages::get($this->plugin, Messages::COMMON_UNKNOWN_BLOCK, ['block' => $blockName])
                            );

                            return;
                        }

                        $position = TargetResolver::blockOrBelow(
                            $player
                        )->getPosition();

                        $blockNameKey = self::isValidName($label)
                            ? $label
                            : $slapperName;

                        try {
                            $manager->addBlock(
                                $blockNameKey,
                                $position,
                                $block,
                                $slapperName
                            );
                        } catch (\Throwable $exception) {
                            $this->fail(
                                $player,
                                $exception
                            );

                            return;
                        }

                        $manager->save(
                            $slapperName
                        );

                        $this->success(
                            $player,
                            Messages::get($this->plugin, Messages::SLAPPER_BLOCK_ADDED, ['label' => $blockNameKey])
                        );
                    }
                );

            case 'remove':
                $name = $args[2] ?? null;

                if ($name === null) {
                    $this->error(
                        $sender,
                        Messages::get($this->plugin, Messages::SLAPPER_BLOCK_REMOVE_USAGE)
                    );

                    return true;
                }

                $removed = $manager->removeBlock($name);

                if (!$removed) {
                    $this->error(
                        $sender,
                        Messages::get($this->plugin, Messages::SLAPPER_BLOCK_UNKNOWN, ['name' => $name])
                    );

                    return true;
                }

                $manager->saveAll();

                $this->success(
                    $sender,
                    Messages::get($this->plugin, Messages::SLAPPER_BLOCK_REMOVED, ['name' => $name])
                );

                return true;

            case 'list':
                $blocks = $manager->getSlapperBlocks();

                if ($blocks === []) {
                    $this->info(
                        $sender,
                        Messages::get($this->plugin, Messages::SLAPPER_BLOCKS_NONE)
                    );

                    return true;
                }

                $sender->sendMessage(
                    $this->prefixed(
                        Messages::get($this->plugin, Messages::SLAPPER_BLOCKS_TITLE, ['count' => count($blocks)])
                    )
                );

                foreach (
                    $blocks as $name => $block
                ) {
                    $position = $block->getPosition();

                    $sender->sendMessage(
                        $this->prefixed(
                            Messages::get($this->plugin, Messages::SLAPPER_BLOCK_ROW, ['name' => $name, 'block' => $block->getBlock()->getName(), 'slapper' => $block->getSlapperName(), 'where' => \AM\SkyMineZ\useless\Positions::describe($position)])
                        )
                    );
                }

                return true;

            default:
                $this->error(
                    $sender,
                    Messages::get($this->plugin, Messages::SLAPPER_BLOCK_USE)
                );

                return true;
        }
    }

    /**
     * Looks a slapper up by name, reporting the miss to the sender.
     */
    private function resolveSlapper(
        CommandSender $sender,
        ?string $name
    ): ?Slapper {
        return $this->resolveNamed(
            $sender,
            $name,
            fn(string $id): ?Slapper => $this->plugin->getSlapperManager()->getSlapper($id),
            Messages::SLAPPER_UNKNOWN
        );
    }

    private function handleMenu(
        CommandSender $sender
    ): bool {
        if (!$sender instanceof Player) {
            $this->error($sender, Messages::get($this->plugin, Messages::SLAPPER_MENU_ONLY));

            return true;
        }

        (new SlapperAdminForm($this->plugin))->send($sender);

        return true;
    }

    private function handleHelp(
        CommandSender $sender
    ): bool {
        $lines = [
            Messages::get($this->plugin, Messages::SLAPPER_HELP_CREATE),
            Messages::get($this->plugin, Messages::SLAPPER_HELP_REMOVE),
            Messages::get($this->plugin, Messages::SLAPPER_HELP_MOVE),
            Messages::get($this->plugin, Messages::SLAPPER_HELP_MSG),
            Messages::get($this->plugin, Messages::SLAPPER_HELP_CMD),
            Messages::get($this->plugin, Messages::SLAPPER_HELP_BLOCK),
            Messages::get($this->plugin, Messages::SLAPPER_HELP_PLACEHOLDERS),
            Messages::get($this->plugin, Messages::SLAPPER_HELP_CONSOLE),
            Messages::get($this->plugin, Messages::SLAPPER_HELP_LIST)
        ];

        $sender->sendMessage(
            $this->prefixed(
                Messages::get($this->plugin, Messages::SLAPPER_HELP_TITLE)
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