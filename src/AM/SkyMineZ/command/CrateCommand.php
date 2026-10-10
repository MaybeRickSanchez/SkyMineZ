<?php

declare(strict_types=1);

namespace AM\SkyMineZ\command;

use AM\SkyMineZ\crate\Crate;
use AM\SkyMineZ\crate\CrateAdminForm;
use AM\SkyMineZ\crate\Key;
use AM\SkyMineZ\crate\Reward;
use AM\SkyMineZ\Main;
use AM\SkyMineZ\config\Messages;
use AM\SkyMineZ\useless\Items;
use AM\SkyMineZ\useless\NumberFormatter;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;

/**
 * /crate - creates crates, hands out keys and edits the reward list.
 *
 * Every subcommand takes a crate *name*; the crate's world position is stored on
 * creation and can be moved afterwards with `/crate move`.
 */
final class CrateCommand extends BaseCommand
{
    public function __construct(
        Main $plugin
    ) {
        parent::__construct(
            $plugin,
            'crate',
            'Manage SkyMineZ crates',
            '/crate <create|remove|move|list|givekey|open|reward> ...',
            ['crates']
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
            'create' => $this->handleCreate($sender, $args),
            'remove', 'delete' => $this->handleRemove($sender, $args),
            'move', 'tp' => $this->handleMove($sender, $args),
            'list' => $this->handleList($sender),
            'givekey', 'key' => $this->handleGiveKey($sender, $args),
            'open' => $this->handleOpen($sender, $args),
            'reward' => $this->handleReward($sender, $args),
            'color' => $this->handleColor($sender, $args),
            'menu' => $this->handleMenu($sender),
            'save' => $this->handleSave($sender),
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

                if ($name === null || $name === '') {
                    $this->error(
                        $player,
                        Messages::get($this->plugin, Messages::CRATE_CREATE_USAGE)
                    );

                    return;
                }

                if (!self::isValidName($name)) {
                    $this->error(
                        $player,
                        Messages::get($this->plugin, Messages::CRATE_NAME_RULE)
                    );

                    return;
                }

                // Canonical "here": the player's middle position, so the
                // command works while looking at the sky. The Position Wand
                // (pos1/pos2) is untouched and remains the way to pick
                // cuboid corners.
                $position = TargetResolver::playerMiddle($player);

                $crate = $this->plugin->getCrateManager()->create(
                    $name,
                    $position,
                    $position->getWorld()
                );

                /*
                 * A fresh crate accepts its own key id straight away, so the loop
                 * "create -> give key -> open" works without any extra setup.
                 */
                $crate->addKey($name);

                $this->plugin->getCrateManager()->save(
                    $name
                );

                $this->success(
                    $player,
                    Messages::get($this->plugin, Messages::CRATE_CREATED, ['name' => $name])
                );

                $player->sendMessage(
                    $this->prefixed(
                        Messages::get($this->plugin, Messages::CRATE_GIVE_HINT, ['name' => $name])
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
                Messages::get($this->plugin, Messages::CRATE_REMOVE_USAGE)
            );

            return true;
        }

        try {
            $removed = $this->plugin->getCrateManager()->remove(
                $name
            );
        } catch (\Throwable $exception) {
            $this->fail(
                $sender,
                $exception
            );

            return true;
        }

        $removed
            ? $this->success(
                $sender,
                Messages::get($this->plugin, Messages::CRATE_REMOVED, ['name' => $name])
            )
            : $this->error(
                $sender,
                Messages::get($this->plugin, Messages::CRATE_UNKNOWN, ['name' => $name])
            );

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
                        Messages::get($this->plugin, Messages::CRATE_MOVE_USAGE)
                    );

                    return;
                }

                $crate = $this->resolveCrate($player, $name);

                if ($crate === null) {
                    return;
                }

                if ($crate->isBusy() || $crate->hasPreviewViewers()) {
                    $this->error(
                        $player,
                        Messages::get($this->plugin, Messages::CRATE_IN_USE)
                    );

                    return;
                }

                // Same canonical position as create. Wand selection is separate.
                $position = TargetResolver::playerMiddle($player);

                $color = $crate->getColor();
                $keys = $crate->getKeys();
                $rewards = $crate->getRewards();

                /*
                 * Moving is a remove plus a create so the position index stays
                 * correct; doing it by hand would leave a stale entry behind and
                 * the old block would stop being protected.
                 */
                $this->plugin->getCrateManager()->remove(
                    $name
                );

                $this->plugin->getCrateManager()->create(
                    $name,
                    $position,
                    $position->getWorld()
                );

                $recreated = $this->plugin->getCrateManager()->getCrate(
                    $name
                );

