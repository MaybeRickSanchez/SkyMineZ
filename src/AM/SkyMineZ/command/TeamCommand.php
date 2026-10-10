<?php

declare(strict_types=1);

namespace AM\SkyMineZ\command;

use AM\SkyMineZ\Main;
use AM\SkyMineZ\config\Messages;
use AM\SkyMineZ\team\TeamManager;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;

/**
 * /team - create teams, manage members, and run duels.
 *
 * Invites and duels need no permission beyond playing: owners invite and kick,
 * challenged owners accept or deny, and everything else just works. The rules
 * themselves (one duel per team, explicit accept, safe return) live in
 * {@link TeamManager}, so the command layer stays thin.
 */
final class TeamCommand extends BaseCommand
{
    public function __construct(
        Main $plugin
    ) {
        parent::__construct(
            $plugin,
            'team',
            'Create teams, invite players and duel other teams',
            '/team <create|info|list|invite|accept|deny|leave|kick|duel> ...',
            ['teams'],
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

        $sub = strtolower($args[0] ?? 'menu');

        return match ($sub) {
            'menu' => $this->handleMenu($sender),
            'create', 'new' => $this->handleCreate($sender, $args),
            'info' => $this->handleInfo($sender, $args),
            'list' => $this->handleList($sender),
            'invite' => $this->handleInvite($sender, $args),
            'accept' => $this->handleAccept($sender),
            'deny' => $this->handleDeny($sender),
            'leave' => $this->handleLeave($sender),
            'kick' => $this->handleKick($sender, $args),
            'disband', 'delete', 'disolve' => $this->handleDisband($sender),
            'arena' => $this->handleArena($sender, $args),
            'duel' => $this->handleDuel($sender, $args),
            default => $this->handleHelp($sender)
        };
    }

    private function manager(): TeamManager
    {
        return $this->plugin->getTeamManager();
    }

    /**
     * Notes when no duel arena is set, so the fight happens in place. Shared
     * by challenge and accept, which otherwise repeat the same check.
     *
     * @param array{a: mixed, b: mixed} $arena
     */
    private function noteArenaFallback(
        Player $player,
        array $arena
    ): void {
        if ($arena['a'] === null && $arena['b'] === null) {
            $this->info($player, Messages::get($this->plugin, Messages::TEAM_ARENA_FIGHT_HERE));
        }
    }

    private function handleMenu(
        CommandSender $sender
    ): bool {
        if (!$sender instanceof Player) {
            $this->error($sender, Messages::get($this->plugin, Messages::TEAM_MENU_ONLY));

            return true;
        }

        (new \AM\SkyMineZ\team\TeamMenuForm($this->plugin))->send($sender);

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
                $name = $args[1] ?? null;

                if ($name === null || !self::isValidName($name)) {
                    $this->error(
                        $player,
                        Messages::get($this->plugin, Messages::TEAM_CREATE_USAGE)
                    );

                    return;
                }

                try {
                    $this->manager()->create($name, $player->getName());
                } catch (\Throwable $exception) {
                    $this->fail($player, $exception);

                    return;
                }

                $this->success($player, Messages::get($this->plugin, Messages::TEAM_CREATED, ['name' => $name]));
            }
        );
    }

    /**
     * @param list<string> $args
     */
    private function handleInfo(
        CommandSender $sender,
        array $args
    ): bool {
        $name = $args[1] ?? null;

        $team = null;

        if ($name !== null) {
            $team = $this->manager()->get($name);
        } elseif ($sender instanceof Player) {
            $team = $this->manager()->getPlayerTeam($sender->getName());
        }

        if ($team === null) {
            $this->error($sender, Messages::get($this->plugin, Messages::TEAM_INFO_USAGE));

            return true;
        }

        $sender->sendMessage($this->prefixed(Messages::get($this->plugin, Messages::TEAM_INFO_TITLE, ['name' => $team->getName()])));
        $sender->sendMessage(
            $this->prefixed(
                Messages::get(
                    $this->plugin,
                    Messages::TEAM_INFO_LINE1,
                    ['owner' => $team->getOwner(), 'level' => $team->getLevel(), 'xp' => $team->getXp()]
                )
            )
        );
        $sender->sendMessage(
            $this->prefixed(
                Messages::get(
                    $this->plugin,
                    Messages::TEAM_INFO_LINE2,
                    ['wins' => $team->getWins(), 'losses' => $team->getLosses()]
                )
            )
        );
        $sender->sendMessage(
            $this->prefixed(
                Messages::get(
                    $this->plugin,
                    Messages::TEAM_INFO_MEMBERS,
                    ['count' => $team->memberCount(), 'members' => implode(', ', $team->getMembers())]
                )
            )
        );

        return true;
    }

    private function handleList(
        CommandSender $sender
    ): bool {
        $teams = $this->manager()->getAll();

        if ($teams === []) {
            $this->info($sender, Messages::get($this->plugin, Messages::TEAM_NONE));

            return true;
        }

        $sender->sendMessage($this->prefixed(Messages::get($this->plugin, Messages::TEAM_LIST_TITLE)));

        foreach ($teams as $team) {
            $sender->sendMessage(
                $this->prefixed(
                    Messages::get(
                        $this->plugin,
                        Messages::TEAM_LIST_ROW,
                        [
                            'name' => $team->getName(),
                            'level' => $team->getLevel(),
                            'count' => $team->memberCount(),
                            'wins' => $team->getWins(),
                            'losses' => $team->getLosses()
                        ]
                    )
                )
            );
        }

        return true;
    }

    /**
     * @param list<string> $args
     */
    private function handleInvite(
        CommandSender $sender,
        array $args
    ): bool {
        if (!$sender instanceof Player) {
            $this->error($sender, Messages::get($this->plugin, Messages::TEAM_INVITE_ONLY));

            return true;
        }

        $target = $args[1] ?? null;

        if ($target === null) {
            $this->error($sender, Messages::get($this->plugin, Messages::TEAM_INVITE_USAGE));

            return true;
        }

        if (!$this->manager()->invite($sender->getName(), $target)) {
            $this->error(
                $sender,
                Messages::get($this->plugin, Messages::TEAM_INVITE_FAIL)
            );

            return true;
        }

        $this->success($sender, Messages::get($this->plugin, Messages::TEAM_INVITED, ['player' => $target]));

        $online = $this->plugin->getServer()->getPlayerExact($target);

        $online?->sendMessage(
            $this->prefixed(
                Messages::get(
                    $this->plugin,
                    Messages::TEAM_INVITE_RECEIVED,
                    ['player' => $sender->getName()]
                )
            )
        );

        return true;
    }

    private function handleAccept(
        CommandSender $sender
    ): bool {
        if (!$sender instanceof Player) {
            $this->error($sender, Messages::get($this->plugin, Messages::TEAM_ACCEPT_ONLY));

            return true;
        }

        $team = $this->manager()->acceptInvite($sender->getName());

        if ($team === null) {
            $this->error($sender, Messages::get($this->plugin, Messages::TEAM_NO_INVITE));

            return true;
        }

        $this->success($sender, Messages::get($this->plugin, Messages::TEAM_JOINED, ['name' => $team->getName()]));

        return true;
    }

    private function handleDeny(
        CommandSender $sender
    ): bool {
        if (!$sender instanceof Player) {
            $this->error($sender, Messages::get($this->plugin, Messages::TEAM_DENY_ONLY));

            return true;
        }

        $this->manager()->denyInvite($sender->getName())
            ? $this->success($sender, Messages::get($this->plugin, Messages::TEAM_DECLINED))
            : $this->error($sender, Messages::get($this->plugin, Messages::TEAM_NO_INVITE));

        return true;
    }

    private function handleLeave(
        CommandSender $sender
    ): bool {
        if (!$sender instanceof Player) {
            $this->error($sender, Messages::get($this->plugin, Messages::TEAM_LEAVE_ONLY));

            return true;
        }

        $team = $this->manager()->leave($sender->getName());

        if ($team === null) {
            $this->error($sender, Messages::get($this->plugin, Messages::TEAM_NOT_IN_TEAM));

            return true;
        }

        $this->success($sender, Messages::get($this->plugin, Messages::TEAM_LEFT, ['name' => $team->getName()]));

        return true;
    }

    /**
     * @param list<string> $args
     */
    private function handleKick(
        CommandSender $sender,
        array $args
    ): bool {
        if (!$sender instanceof Player) {
            $this->error($sender, Messages::get($this->plugin, Messages::TEAM_KICK_ONLY));

            return true;
        }

        $target = $args[1] ?? null;

        if ($target === null) {
            $this->error($sender, Messages::get($this->plugin, Messages::TEAM_KICK_USAGE));

            return true;
        }

        $this->manager()->kick($sender->getName(), $target)
            ? $this->success($sender, Messages::get($this->plugin, Messages::TEAM_KICKED, ['player' => $target]))
            : $this->error($sender, Messages::get($this->plugin, Messages::TEAM_KICK_FAIL));

        return true;
    }

    private function handleDisband(
        CommandSender $sender
    ): bool {
        if (!$sender instanceof Player) {
            $this->error($sender, Messages::get($this->plugin, Messages::TEAM_DISBAND_ONLY));

            return true;
        }

        $team = $this->manager()->getPlayerTeam($sender->getName());

        if ($team === null) {
            $this->error($sender, Messages::get($this->plugin, Messages::TEAM_NOT_IN_TEAM));

            return true;
        }

        if (!$team->isOwner($sender->getName())) {
            $this->error($sender, Messages::get($this->plugin, Messages::TEAM_DISBAND_OWNER));

            return true;
        }

        $name = $team->getName();

        $this->manager()->disband($name);
        $this->success($sender, Messages::get($this->plugin, Messages::TEAM_DISBANDED, ['name' => $name]));

        return true;
    }

    /**
     * /team arena seta|setb|clear|info — admin-only duel rendezvous points.
     *
     * @param list<string> $args
     */
    private function handleArena(
        CommandSender $sender,
        array $args
    ): bool {
        if (!$sender->hasPermission(Main::PERMISSION_ADMIN)) {
            $this->error($sender, Messages::get($this->plugin, Messages::COMMON_NO_PERMISSION));

            return true;
        }

        $action = strtolower($args[1] ?? 'info');

        if ($action === 'info') {
            $arena = $this->manager()->getArena();

            $this->info(
                $sender,
                Messages::get(
                    $this->plugin,
                    Messages::TEAM_ARENA_INFO,
                    [
                        'a' => $arena['a'] === null ? 'not set' : \AM\SkyMineZ\useless\Positions::describe($arena['a']),
                        'b' => $arena['b'] === null ? 'not set' : \AM\SkyMineZ\useless\Positions::describe($arena['b'])
                    ]
                )
            );

            return true;
        }

        if ($action === 'clear') {
            $this->manager()->clearArena();
            $this->success($sender, Messages::get($this->plugin, Messages::TEAM_ARENA_CLEARED));

            return true;
        }

        if (
            ($action === 'seta' || $action === 'setb')
            && $sender instanceof Player
        ) {
            $side = $action === 'setb' ? 'b' : 'a';

            $this->manager()->setArena($side, $sender->getLocation());

            $this->success(
                $sender,
                Messages::get(
                    $this->plugin,
                    Messages::TEAM_ARENA_SET,
                    [
                        'side' => strtoupper($side),
                        'where' => \AM\SkyMineZ\useless\Positions::describe($sender->getLocation())
                    ]
                )
            );

            return true;
        }

        $this->error($sender, Messages::get($this->plugin, Messages::TEAM_ARENA_USAGE));

        return true;
    }

    /**
     * /team duel challenge <team> | accept <id> | deny <id>
     *
     * @param list<string> $args
     */
    private function handleDuel(
        CommandSender $sender,
        array $args
    ): bool {
        if (!$sender instanceof Player) {
            $this->error($sender, Messages::get($this->plugin, Messages::TEAM_DUEL_ONLY));

            return true;
        }

        $action = strtolower($args[1] ?? 'help');

        return match ($action) {
            'challenge' => $this->duelChallenge($sender, $args[2] ?? null),
            'accept' => $this->duelAccept($sender, $args[2] ?? null),
            'deny' => $this->duelDeny($sender, $args[2] ?? null),
            default => $this->duelHelp($sender)
        };
    }

    private function duelChallenge(
        Player $player,
        ?string $targetTeam
    ): bool {
        if ($targetTeam === null) {
            $this->error($player, Messages::get($this->plugin, Messages::TEAM_DUEL_CHALLENGE_USAGE));

            return true;
        }

        $id = $this->manager()->challenge($player->getName(), $targetTeam);

        if ($id === null) {
            $this->error(
                $player,
                Messages::get($this->plugin, Messages::TEAM_DUEL_CHALLENGE_FAIL)
            );

            return true;
        }

        $own = $this->manager()->getPlayerTeam($player->getName());
        $other = $this->manager()->get($targetTeam);

        $this->success(
            $player,
            Messages::get(
                $this->plugin,
                Messages::TEAM_DUEL_SENT,
                ['id' => $id, 'team' => (string) $targetTeam]
            )
        );

        $this->noteArenaFallback($player, $this->manager()->getArena());

        if ($other !== null && $own !== null) {
            $owner = $this->plugin->getServer()->getPlayerExact($other->getOwner());

            $owner?->sendMessage(
                $this->prefixed(
                    Messages::get(
                        $this->plugin,
                        Messages::TEAM_DUEL_RECEIVED,
                        ['player' => $player->getName(), 'team' => $other->getName(), 'id' => $id]
                    )
                )
            );
        }

        return true;
    }

    private function duelAccept(
        Player $player,
        ?string $idArg
    ): bool {
        $id = $idArg !== null && is_numeric($idArg) ? (int) $idArg : -1;

        if (!$this->manager()->acceptChallenge($player->getName(), $id)) {
            $this->error($player, Messages::get($this->plugin, Messages::TEAM_DUEL_ACCEPT_FAIL));

            return true;
        }

        $this->success($player, Messages::get($this->plugin, Messages::TEAM_DUEL_ACCEPTED));

        $this->noteArenaFallback($player, $this->manager()->getArena());

        return true;
    }

    private function duelDeny(
        Player $player,
        ?string $idArg
    ): bool {
        $id = $idArg !== null && is_numeric($idArg) ? (int) $idArg : -1;

        $this->manager()->denyChallenge($player->getName(), $id)
            ? $this->success($player, Messages::get($this->plugin, Messages::TEAM_DUEL_DECLINED))
            : $this->error($player, Messages::get($this->plugin, Messages::TEAM_DUEL_NOTHING));

        return true;
    }

    private function duelHelp(
        CommandSender $sender
    ): bool {
        foreach ([
            Messages::get($this->plugin, Messages::TEAM_DUEL_HELP_CHALLENGE),
            Messages::get($this->plugin, Messages::TEAM_DUEL_HELP_ACCEPT),
            Messages::get($this->plugin, Messages::TEAM_DUEL_HELP_DENY)
        ] as $line) {
            $sender->sendMessage($this->prefixed($line));
        }

        return true;
    }

    private function handleHelp(
        CommandSender $sender
    ): bool {
        foreach ([
            Messages::get($this->plugin, Messages::TEAM_HELP_MENU),
            Messages::get($this->plugin, Messages::TEAM_HELP_CREATE),
            Messages::get($this->plugin, Messages::TEAM_HELP_INFO),
            Messages::get($this->plugin, Messages::TEAM_HELP_LIST),
            Messages::get($this->plugin, Messages::TEAM_HELP_INVITE),
            Messages::get($this->plugin, Messages::TEAM_HELP_ACCEPT),
            Messages::get($this->plugin, Messages::TEAM_HELP_DENY),
            Messages::get($this->plugin, Messages::TEAM_HELP_LEAVE),
            Messages::get($this->plugin, Messages::TEAM_HELP_KICK),
            Messages::get($this->plugin, Messages::TEAM_HELP_ARENA),
            Messages::get($this->plugin, Messages::TEAM_HELP_DUEL)
        ] as $line) {
            $sender->sendMessage($this->prefixed($line));
        }

        return true;
    }
}