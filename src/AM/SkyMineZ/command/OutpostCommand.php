<?php

declare(strict_types=1);

namespace AM\SkyMineZ\command;

use AM\SkyMineZ\Main;
use AM\SkyMineZ\config\Messages;
use AM\SkyMineZ\outpost\OutpostAdminForm;
use AM\SkyMineZ\outpost\Outpost;
use AM\SkyMineZ\useless\NumberFormatter;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;

/**
 * /outpost - creates outposts and inspects or overrides their ownership.
 */
final class OutpostCommand extends BaseCommand
{
    public function __construct(
        Main $plugin
    ) {
        parent::__construct(
            $plugin,
            'outpost',
            'Manage SkyMineZ outposts',
            '/outpost <create|remove|list|info|owner|setpos|reset> ...',
            ['outposts']
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
            'owner' => $this->handleOwner($sender, $args),
            'reset' => $this->handleReset($sender, $args),
            'menu' => $this->handleMenu($sender),
            'setlabel' => $this->handleSetLabel($sender, $args),
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
                        Messages::get($this->plugin, Messages::OUTPOST_CREATE_USAGE)
                    );

                    return;
                }

                $region = $this->selectionRegion($player);

                if ($region === null) {
                    return;
                }

                [$pos1, $pos2] = $region;

                try {
                    $outpost = $this->plugin->getOutpostManager()->create(
                        $name,
                        $pos1,
                        $pos2
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
                    Messages::get($this->plugin, Messages::OUTPOST_CREATED, ['name' => $name, 'world' => $outpost->getInfo()->getPosition()?->getWorld()->getFolderName() ?? '?'])
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
                Messages::get($this->plugin, Messages::OUTPOST_REMOVE_USAGE)
            );

            return true;
        }

        $removed = $this->plugin->getOutpostManager()->remove(
            $name
        );

        $removed
            ? $this->success(
                $sender,
                Messages::get($this->plugin, Messages::OUTPOST_REMOVED, ['name' => $name])
            )
            : $this->error(
                $sender,
                Messages::get($this->plugin, Messages::OUTPOST_UNKNOWN, ['name' => $name])
            );

