<?php

declare(strict_types=1);

namespace AM\SkyMineZ\command;

use AM\SkyMineZ\Main;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use pocketmine\world\Position;

/**
 * Shared plumbing for every SkyMineZ command.
 *
 * Gives the subcommands a consistent reply format (prefixed, colour coded),
 * permission checks and the small parsing helpers that would otherwise be copied
 * into a dozen classes.
 */
abstract class BaseCommand extends Command
{
    public function __construct(
        protected Main $plugin,
        string $name,
        string $description,
        string $usage,
        array $aliases = [],
        string $permission = Main::PERMISSION_ADMIN
    ) {
        parent::__construct(
            $name,
            $description,
            $usage,
            $aliases
        );

        /*
         * PocketMine refuses to load a plugin whose commands have no permission
         * declared, and plugin.yml is the single place they are listed. Setting
         * it here too keeps Command::testPermission() and the manifest in sync.
         */
        $this->setPermission($permission);
    }

    /**
     * @param list<string> $args
     * @param callable(Player, list<string>): void $handler
     */
    protected function runPlayerSubCommand(
        CommandSender $sender,
        array $args,
        callable $handler
    ): bool {
        if (!$sender instanceof Player) {
            $sender->sendMessage(
                $this->prefixed(
                    \AM\SkyMineZ\config\Messages::get($this->plugin, \AM\SkyMineZ\config\Messages::COMMON_IN_GAME_ONLY)
                )
            );

            return true;
        }

        $handler(
            $sender,
            $args
        );

        return true;
    }

    protected function prefixed(
        string $message
    ): string {
        return $this->plugin->getConfigManager()->getPrefix()
            . $message;
    }

    protected function success(
        CommandSender $sender,
        string $message
    ): void {
        $sender->sendMessage(
            $this->prefixed('§a' . $message)
        );
    }

    protected function error(
        CommandSender $sender,
        string $message
    ): void {
        $sender->sendMessage(
            $this->prefixed('§c' . $message)
        );
    }

    protected function info(
        CommandSender $sender,
        string $message
    ): void {
        $sender->sendMessage(
            $this->prefixed('§7' . $message)
        );
    }

    /**
     * Reports a caught exception without dumping a stack trace into chat, which
     * is unreadable in-game and leaks internals.
     */
    protected function fail(
        CommandSender $sender,
        \Throwable $exception
    ): void {
        $this->error(
            $sender,
            $exception->getMessage()
        );

        $this->plugin->getLogger()->logException(
            $exception
        );
    }

    /**
     * Registry names (crates, mines, outposts, slappers): letters, digits,
     * underscore and dash, 1-32 chars. One canonical rule so a name accepted
     * by one command is never rejected by another.
     */
    protected static function isValidName(
        string $name
    ): bool {
        return preg_match(
            '/^[A-Za-z0-9_-]{1,32}$/',
            $name
        ) === 1;
    }

    /**
     * Looks a named object up and reports the miss, the shape every
     * resolveCrate/resolveMine/resolveOutpost/resolveSlapper/
     * resolveLeaderboard shares. Returns null after reporting.
     *
     * @template T of object
     *
     * @param callable(string): ?T $lookup
     *
     * @return ?T
     */
    protected function resolveNamed(
        CommandSender $sender,
        ?string $name,
        callable $lookup,
        string $unknownKey
    ): mixed {
        $entity = $name !== null && $name !== ''
            ? $lookup($name)
            : null;

        if ($entity === null) {
            $this->error(
                $sender,
                \AM\SkyMineZ\config\Messages::get(
                    $this->plugin,
                    $unknownKey,
                    ['name' => (string) ($name ?? '')]
                )
            );

            return null;
        }

        return $entity;
    }

    /**
     * Reads the shared pos1/pos2 selection, reporting when it is missing or
     * split across worlds. Returns null after reporting.
     *
     * @return array{Position, Position}|null
     */
    protected function selectionRegion(
        Player $player,
        string $missingKey = \AM\SkyMineZ\config\Messages::COMMON_SELECT_REGION
    ): ?array {
        $region = $this->plugin->getSelectionManager()->getRegion(
            $player
        );

        if ($region === null) {
            $this->error(
                $player,
                \AM\SkyMineZ\config\Messages::get(
                    $this->plugin,
                    $missingKey
                )
            );
        }

        return $region;
    }

    /**
     * @param list<string> $args
     */
    protected function joinArguments(
        array $args,
        int $from = 1
    ): string {
        return implode(
            ' ',
            array_slice(
                $args,
                $from
            )
        );
    }
}