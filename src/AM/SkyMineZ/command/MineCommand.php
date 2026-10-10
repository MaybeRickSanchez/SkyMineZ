<?php

declare(strict_types=1);

namespace AM\SkyMineZ\command;

use AM\SkyMineZ\Main;
use AM\SkyMineZ\config\Messages;
use AM\SkyMineZ\mine\MineAdminForm;
use AM\SkyMineZ\mine\Mine;
use AM\SkyMineZ\mine\MineBlock;
use AM\SkyMineZ\useless\NumberFormatter;
use AM\SkyMineZ\useless\Positions;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use pocketmine\world\Position;

/**
 * /mine - creates mines, defines their block list and controls the reset timer.
 *
 * The cuboid comes from the player's pos1/pos2 selection (see
 * {@link SelectionManager}), so `/skymine pos1` and `/skymine pos2` are the
 * workflow for building one.
 */
final class MineCommand extends BaseCommand
{
    public function __construct(
        Main $plugin
    ) {
        parent::__construct(
            $plugin,
            'mine',
            'Manage SkyMineZ mines',
            '/mine <create|remove|list|info|block|pos1|pos2|setinterval|setlabel|reset> ...',
            ['mines']
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
            'block', 'blocks' => $this->handleBlock($sender, $args),
            'pos1', 'pos2' => $this->handlePosition($sender, $args),
            'setinterval' => $this->handleInterval($sender, $args),
            'setlabel' => $this->handleLabel($sender, $args),
            'reset' => $this->handleReset($sender, $args),
            'clearblocks' => $this->handleClearBlocks($sender, $args),
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
                        Messages::get($this->plugin, Messages::MINE_CREATE_USAGE)
                    );

                    return;
                }

                $region = $this->selectionRegion($player);

                if ($region === null) {
                    return;
                }

                [$pos1, $pos2] = $region;

                /*
                 * The label sits above the top corner of the box so it never
                 * ends up inside the ore. Use min+size/2 (not pos1+size/2):
                 * pos1 may be the max corner, which would push the label
                 * outside the box.
                 */
                $label = new Position(
                    min($pos1->getFloorX(), $pos2->getFloorX()) + (
                        (int) abs(
                            $pos2->getFloorX() - $pos1->getFloorX()
                        ) / 2
                    ),
                    max(
                        $pos1->getFloorY(),
                        $pos2->getFloorY()
                    ) + 3,
                    min($pos1->getFloorZ(), $pos2->getFloorZ()) + (
                        (int) abs(
                            $pos2->getFloorZ() - $pos1->getFloorZ()
                        ) / 2
                    ),
                    $pos1->getWorld()
                );

                try {
                    $mine = $this->plugin->getMineManager()->create(
                        $name,
                        $pos1,
                        $pos2,
                        $label
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
                    Messages::get($this->plugin, Messages::MINE_CREATED, ['name' => $name, 'blocks' => $mine->getMineBox()->getVolume()])
                );

                $player->sendMessage(
                    $this->prefixed(
                        Messages::get($this->plugin, Messages::MINE_ADD_BLOCKS_HINT, ['name' => $name])
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
                Messages::get($this->plugin, Messages::MINE_REMOVE_USAGE)
            );

            return true;
        }

        $removed = $this->plugin->getMineManager()->remove(
            $name
        );

        $removed
            ? $this->success(
                $sender,
                Messages::get($this->plugin, Messages::MINE_REMOVED, ['name' => $name])
            )
            : $this->error(
                $sender,
                Messages::get($this->plugin, Messages::MINE_UNKNOWN, ['name' => $name])
            );

        return true;
    }