        return true;
    }

    private function handleList(
        CommandSender $sender
    ): bool {
        $manager = $this->plugin->getOutpostManager();

        if ($manager->count() === 0) {
            $this->info(
                $sender,
                Messages::get($this->plugin, Messages::OUTPOST_NONE)
            );

            return true;
        }

        $sender->sendMessage(
            $this->prefixed(
                Messages::get($this->plugin, Messages::OUTPOST_LIST_TITLE, ['count' => $manager->count()])
            )
        );

        foreach (
            $manager->getAll() as $name => $outpost
        ) {
            $sender->sendMessage(
                $this->prefixed(
                    Messages::get($this->plugin, Messages::OUTPOST_LIST_ROW,
                        [
                            'name' => $name,
                            'world' => $outpost->getWorld()->getFolderName(),
                            'owner' => $outpost->getOwner() ?? 'none',
                            'state' => $outpost->isCapturable() ? '§acapturable' : '§clocked'
                        ])
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
        $outpost = $this->resolveOutpost($sender, $args[1] ?? null);

        if ($outpost === null) {
            return true;
        }

        $sender->sendMessage(
            $this->prefixed(
                Messages::get($this->plugin, Messages::OUTPOST_INFO_TITLE, ['name' => $outpost->getName()])
            )
        );
        $sender->sendMessage(
            $this->prefixed(
                Messages::get($this->plugin, Messages::OUTPOST_INFO_WORLD, ['world' => $outpost->getWorld()->getFolderName()])
            )
        );
        $sender->sendMessage(
            $this->prefixed(
                Messages::get($this->plugin, Messages::OUTPOST_INFO_STATE, ['state' => $outpost->getState()])
            )
        );
        $sender->sendMessage(
            $this->prefixed(
                Messages::get($this->plugin, Messages::OUTPOST_INFO_OWNER, ['owner' => $outpost->getOwner() ?? 'none'])
            )
        );
        $sender->sendMessage(
            $this->prefixed(
                Messages::get($this->plugin, Messages::OUTPOST_INFO_PROGRESS, ['progress' => $outpost->getProgress(), 'required' => $outpost->getCaptureRequired()])
            )
        );

        $availableAt = $outpost->getAvailableAt();

        if ($availableAt > time()) {
            $sender->sendMessage(
                $this->prefixed(
                    Messages::get($this->plugin, Messages::OUTPOST_INFO_UNLOCKS, ['in' => NumberFormatter::duration($availableAt - time())])
                )
            );
        }

        $owned = $this->plugin->getOutpostManager()->getOwnedBy(
            $outpost->getOwner() ?? ''
        );

        if ($owned !== []) {
            $sender->sendMessage(
                $this->prefixed(
                    Messages::get($this->plugin, Messages::OUTPOST_INFO_OWNED, ['count' => count($owned)])
                )
            );
        }

        return true;
    }

    /**
     * @param list<string> $args
     */
    private function handleOwner(
        CommandSender $sender,
        array $args
    ): bool {
        $outpost = $this->resolveOutpost($sender, $args[1] ?? null);

        if ($outpost === null) {
            return true;
        }

        $playerName = $args[2] ?? null;

        if ($playerName === null) {
            $this->error(
                $sender,
                Messages::get($this->plugin, Messages::OUTPOST_OWNER_USAGE)
            );

            return true;
        }

        if (
            strcasecmp(
                $playerName,
                'clear'
            ) === 0
        ) {
            $outpost->setOwner(null);
        } else {
            $outpost->setOwner($playerName);
        }

        $this->plugin->getOutpostManager()->save(
            $outpost->getName()
        );

        $this->success(
            $sender,
            Messages::get($this->plugin, Messages::OUTPOST_OWNER_SET, ['owner' => $outpost->getOwner() ?? 'none'])
        );

        return true;
    }

    /**
     * @param list<string> $args
     */
    private function handleReset(
        CommandSender $sender,
        array $args
    ): bool {
        $outpost = $this->resolveOutpost($sender, $args[1] ?? null);

        if ($outpost === null) {
            return true;
        }

        $outpost->restore(
            $outpost->getOwner(),
            Outpost::STATE_CAPTABLE,
            0,
            0,
            0
        );

        $this->plugin->getOutpostManager()->save(
            $outpost->getName()
        );

        $this->success(
            $sender,
            Messages::get($this->plugin, Messages::OUTPOST_UNLOCKED)
        );

        return true;
    }

    /**
     * Looks an outpost up by name, reporting the miss to the sender.
     */
    private function resolveOutpost(
        CommandSender $sender,
        ?string $name
    ): ?Outpost {
        return $this->resolveNamed(
            $sender,
            $name,
            fn(string $id): ?Outpost => $this->plugin->getOutpostManager()->get($id),
            Messages::OUTPOST_UNKNOWN
        );
    }

    /**
     * @param list<string> $args
     */
    private function handleSetLabel(
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
                $outpost = $this->resolveOutpost($player, $args[1] ?? null);

                if ($outpost === null) {
                    return;
                }

                $position = TargetResolver::playerMiddle($player);

                $outpost->setLabelPosition($position);
                $outpost->spawn();

                $this->plugin->getOutpostManager()->save(
                    $outpost->getName()
                );

                $this->success(
                    $player,
                    Messages::get($this->plugin, Messages::OUTPOST_LABEL_MOVED, ['name' => $outpost->getName()])
                );
            }
        );
    }

    private function handleMenu(
        CommandSender $sender
    ): bool {
        if (!$sender instanceof Player) {
            $this->error($sender, Messages::get($this->plugin, Messages::OUTPOST_MENU_ONLY));

            return true;
        }

        (new OutpostAdminForm($this->plugin))->send($sender);

        return true;
    }

    private function handleHelp(
        CommandSender $sender
    ): bool {
        $lines = [
            Messages::get($this->plugin, Messages::OUTPOST_HELP_CREATE),
            Messages::get($this->plugin, Messages::OUTPOST_HELP_REMOVE),
            Messages::get($this->plugin, Messages::OUTPOST_HELP_OWNER),
            Messages::get($this->plugin, Messages::OUTPOST_HELP_RESET),
            Messages::get($this->plugin, Messages::OUTPOST_HELP_SETLABEL),
            Messages::get($this->plugin, Messages::OUTPOST_HELP_INFO),
            Messages::get($this->plugin, Messages::OUTPOST_HELP_LIST)
        ];

        $sender->sendMessage(
            $this->prefixed(
                Messages::get($this->plugin, Messages::OUTPOST_HELP_TITLE)
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