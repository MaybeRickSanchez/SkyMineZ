<?php

declare(strict_types=1);

namespace AM\SkyMineZ\command;

use AM\SkyMineZ\Main;
use AM\SkyMineZ\config\Messages;
use AM\SkyMineZ\useless\Positions;
use AM\SkyMineZ\warp\WarpManager;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;

/**
 * /warp - teleport to named server warps, plus admin management.
 *
 * Using a warp needs nothing but the player permission; creating, moving and
 * deleting need the admin permission. A warp whose world stopped loading reads
 * as missing with a clear message instead of teleporting nowhere.
 */
final class WarpCommand extends BaseCommand
{
    public function __construct(
        Main $plugin
    ) {
        parent::__construct(
            $plugin,
            'warp',
            'Teleport to server warps and manage them',
            '/warp [name|create|delete|move|list] ...',
            ['warps'],
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

        if ($sub === '' || !$this->isManagementWord($sub)) {
            return $this->handleTeleport($sender, $args[0] ?? null);
        }

        return match ($sub) {
            'create', 'new' => $this->handleCreate($sender, $args),
            'delete', 'remove' => $this->handleRemove($sender, $args),
            'move' => $this->handleMove($sender, $args),
            'list' => $this->handleList($sender),
            default => $this->handleTeleport($sender, $args[0] ?? null)
        };
    }

    private function isManagementWord(
        string $word
    ): bool {
        return in_array(
            $word,
            ['create', 'new', 'delete', 'remove', 'move', 'list'],
            true
        );
    }

    private function manager(): WarpManager
    {
        return $this->plugin->getWarpManager();
    }

    private function handleTeleport(
        CommandSender $sender,
        ?string $name
    ): bool {
        if (!$sender instanceof Player) {
            $this->error($sender, Messages::get($this->plugin, Messages::WARP_ONLY));

            return true;
        }

        if ($name === null || $name === '') {
            return $this->handleList($sender);
        }

        if (!$this->manager()->teleport($sender, $name)) {
            $this->error(
                $sender,
                Messages::get($this->plugin, Messages::WARP_MISSING_WORLD, ['name' => (string) $name])
            );

            return true;
        }

        $this->success($sender, Messages::get($this->plugin, Messages::WARPED, ['name' => $name]));

        return true;
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
            function(Player $player, array $args): void {
                if (!$player->hasPermission(Main::PERMISSION_ADMIN)) {
                    $this->error($player, Messages::get($this->plugin, Messages::COMMON_NO_PERMISSION));

                    return;
                }

                $name = $args[1] ?? null;

                if ($name === null || !self::isValidName($name)) {
                    $this->error(
                        $player,
                        Messages::get($this->plugin, Messages::WARP_CREATE_USAGE)
                    );

                    return;
                }

                try {
                    $this->manager()->create($name, $player->getLocation());
                } catch (\Throwable $exception) {
                    $this->fail($player, $exception);

                    return;
                }

                $this->success($player, Messages::get($this->plugin, Messages::WARP_CREATED, ['name' => $name]));
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
        if (!$sender->hasPermission(Main::PERMISSION_ADMIN)) {
            $this->error($sender, Messages::get($this->plugin, Messages::COMMON_NO_PERMISSION));

            return true;
        }

        $name = $args[1] ?? null;

        if ($name === null) {
            $this->error($sender, Messages::get($this->plugin, Messages::WARP_DELETE_USAGE));

            return true;
        }

        $this->manager()->remove($name)
            ? $this->success($sender, Messages::get($this->plugin, Messages::WARP_DELETED, ['name' => $name]))
            : $this->error($sender, Messages::get($this->plugin, Messages::WARP_NOT_FOUND, ['name' => $name]));

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
            function(Player $player, array $args): void {
                if (!$player->hasPermission(Main::PERMISSION_ADMIN)) {
                    $this->error($player, Messages::get($this->plugin, Messages::COMMON_NO_PERMISSION));

                    return;
                }

                $name = $args[1] ?? null;

                if ($name === null) {
                    $this->error($player, Messages::get($this->plugin, Messages::WARP_MOVE_USAGE));

                    return;
                }

                $this->manager()->move($name, $player->getLocation())
                    ? $this->success($player, Messages::get($this->plugin, Messages::WARP_MOVED, ['name' => $name]))
                    : $this->error($player, Messages::get($this->plugin, Messages::WARP_NOT_FOUND, ['name' => $name]));
            }
        );
    }

    private function handleList(
        CommandSender $sender
    ): bool {
        $warps = $this->manager()->getAll();

        if ($warps === []) {
            $this->info($sender, Messages::get($this->plugin, Messages::WARP_NONE));

            return true;
        }

        $sender->sendMessage($this->prefixed(Messages::get($this->plugin, Messages::WARP_LIST_TITLE)));

        foreach ($warps as $name => $position) {
            $sender->sendMessage(
                $this->prefixed('§f' . $name . ' §8| §7' . Positions::describe($position))
            );
        }

        return true;
    }
}