    private function handleList(
        CommandSender $sender
    ): bool {
        $manager = $this->plugin->getMineManager();

        if ($manager->count() === 0) {
            $this->info(
                $sender,
                Messages::get($this->plugin, Messages::MINE_NONE)
            );

            return true;
        }

        $sender->sendMessage(
            $this->prefixed(
                Messages::get($this->plugin, Messages::MINE_LIST_TITLE, ['count' => $manager->count()])
            )
        );

        foreach (
            $manager->getAll() as $name => $mine
        ) {
            $sender->sendMessage(
                $this->prefixed(
                    Messages::get($this->plugin, Messages::MINE_LIST_ROW,
                        [
                            'name' => $name,
                            'volume' => $mine->getMineBox()->getVolume(),
                            'types' => count($mine->getBlocks()),
                            'interval' => $mine->getResetInterval() > 0 ? 'every ' . NumberFormatter::duration($mine->getResetInterval()) : 'manual'
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
        $mine = $this->resolveMine($sender, $args[1] ?? null);

        if ($mine === null) {
            return true;
        }

        $box = $mine->getMineBox();

        $sender->sendMessage(
            $this->prefixed(
                Messages::get($this->plugin, Messages::MINE_INFO_TITLE, ['name' => $mine->getName()])
            )
        );
        $sender->sendMessage(
            $this->prefixed(
                Messages::get($this->plugin, Messages::MINE_INFO_WORLD, ['world' => $mine->getWorld()->getFolderName()])
            )
        );
        $sender->sendMessage(
            $this->prefixed(
                Messages::get($this->plugin, Messages::MINE_INFO_SIZE, ['size' => $box->getSizeX() . 'x' . $box->getSizeY() . 'x' . $box->getSizeZ(), 'volume' => $box->getVolume()])
            )
        );
        $sender->sendMessage(
            $this->prefixed(
                Messages::get($this->plugin, Messages::MINE_INFO_INTERVAL, ['interval' => $mine->getResetInterval() > 0 ? NumberFormatter::duration($mine->getResetInterval()) : 'manual only'])
            )
        );
        $sender->sendMessage(
            $this->prefixed(
                Messages::get($this->plugin, Messages::MINE_INFO_STATE, ['state' => $mine->isFilling() ? 'refilling' : 'idle', 'percent' => $mine->getTotalPercent()])
            )
        );

        return true;
    }

    /**
     * @param list<string> $args
     */
    private function handlePosition(
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
                $which = strtolower(
                    $args[0] ?? 'pos1'
                );

                $position = TargetResolver::playerMiddle($player);

                if ($which === 'pos1') {
                    $this->plugin->getSelectionManager()->setPos1(
                        $player,
                        $position
                    );

                    $this->success(
                        $player,
                        Messages::get($this->plugin, Messages::SKYMINE_POS_SET, ['which' => 'pos1', 'world' => $position->getWorld()->getFolderName(), 'x' => $position->getFloorX(), 'y' => $position->getFloorY(), 'z' => $position->getFloorZ()])
                    );

                    return;
                }

                $this->plugin->getSelectionManager()->setPos2(
                    $player,
                    $position
                );

                $this->success(
                    $player,
                    Messages::get($this->plugin, Messages::SKYMINE_POS_SET, ['which' => 'pos2', 'world' => $position->getWorld()->getFolderName(), 'x' => $position->getFloorX(), 'y' => $position->getFloorY(), 'z' => $position->getFloorZ()])
                );
            }
        );
    }

    /**
     * @param list<string> $args
     */
    private function handleInterval(
        CommandSender $sender,
        array $args
    ): bool {
        $mine = $this->plugin->getMineManager()->get(
            $args[1] ?? ''
        );

        $seconds = $args[2] ?? null;

        if (
            $mine === null
            || $seconds === null
            || !is_numeric($seconds)
        ) {
            $this->error(
                $sender,
                Messages::get($this->plugin, Messages::MINE_INTERVAL_USAGE)
            );

            return true;
        }

        $mine->setResetInterval(
            (int) $seconds
        );

        $this->plugin->getMineManager()->save(
            $mine->getName()
        );

        $this->success(
            $sender,
            Messages::get($this->plugin, Messages::MINE_INTERVAL_SET_TO, ['value' => $mine->getResetInterval() > 0 ? NumberFormatter::duration($mine->getResetInterval()) : 'manual only'])
        );

        return true;
    }

    /**
     * @param list<string> $args
     */
    private function handleLabel(
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
                $mine = $this->resolveMine($player, $args[1] ?? null);

                if ($mine === null) {
                    return;
                }

                $position = TargetResolver::playerMiddle($player);

                $mine->getInfo()->setPosition($position);
                $mine->getInfo()->spawn();

                $this->plugin->getMineManager()->save(
                    $mine->getName()
                );

                $this->success(
                    $player,
                    Messages::get($this->plugin, Messages::MINE_LABEL_SET, ['where' => Positions::describe($position)])
                );
            }
        );
    }

    /**
     * @param list<string> $args
     */
    private function handleReset(
        CommandSender $sender,
        array $args
    ): bool {
        $manager = $this->plugin->getMineManager();

        $name = $args[1] ?? 'all';

        if (
            strcasecmp(
                $name,
                'all'
            ) === 0
        ) {
            $started = $manager->resetAll(
                'manual'
            );

            $started === []
                ? $this->error(
                    $sender,
                    Messages::get($this->plugin, Messages::MINE_RESET_NONE)
                )
                : $this->success(
                    $sender,
                    Messages::get($this->plugin, Messages::MINE_RESETTING, ['count' => count($started)])
                );

            return true;
        }

        $mine = $this->resolveMine($sender, $name);

        if ($mine === null) {
            return true;
        }

        if (!$manager->reset(
            $name,
            'manual'
        )) {
            $this->error(
                $sender,
                Messages::get($this->plugin, Messages::MINE_REFILL_BUSY)
            );

            return true;
        }

        $this->success(
            $sender,
            Messages::get($this->plugin, Messages::MINE_REFILLING_NAMED, ['name' => $name])
        );

        return true;
    }

    /**
     * @param list<string> $args
     */
    private function handleClearBlocks(
        CommandSender $sender,
        array $args
    ): bool {
        $mine = $this->resolveMine($sender, $args[1] ?? null);

        if ($mine === null) {
            return true;
        }

        $mine->clearBlocks();

        $this->plugin->getMineManager()->save(
            $mine->getName()
        );

        $this->success(
            $sender,
            Messages::get($this->plugin, Messages::MINE_BLOCKS_CLEARED)
        );

        return true;
    }

    /**
     * /mine block <add|remove|list> ...
     *
     * @param list<string> $args
     */
    private function handleBlock(
        CommandSender $sender,
        array $args
    ): bool {
        $action = strtolower(
            $args[1] ?? 'list'
        );

        $mine = $this->plugin->getMineManager()->get(
            $args[2] ?? ''
        );

        if ($mine === null) {
            $this->error(
                $sender,
                Messages::get($this->plugin, Messages::MINE_BLOCK_USAGE)
            );

            return true;
        }

        switch ($action) {
            case 'add':
                return $this->blockAdd(
                    $sender,
                    $mine,
                    $args
                );

            case 'remove':
                return $this->blockRemove(
                    $sender,
                    $mine,
                    $args
                );

            case 'list':
                return $this->blockList(
                    $sender,
                    $mine
                );

            default:
                $this->error(
                    $sender,
                    Messages::get($this->plugin, Messages::MINE_BLOCK_BAD)
                );

                return true;
        }
    }

    /**
     * @param list<string> $args
     */
    private function blockAdd(
        CommandSender $sender,
        Mine $mine,
        array $args
    ): bool {
        $blockName = $args[3] ?? null;
        $percent = isset($args[4]) && is_numeric($args[4])
            ? (int) $args[4]
            : -1;

        if (
            $blockName === null
            || $percent < 1
            || $percent > 100
        ) {
            $this->error(
                $sender,
                Messages::get($this->plugin, Messages::MINE_BLOCK_ADD_USAGE)
            );

            return true;
        }

        $block = BlockParser::parse($blockName);

        if ($block === null) {
            $this->error(
                $sender,
                Messages::get($this->plugin, Messages::MINE_BLOCK_UNKNOWN, ['block' => $blockName, 'suggestions' => implode(', ', BlockParser::suggestions())])
            );

            return true;
        }

        try {
            $mine->addBlock(
                new MineBlock(
                    $percent,
                    $block
                )
            );
        } catch (\InvalidArgumentException $exception) {
            $this->fail(
                $sender,
                $exception
            );

            return true;
        }

        $this->plugin->getMineManager()->save(
            $mine->getName()
        );

        $this->success(
            $sender,
            Messages::get($this->plugin, Messages::MINE_BLOCK_ADDED_FULL, ['percent' => $percent, 'block' => $block->getName(), 'mine' => $mine->getName()])
        );

        if ($mine->getTotalPercent() !== 100) {
            $this->info(
                $sender,
                Messages::get($this->plugin, Messages::MINE_WEIGHT_TOTAL, ['total' => $mine->getTotalPercent()])
            );
        }

        return true;
    }

    /**
     * @param list<string> $args
     */
    private function blockRemove(
        CommandSender $sender,
        Mine $mine,
        array $args
    ): bool {
        $index = isset($args[3]) && is_numeric($args[3])
            ? (int) $args[3]
            : -1;

        if (
            $index < 0
            || !isset($mine->getBlocks()[$index])
        ) {
            $this->error(
                $sender,
                Messages::get($this->plugin, Messages::MINE_INDEX_RANGE)
            );

            return true;
        }

        $mine->removeBlock($index);

        $this->plugin->getMineManager()->save(
            $mine->getName()
        );

        $this->success(
            $sender,
            Messages::get($this->plugin, Messages::MINE_BLOCK_REMOVED)
        );

        return true;
    }

    private function blockList(
        CommandSender $sender,
        Mine $mine
    ): bool {
        $blocks = $mine->getBlocks();

        if ($blocks === []) {
            $this->info(
                $sender,
                Messages::get($this->plugin, Messages::MINE_NO_BLOCKS, ['mine' => $mine->getName()])
            );

            return true;
        }

        $sender->sendMessage(
            $this->prefixed(
                Messages::get($this->plugin, Messages::MINE_BLOCKS_TITLE, ['mine' => $mine->getName()])
            )
        );

        foreach (
            $blocks as $index => $entry
        ) {
            $sender->sendMessage(
                $this->prefixed(
                    Messages::get($this->plugin, Messages::MINE_BLOCK_ROW, ['index' => $index, 'name' => $entry->getName(), 'percent' => $entry->getPercent()])
                )
            );
        }

        return true;
    }

    /**
     * Looks a mine up by name, reporting the miss to the sender.
     */
    private function resolveMine(
        CommandSender $sender,
        ?string $name
    ): ?Mine {
        return $this->resolveNamed(
            $sender,
            $name,
            fn(string $id): ?Mine => $this->plugin->getMineManager()->get($id),
            Messages::MINE_UNKNOWN
        );
    }

    private function handleMenu(
        CommandSender $sender
    ): bool {
        if (!$sender instanceof Player) {
            $this->error($sender, Messages::get($this->plugin, Messages::MINE_MENU_ONLY));

            return true;
        }

        (new MineAdminForm($this->plugin))->send($sender);

        return true;
    }

    private function handleHelp(
        CommandSender $sender
    ): bool {
        $lines = [
            Messages::get($this->plugin, Messages::MINE_HELP_POS1),
            Messages::get($this->plugin, Messages::MINE_HELP_POS2),
            Messages::get($this->plugin, Messages::MINE_HELP_CREATE),
            Messages::get($this->plugin, Messages::MINE_HELP_BLOCK_ADD),
            Messages::get($this->plugin, Messages::MINE_HELP_BLOCK_REMOVE),
            Messages::get($this->plugin, Messages::MINE_HELP_BLOCK_LIST),
            Messages::get($this->plugin, Messages::MINE_HELP_CLEAR),
            Messages::get($this->plugin, Messages::MINE_HELP_INTERVAL),
            Messages::get($this->plugin, Messages::MINE_HELP_LABEL),
            Messages::get($this->plugin, Messages::MINE_HELP_RESET),
            Messages::get($this->plugin, Messages::MINE_HELP_INFO),
            Messages::get($this->plugin, Messages::MINE_HELP_LIST)
        ];

        $sender->sendMessage(
            $this->prefixed(
                Messages::get($this->plugin, Messages::MINE_HELP_TITLE)
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