                if ($recreated !== null) {
                    $recreated->setColor($color);

                    foreach ($keys as $keyId) {
                        $recreated->addKey($keyId);
                    }

                    foreach ($rewards as $reward) {
                        $recreated->addRewardObject(
                            clone $reward
                        );
                    }
                }

                $this->plugin->getCrateManager()->save(
                    $name
                );

                $this->success(
                    $player,
                    Messages::get($this->plugin, Messages::CRATE_MOVED, ['name' => $name])
                );
            }
        );
    }

    private function handleList(
        CommandSender $sender
    ): bool {
        $manager = $this->plugin->getCrateManager();

        if ($manager->count() === 0) {
            $this->info(
                $sender,
                Messages::get($this->plugin, Messages::CRATE_NONE)
            );

            return true;
        }

        $sender->sendMessage(
            $this->prefixed(
                Messages::get($this->plugin, Messages::CRATE_LIST_TITLE, ['count' => $manager->count()])
            )
        );

        foreach (
            $manager->getCrates() as $name => $crate
        ) {
            $position = $crate->getPosition();

            $sender->sendMessage(
                $this->prefixed(
                    Messages::get($this->plugin, Messages::CRATE_LIST_ROW,
                        [
                            'name' => $name,
                            'world' => $crate->getWorld()->getFolderName(),
                            'x' => $position->getFloorX(),
                            'y' => $position->getFloorY(),
                            'z' => $position->getFloorZ(),
                            'rewards' => count($crate->getRewards()),
                            'keys' => count($crate->getKeys())
                        ])
                )
            );
        }

        return true;
    }

    /**
     * @param list<string> $args
     */
    private function handleGiveKey(
        CommandSender $sender,
        array $args
    ): bool {
        $target = $this->plugin->getServer()->getPlayerExact(
            $args[1] ?? ''
        );

        $crateName = $args[2] ?? null;

        if ($target === null) {
            $this->error(
                $sender,
                Messages::get($this->plugin, Messages::COMMON_PLAYER_OFFLINE)
            );

            return true;
        }

        if ($crateName === null) {
            $this->error(
                $sender,
                Messages::get($this->plugin, Messages::CRATE_GIVEKEY_USAGE)
            );

            return true;
        }

        $crate = $this->resolveCrate($sender, $crateName);

        if ($crate === null) {
            return true;
        }

        $amount = max(
            1,
            min(
                64,
                (int) ($args[3] ?? 1)
            )
        );

        $keyId = $crate->getKeys()[0] ?? $crate->getName();

        $target->getInventory()->addItem(
            Key::create(
                $keyId,
                "§d" . $crate->getName() . " Key",
                $amount
            )
        );

        $this->success(
            $sender,
            Messages::get($this->plugin, Messages::CRATE_KEY_GIVEN_FULL, ['amount' => $amount, 'key' => $keyId, 'player' => $target->getName()])
        );

        return true;
    }

    /**
     * @param list<string> $args
     */
    private function handleOpen(
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
                        Messages::get($this->plugin, Messages::CRATE_OPEN_USAGE)
                    );

                    return;
                }

                $crate = $this->resolveCrate($player, $name);

                if ($crate === null) {
                    return;
                }

                if (!$crate->showPreview($player)) {
                    return;
                }

                $this->info(
                    $player,
                    Messages::get($this->plugin, Messages::CRATE_PREVIEW_HINT, ['name' => $name])
                );
            }
        );
    }

    /**
     * @param list<string> $args
     */
    private function handleColor(
        CommandSender $sender,
        array $args
    ): bool {
        $name = $args[1] ?? null;
        $color = isset($args[2]) ? Crate::colorFromName($args[2]) : null;

        if ($name === null || $color === null) {
            $this->error(
                $sender,
                Messages::get($this->plugin, Messages::CRATE_COLOR_USAGE, ['colors' => implode(', ', Crate::colorNames())])
            );

            return true;
        }

        $crate = $this->resolveCrate($sender, $name);

        if ($crate === null) {
            return true;
        }

        $crate->setColor($color);
        $this->plugin->getCrateManager()->save($name);

        $this->success($sender, Messages::get($this->plugin, Messages::CRATE_COLOR_SET, ['name' => $name, 'color' => strtolower($color->name)]));

        return true;
    }

    private function handleMenu(
        CommandSender $sender
    ): bool {
        if (!$sender instanceof Player) {
            $this->error($sender, Messages::get($this->plugin, Messages::CRATE_MENU_ONLY));

            return true;
        }

        (new CrateAdminForm($this->plugin))->send($sender);

        return true;
    }

    private function handleSave(
        CommandSender $sender
    ): bool {
        try {
            $this->plugin->getCrateManager()->saveAll();
        } catch (\Throwable $exception) {
            $this->fail(
                $sender,
                $exception
            );

            return true;
        }

        $this->success(
            $sender,
            Messages::get($this->plugin, Messages::CRATE_SAVED)
        );

        return true;
    }

    /**
     * /crate reward <add|remove|list|weight|type> ...
     *
     * @param list<string> $args
     */
    private function handleReward(
        CommandSender $sender,
        array $args
    ): bool {
        $action = strtolower(
            $args[1] ?? 'list'
        );

        $crateName = $args[2] ?? null;

        if ($crateName === null) {
            $this->error(
                $sender,
                Messages::get($this->plugin, Messages::CRATE_REWARD_USAGE)
            );

            return true;
        }

        $crate = $this->resolveCrate($sender, $crateName);

        if ($crate === null) {
            return true;
        }

        switch ($action) {
            case 'add':
                return $this->rewardAdd(
                    $sender,
                    $crate,
                    $args
                );

            case 'remove':
                return $this->rewardRemove(
                    $sender,
                    $crate,
                    $args
                );

            case 'list':
                return $this->rewardList(
                    $sender,
                    $crate
                );

            case 'weight':
                return $this->rewardWeight(
                    $sender,
                    $crate,
                    $args
                );

            case 'type':
                return $this->rewardType(
                    $sender,
                    $crate,
                    $args
                );

            default:
                $this->error(
                    $sender,
                    Messages::get($this->plugin, Messages::CRATE_REWARD_BAD_ACTION)
                );

                return true;
        }
    }

    /**
     * @param list<string> $args
     */
    private function rewardAdd(
        CommandSender $sender,
        Crate $crate,
        array $args
    ): bool {
        $itemSpec = $args[3] ?? null;

        if ($itemSpec === null) {
            $this->error(
                $sender,
                Messages::get($this->plugin, Messages::CRATE_REWARD_ADD_USAGE)
            );

            return true;
        }

        $item = Items::parse($itemSpec);

        if ($item === null) {
            $this->error(
                $sender,
                Messages::get($this->plugin, Messages::CRATE_REWARD_BAD_ITEM, ['item' => $itemSpec])
            );

            return true;
        }

        $type = $args[4] ?? Reward::TYPE_COMMON;

        try {
            $weight = isset($args[5]) && is_numeric($args[5])
                ? (float) $args[5]
                : Reward::getDefaultWeightForType($type);
        } catch (\InvalidArgumentException) {
            $this->error(
                $sender,
                Messages::get($this->plugin, Messages::CRATE_REWARD_BAD_TYPE, ['type' => $type, 'types' => implode(', ', array_keys(Reward::types()))])
            );

            return true;
        }

        try {
            $crate->addRewardByType(
                $item,
                $type
            );

            $index = count(
                $crate->getRewards()
            ) - 1;

            if ($weight > 0.0) {
                $crate->setRewardWeight(
                    $index,
                    $weight
                );
            }
        } catch (\InvalidArgumentException $exception) {
            $this->fail(
                $sender,
                $exception
            );

            return true;
        }

        $this->plugin->getCrateManager()->save(
            $crate->getName()
        );

        $this->success(
            $sender,
            Messages::get($this->plugin, Messages::CRATE_REWARD_ADDED2,
                [
                    'count' => $item->getCount(),
                    'item' => $item->getName(),
                    'crate' => $crate->getName(),
                    'index' => $index
                ])
        );

        return true;
    }

    /**
     * @param list<string> $args
     */
    private function rewardRemove(
        CommandSender $sender,
        Crate $crate,
        array $args
    ): bool {
        $index = isset($args[3]) && is_numeric($args[3])
            ? (int) $args[3]
            : -1;

        if (
            $index < 0
            || !isset($crate->getRewards()[$index])
        ) {
            $this->error(
                $sender,
                Messages::get($this->plugin, Messages::CRATE_REWARD_RANGE)
            );

            return true;
        }

        $crate->removeReward($index);

        $this->plugin->getCrateManager()->save(
            $crate->getName()
        );

        $this->success(
            $sender,
            Messages::get($this->plugin, Messages::CRATE_REWARD_REMOVED)
        );

        return true;
    }

    private function rewardList(
        CommandSender $sender,
        Crate $crate
    ): bool {
        $rewards = $crate->getRewards();

        if ($rewards === []) {
            $this->info(
                $sender,
                Messages::get($this->plugin, Messages::CRATE_REWARD_NONE, ['crate' => $crate->getName()])
            );

            return true;
        }

        $sender->sendMessage(
            $this->prefixed(
                Messages::get($this->plugin, Messages::CRATE_REWARD_TITLE, ['crate' => $crate->getName()])
            )
        );

        foreach (
            $rewards as $index => $reward
        ) {
            $item = $reward->getItem();

            $sender->sendMessage(
                $this->prefixed(
                    Messages::get($this->plugin, Messages::CRATE_REWARD_ROW,
                        [
                            'index' => $index,
                            'type' => $reward->getType(),
                            'count' => $item->getCount(),
                            'item' => $item->getName(),
                            'chance' => NumberFormatter::trim($crate->getRewardChance($index) ?? 0.0),
                            'weight' => NumberFormatter::trim($reward->getWeight())
                        ])
                )
            );
        }

        return true;
    }

    /**
     * @param list<string> $args
     */
    private function rewardWeight(
        CommandSender $sender,
        Crate $crate,
        array $args
    ): bool {
        $index = isset($args[3]) && is_numeric($args[3])
            ? (int) $args[3]
            : -1;
        $weight = isset($args[4]) && is_numeric($args[4])
            ? (float) $args[4]
            : -1.0;

        if (
            $index < 0
            || $weight <= 0.0
        ) {
            $this->error(
                $sender,
                Messages::get($this->plugin, Messages::CRATE_REWARD_WEIGHT_USAGE)
            );

            return true;
        }

        try {
            $crate->setRewardWeight(
                $index,
                $weight
            );
        } catch (\Throwable $exception) {
            $this->fail(
                $sender,
                $exception
            );

            return true;
        }

        $this->plugin->getCrateManager()->save(
            $crate->getName()
        );

        $this->success(
            $sender,
            Messages::get($this->plugin, Messages::CRATE_WEIGHT_SET)
        );

        return true;
    }

    /**
     * @param list<string> $args
     */
    private function rewardType(
        CommandSender $sender,
        Crate $crate,
        array $args
    ): bool {
        $index = isset($args[3]) && is_numeric($args[3])
            ? (int) $args[3]
            : -1;
        $type = $args[4] ?? '';

        if (
            $index < 0
            || $type === ''
        ) {
            $this->error(
                $sender,
                Messages::get($this->plugin, Messages::CRATE_REWARD_TYPE_USAGE)
            );

            return true;
        }

        try {
            $crate->setRewardType(
                $index,
                $type
            );
        } catch (\Throwable $exception) {
            $this->fail(
                $sender,
                $exception
            );

            return true;
        }

        $this->plugin->getCrateManager()->save(
            $crate->getName()
        );

        $this->success(
            $sender,
            Messages::get($this->plugin, Messages::CRATE_TYPE_SET)
        );

        return true;
    }

    /**
     * Looks a crate up by name, reporting the miss to the sender.
     *
     * Every subcommand that takes a crate name funnels through here so the
     * "not found" message stays identical everywhere.
     */
    private function resolveCrate(
        CommandSender $sender,
        ?string $name
    ): ?Crate {
        return $this->resolveNamed(
            $sender,
            $name,
            fn(string $id): ?Crate => $this->plugin->getCrateManager()->getCrate($id),
            Messages::CRATE_UNKNOWN
        );
    }

    private function handleHelp(
        CommandSender $sender
    ): bool {
        $lines = [
            Messages::get($this->plugin, Messages::CRATE_HELP_CREATE),
            Messages::get($this->plugin, Messages::CRATE_HELP_REMOVE),
            Messages::get($this->plugin, Messages::CRATE_HELP_MOVE),
            Messages::get($this->plugin, Messages::CRATE_HELP_LIST),
            Messages::get($this->plugin, Messages::CRATE_HELP_GIVEKEY),
            Messages::get($this->plugin, Messages::CRATE_HELP_OPEN),
            Messages::get($this->plugin, Messages::CRATE_HELP_REWARD_ADD),
            Messages::get($this->plugin, Messages::CRATE_HELP_REWARD_REMOVE),
            Messages::get($this->plugin, Messages::CRATE_HELP_REWARD_LIST),
            Messages::get($this->plugin, Messages::CRATE_HELP_REWARD_WEIGHT),
            Messages::get($this->plugin, Messages::CRATE_HELP_REWARD_TYPE),
            Messages::get($this->plugin, Messages::CRATE_HELP_COLOR),
            Messages::get($this->plugin, Messages::CRATE_HELP_MENU),
            Messages::get($this->plugin, Messages::CRATE_HELP_SAVE)
        ];

        $sender->sendMessage(
            $this->prefixed(
                Messages::get($this->plugin, Messages::CRATE_HELP_TITLE)
            )
        );

        foreach (
            $lines as $line
        ) {
            $sender->sendMessage(
                $this->prefixed(
                    $line
                )
            );
        }

        return true;
    }
}