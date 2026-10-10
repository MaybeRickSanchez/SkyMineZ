<?php

declare(strict_types=1);

namespace AM\SkyMineZ\team;

use AM\SkyMineZ\Main;
use AM\SkyMineZ\config\Messages;
use AM\SkyMineZ\useless\Arrays;
use AM\SkyMineZ\useless\Positions;
use pocketmine\utils\Config;
use RuntimeException;

/**
 * Teams, invitations and duels.
 *
 * Teams persist in teams.json. Invitations, pending challenges and live
 * battles are runtime-only: they all expire within minutes, and a restart
 * cleanly drops them instead of resurrecting stale fights.
 *
 * Duel integrity rules, enforced in exactly one place (here):
 *
 *  - a team takes part in at most one challenge or battle at a time
 *  - a battle only starts after an explicit accept, never on challenge
 *  - a battle ends on elimination, timeout, or both sides gone
 *  - every participant returns to their pre-battle position afterwards
 *  - quitting mid-battle counts that player out of their side
 */
final class TeamManager
{
    private const FILE_NAME = 'teams.json';

    /** @var array<string, Team> lowercase team name => Team */
    private array $teams = [];

    /**
     * Pending join invitations: lowercase player => team + expiry.
     *
     * @var array<string, array{team: string, by: string, expires: int}>
     */
    private array $invites = [];

    /**
     * Pending duel challenges, keyed by an incrementing id.
     *
     * @var array<int, array{from: string, to: string, by: string, expires: int}>
     */
    private array $challenges = [];

    /**
     * Live battles, keyed by challenge id.
     *
     * @var array<int, array{
     *     a: string,
     *     b: string,
     *     endsAt: int,
     *     outA: array<string, true>,
     *     outB: array<string, true>,
     *     returns: array<string, array{world: string, x: float, y: float, z: float, yaw: float, pitch: float}>
     * }>
     */
    private array $battles = [];

    private int $nextChallengeId = 1;

    private Config $db;

    public function __construct(
        private Main $main
    ) {
        $this->db = new Config(
            $this->main->getDataFolder() . self::FILE_NAME,
            Config::JSON
        );

        $this->main->getScheduler()->scheduleRepeatingTask(
            new TeamTask($this),
            TeamTask::INTERVAL
        );
    }

    public function load(): void
    {
        $this->teams = [];
        $this->invites = [];
        $this->challenges = [];
        $this->battles = [];

        foreach ($this->db->getAll() as $name => $data) {
            if (
                !is_string($name)
                || !is_array($data)
                || !Arrays::isStringMap($data)
            ) {
                continue;
            }

            $team = Team::fromArray($name, $data);

            if ($team === null) {
                $this->main->getLogger()->warning(
                    "Skipped malformed team '{$name}' in " . self::FILE_NAME
                );

                continue;
            }

            $this->teams[strtolower($name)] = $team;
        }
    }

    public function saveAll(): void
    {
        $data = [];

        foreach ($this->teams as $team) {
            $data[$team->getName()] = $team->toArray();
        }

        $this->db->setAll($data);
        $this->db->save();
    }
    /**
     * @throws RuntimeException when the name is taken or the player is-teamed
     */
    public function create(
        string $name,
        string $owner
    ): Team {
        $key = strtolower($name);

        if (isset($this->teams[$key])) {
            throw new RuntimeException("Team '{$name}' already exists.");
        }

        if ($this->getPlayerTeam($owner) !== null) {
            throw new RuntimeException('You are already in a team.');
        }

        $team = new Team($name, $owner);

        $this->teams[$key] = $team;

        $this->saveAll();

        return $team;
    }

    public function disband(
        string $name
    ): bool {
        $key = strtolower($name);

        if (!isset($this->teams[$key])) {
            return false;
        }

        $this->cancelBattlesOf($key);
        $this->cancelChallengesOf($key);

        unset($this->teams[$key]);

        $this->saveAll();

        return true;
    }

    public function get(
        string $name
    ): ?Team {
        return $this->teams[strtolower($name)] ?? null;
    }

    public function has(
        string $name
    ): bool {
        return isset($this->teams[strtolower($name)]);
    }

    /**
     * @return array<string, Team>
     */
    public function getAll(): array
    {
        return $this->teams;
    }

    public function count(): int
    {
        return count($this->teams);
    }

    public function getPlayerTeam(
        string $playerName
    ): ?Team {
        $playerName = strtolower($playerName);

        foreach ($this->teams as $team) {
            if ($team->isMember($playerName)) {
                return $team;
            }
        }

        return null;
    }

    public function leave(
        string $playerName
    ): ?Team {
        $team = $this->getPlayerTeam($playerName);

        if ($team === null) {
            return null;
        }

        $this->removeFromDuel($playerName);

        $team->removeMember($playerName);

        if ($team->memberCount() === 0) {
            $this->disband($team->getName());
        } else {
            $this->saveAll();
        }

        return $team;
    }

    /**
     * Owner-only kick. Returns false when the kicker is not the owner, the
     * target is not on the team, or the target is the owner themselves.
     */
    public function kick(
        string $kicker,
        string $target
    ): bool {
        $team = $this->getPlayerTeam($target);

        if ($team === null || !$team->isOwner($kicker)) {
            return false;
        }

        if ($team->isOwner($target)) {
            return false;
        }

        $this->removeFromDuel($target);

        $team->removeMember($target);

        $this->saveAll();

        return true;
    }

    /**
     * Owner-only invite. Returns false when the inviter is not an owner, the
     * target is already teamed, or a live invite already exists.
     */
    public function invite(
        string $inviter,
        string $target
    ): bool {
        $team = $this->getPlayerTeam($inviter);

        if ($team === null || !$team->isOwner($inviter)) {
            return false;
        }

        $targetKey = strtolower($target);

        if ($this->getPlayerTeam($targetKey) !== null) {
            return false;
        }

        if (
            isset($this->invites[$targetKey])
            && $this->invites[$targetKey]['expires'] >= time()
        ) {
            return false;
        }

        $this->invites[$targetKey] = [
            'team' => strtolower($team->getName()),
            'by' => strtolower($inviter),
            'expires' => time() + $this->inviteTtl()
        ];

        return true;
    }

    /**
     * @return array{team: string, by: string, expires: int}|null
     */
    public function getInvite(
        string $playerName
    ): ?array {
        $invite = $this->invites[strtolower($playerName)] ?? null;

        if ($invite === null) {
            return null;
        }

        if ($invite['expires'] < time()) {
            unset($this->invites[strtolower($playerName)]);

            return null;
        }

        return $invite;
    }

    public function acceptInvite(
        string $playerName
    ): ?Team {
        $key = strtolower($playerName);
        $invite = $this->getInvite($key);

        if ($invite === null) {
            return null;
        }

        if ($this->getPlayerTeam($key) !== null) {
            unset($this->invites[$key]);

            return null;
        }

        $team = $this->teams[$invite['team']] ?? null;

        unset($this->invites[$key]);

        if ($team === null) {
            return null;
        }

        $team->addMember($key);

        $this->saveAll();

        return $team;
    }

    public function denyInvite(
        string $playerName
    ): bool {
        $key = strtolower($playerName);

        if (!isset($this->invites[$key])) {
            return false;
        }

        unset($this->invites[$key]);

        return true;
    }

    /**
     * Starts a duel challenge from one team's owner to another team.
     * Nothing fights until the other side explicitly accepts.
     *
     * @return int|null the challenge id, or null when the challenge is invalid
     */
    public function challenge(
        string $challenger,
        string $targetTeam
    ): ?int {
        $own = $this->getPlayerTeam($challenger);

        if ($own === null || !$own->isOwner($challenger)) {
            return null;
        }

        $other = $this->get($targetTeam);

        if ($other === null || $other === $own) {
            return null;
        }

        $ownKey = strtolower($own->getName());
        $otherKey = strtolower($other->getName());

        if ($this->isBusy($ownKey) || $this->isBusy($otherKey)) {
            return null;
        }

        $id = $this->nextChallengeId++;

        $this->challenges[$id] = [
            'from' => $ownKey,
            'to' => $otherKey,
            'by' => strtolower($challenger),
            'expires' => time() + $this->challengeTtl()
        ];

        return $id;
    }

    /**
     * @return array{from: string, to: string, by: string, expires: int}|null
     */
    public function getChallenge(
        int $id
    ): ?array {
        $challenge = $this->challenges[$id] ?? null;

        if ($challenge === null) {
            return null;
        }

        if ($challenge['expires'] < time()) {
            unset($this->challenges[$id]);

            return null;
        }

        return $challenge;
    }

    /**
     * Challenges waiting for this player's team (they must own it to accept).
     *
     * @return array<int, array{from: string, to: string, by: string, expires: int}>
     */
    public function incomingChallenges(
        string $playerName
    ): array {
        $own = $this->getPlayerTeam($playerName);

        if ($own === null || !$own->isOwner($playerName)) {
            return [];
        }

        $ownKey = strtolower($own->getName());
        $result = [];

        foreach ($this->challenges as $id => $challenge) {
            if ($challenge['to'] !== $ownKey) {
                continue;
            }

            if ($challenge['expires'] < time()) {
                unset($this->challenges[$id]);

                continue;
            }

            $result[$id] = $challenge;
        }

        return $result;
    }

    /**
     * Accepts a pending challenge and starts the battle. Only the challenged
     * team's owner can accept, and only while neither side is otherwise busy.
     */
    public function acceptChallenge(
        string $playerName,
        int $id
    ): bool {
        $challenge = $this->getChallenge($id);

        if ($challenge === null) {
            return false;
        }

        $own = $this->getPlayerTeam($playerName);

        if (
            $own === null
            || !$own->isOwner($playerName)
            || strtolower($own->getName()) !== $challenge['to']
        ) {
            return false;
        }

        $teamA = $this->teams[$challenge['from']] ?? null;
        $teamB = $this->teams[$challenge['to']] ?? null;

        if ($teamA === null || $teamB === null) {
            unset($this->challenges[$id]);

            return false;
        }

        if (
            $this->isBusy(strtolower($teamA->getName()))
            || $this->isBusy(strtolower($teamB->getName()))
        ) {
            return false;
        }

        unset($this->challenges[$id]);

        $returns = [];

        foreach ([$teamA, $teamB] as $team) {
            foreach ($team->getMembers() as $member) {
                $online = $this->main->getServer()->getPlayerExact($member);

                if ($online === null) {
                    continue;
                }

                $returns[$member] = Positions::toArray(
                    $online->getLocation()
                );
            }
        }

        $this->battles[$id] = [
            'a' => strtolower($teamA->getName()),
            'b' => strtolower($teamB->getName()),
            'endsAt' => time() + $this->duelDuration(),
            'outA' => [],
            'outB' => [],
            'returns' => $returns
        ];

        $this->rendezvous($teamA, $this->getArenaA());
        $this->rendezvous($teamB, $this->getArenaB());

        $this->broadcastDuel(
            Messages::get(
                $this->main,
                Messages::TEAM_DUEL_STARTED,
                ['a' => $teamA->getName(), 'b' => $teamB->getName()]
            )
        );

        return true;
    }

    public function denyChallenge(
        string $playerName,
        int $id
    ): bool {
        $challenge = $this->getChallenge($id);

        if ($challenge === null) {
            return false;
        }

        $own = $this->getPlayerTeam($playerName);

        if (
            $own === null
            || strtolower($own->getName()) !== $challenge['to']
        ) {
            return false;
        }

        unset($this->challenges[$id]);

        return true;
    }

    /**
     * Duel arena rendezvous, one spawn per side.
     *
     * Stored in config.yml (`teams.arena-a` / `teams.arena-b`) via the
     * canonical {@link Positions} format, so the arena survives restarts. Either
     * side may be unset: that side simply fights where it stands. A missing
     * world (deleted or not yet loaded and unloadable) also falls back to
     * fighting in place instead of cancelling the duel.
     */
    public function getArenaA(): ?\pocketmine\entity\Location
    {
        return $this->readArena('teams.arena-a');
    }

    public function getArenaB(): ?\pocketmine\entity\Location
    {
        return $this->readArena('teams.arena-b');
    }

    /**
     * @return array{a: \pocketmine\entity\Location|null, b: \pocketmine\entity\Location|null}
     */
    public function getArena(): array
    {
        return ['a' => $this->getArenaA(), 'b' => $this->getArenaB()];
    }

    public function setArena(
        string $side,
        \pocketmine\entity\Location $position
    ): void {
        $side = strtolower($side) === 'b' ? 'b' : 'a';

        $config = $this->main->getConfigManager();
        $config->set('teams.arena-' . $side, Positions::toArray($position));
        $config->save();
    }

    public function clearArena(): void
    {
        $config = $this->main->getConfigManager();
        $config->set('teams.arena-a', null);
        $config->set('teams.arena-b', null);
        $config->save();
    }

    private function readArena(
        string $path
    ): ?\pocketmine\entity\Location {
        $data = $this->main->getConfigManager()->get($path);

        if (!is_array($data)) {
            return null;
        }

        return Positions::fromArray(
            $data,
            $this->main->getServer()->getWorldManager()
        );
    }

    /**
     * Teleports every online member of $team to the arena spawn. Returns are
     * recorded before this runs, and {@link sendHome()} brings everyone back
     * on win, draw, timeout or cancel — so a teleport here can never strand
     * anybody. A null spawn means "fight where you stand".
     */
    private function rendezvous(
        Team $team,
        ?\pocketmine\entity\Location $spawn
    ): void {
        if ($spawn === null) {
            return;
        }

        foreach ($team->getMembers() as $member) {
            $online = $this->main->getServer()->getPlayerExact($member);

            if ($online === null || !$online->isConnected()) {
                continue;
            }

            $online->teleport($spawn);
        }
    }

    /**
     * PvP compatibility hook: members of opposite sides of a live battle may
     * always damage each other, regardless of their PvP toggles.
     */
    public function areOpponents(
        string $attacker,
        string $victim
    ): bool {
        $attacker = strtolower($attacker);
        $victim = strtolower($victim);

        foreach ($this->battles as $battle) {
            $teamA = $this->teams[$battle['a']] ?? null;
            $teamB = $this->teams[$battle['b']] ?? null;

            if ($teamA === null || $teamB === null) {
                continue;
            }

            $aAttacker = $this->isFighting($teamA, isset($battle['outA'][$attacker]), $attacker);
            $bAttacker = $this->isFighting($teamB, isset($battle['outB'][$attacker]), $attacker);
            $aVictim = $this->isFighting($teamA, isset($battle['outA'][$victim]), $victim);
            $bVictim = $this->isFighting($teamB, isset($battle['outB'][$victim]), $victim);

            if (
                ($aAttacker && $bVictim)
                || ($bAttacker && $aVictim)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Marks a player out of their side (death or quit). When a whole side is
     * out, the other side wins immediately.
     */
    public function markOut(
        string $playerName
    ): void {
        $playerName = strtolower($playerName);

        foreach ($this->battles as $id => $battle) {
            $side = $this->sideOf($battle, $playerName);

            if ($side === null) {
                continue;
            }

            $key = $side === 'a' ? 'outA' : 'outB';

            $battle[$key][$playerName] = true;
            $this->battles[$id] = $battle;

            $this->checkBattleEnd($id);
        }
    }

    /**
     * Removes a player from any live battle without deciding it (used when
     * they leave their team mid-fight, which counts them out via markOut
     * first by the caller when appropriate).
     */
    public function removeFromDuel(
        string $playerName
    ): void {
        $this->markOut($playerName);
    }

    /**
     * @return array<string, array{level: int, wins: int}>
     */
    public function getSnapshot(): array
    {
        $result = [];

        foreach ($this->teams as $team) {
            $result[$team->getName()] = [
                'level' => $team->getLevel(),
                'wins' => $team->getWins()
            ];
        }

        return $result;
    }

    /**
     * Slow maintenance: expire invites/challenges, finish timed-out battles.
     */
    public function tick(): void
    {
        $now = time();

        foreach ($this->invites as $player => $invite) {
            if ($invite['expires'] < $now) {
                unset($this->invites[$player]);
            }
        }

        foreach ($this->challenges as $id => $challenge) {
            if ($challenge['expires'] < $now) {
                unset($this->challenges[$id]);
            }
        }

        foreach (array_keys($this->battles) as $id) {
            $battle = $this->battles[$id] ?? null;

            if ($battle === null) {
                continue;
            }

            if ($battle['endsAt'] <= $now) {
                $this->finishDraw($id);
            }
        }
    }

    private function isBusy(
        string $teamKey
    ): bool {
        foreach ($this->challenges as $challenge) {
            if (
                $challenge['from'] === $teamKey
                || $challenge['to'] === $teamKey
            ) {
                return true;
            }
        }

        foreach ($this->battles as $battle) {
            if (
                $battle['a'] === $teamKey
                || $battle['b'] === $teamKey
            ) {
                return true;
            }
        }

        return false;
    }

    private function isFighting(
        Team $team,
        bool $out,
        string $playerName
    ): bool {
        return $team->isMember($playerName) && !$out;
    }

    /**
     * @param array{a: string, b: string, endsAt: int, outA: array<string, true>, outB: array<string, true>, returns: array<string, mixed>} $battle
     */
    private function sideOf(
        array $battle,
        string $playerName
    ): ?string {
        $teamA = $this->teams[$battle['a']] ?? null;
        $teamB = $this->teams[$battle['b']] ?? null;

        if ($teamA !== null && $teamA->isMember($playerName)) {
            return 'a';
        }

        if ($teamB !== null && $teamB->isMember($playerName)) {
            return 'b';
        }

        return null;
    }

    private function checkBattleEnd(
        int $id
    ): void {
        $battle = $this->battles[$id] ?? null;

        if ($battle === null) {
            return;
        }

        $teamA = $this->teams[$battle['a']] ?? null;
        $teamB = $this->teams[$battle['b']] ?? null;

        if ($teamA === null || $teamB === null) {
            unset($this->battles[$id]);

            return;
        }

        $aliveA = $this->aliveCount($teamA, $battle['outA']);
        $aliveB = $this->aliveCount($teamB, $battle['outB']);

        if ($aliveA === 0 && $aliveB === 0) {
            $this->finishDraw($id);

            return;
        }

        if ($aliveB === 0) {
            $this->finishWin($id, $teamA, $teamB);

            return;
        }

        if ($aliveA === 0) {
            $this->finishWin($id, $teamB, $teamA);
        }
    }

    /**
     * @param array<string, true> $out
     */
    private function aliveCount(
        Team $team,
        array $out
    ): int {
        $alive = 0;

        foreach ($team->getMembers() as $member) {
            if (!isset($out[$member])) {
                ++$alive;
            }
        }

        return $alive;
    }

    private function finishWin(
        int $id,
        Team $winner,
        Team $loser
    ): void {
        $battle = $this->battles[$id] ?? null;

        unset($this->battles[$id]);

        if ($battle === null) {
            return;
        }

        $winner->addWin();
        $loser->addLoss();

        $leveled = $winner->addXp(
            $this->duelWinXp(),
            $this->xpPerLevel()
        );

        $this->saveAll();

        $this->broadcastDuel(
            Messages::get(
                $this->main,
                Messages::TEAM_DUEL_OVER,
                ['winner' => $winner->getName(), 'loser' => $loser->getName()]
            )
            . ($leveled
                ? Messages::get(
                    $this->main,
                    Messages::TEAM_DUEL_LEVEL_UP,
                    ['winner' => $winner->getName(), 'level' => $winner->getLevel()]
                )
                : "")
        );

        $this->sendHome($battle);
    }

    private function finishDraw(
        int $id
    ): void {
        $battle = $this->battles[$id] ?? null;

        unset($this->battles[$id]);

        if ($battle === null) {
            return;
        }

        $teamA = $this->teams[$battle['a']] ?? null;
        $teamB = $this->teams[$battle['b']] ?? null;

        $this->broadcastDuel(
            Messages::get(
                $this->main,
                Messages::TEAM_DUEL_DRAW,
                [
                    'a' => $teamA !== null ? $teamA->getName() : 'a team',
                    'b' => $teamB !== null ? $teamB->getName() : 'a team'
                ]
            )
        );

        $this->sendHome($battle);
    }

    /**
     * @param array{a: string, b: string, endsAt: int, outA: array<string, true>, outB: array<string, true>, returns: array<string, mixed>} $battle
     */
    private function sendHome(
        array $battle
    ): void {
        $worldManager = $this->main->getServer()->getWorldManager();

        foreach ($battle['returns'] as $playerName => $record) {
            if (
                !is_array($record)
                || !Arrays::isStringMap($record)
            ) {
                continue;
            }

            $position = Positions::fromArray($record, $worldManager);

            if ($position === null) {
                continue;
            }

            $player = $this->main->getServer()->getPlayerExact($playerName);

            if ($player === null || !$player->isConnected()) {
                continue;
            }

            $player->teleport($position);
        }
    }

    private function cancelBattlesOf(
        string $teamKey
    ): void {
        foreach (array_keys($this->battles) as $id) {
            $battle = $this->battles[$id] ?? null;

            if (
                $battle !== null
                && ($battle['a'] === $teamKey || $battle['b'] === $teamKey)
            ) {
                $this->sendHome($battle);

                unset($this->battles[$id]);
            }
        }
    }

    private function cancelChallengesOf(
        string $teamKey
    ): void {
        foreach ($this->challenges as $id => $challenge) {
            if (
                $challenge['from'] === $teamKey
                || $challenge['to'] === $teamKey
            ) {
                unset($this->challenges[$id]);
            }
        }
    }

    private function broadcastDuel(
        string $message
    ): void {
        $this->main->getServer()->broadcastMessage(
            $this->main->getConfigManager()->getPrefix() . $message
        );
    }

    private function inviteTtl(): int
    {
        return max(
            10,
            $this->main->getConfigManager()->getInt('teams.invite-seconds', 120)
        );
    }

    private function challengeTtl(): int
    {
        return max(
            10,
            $this->main->getConfigManager()->getInt('teams.challenge-seconds', 60)
        );
    }

    private function duelDuration(): int
    {
        return max(
            60,
            $this->main->getConfigManager()->getInt('teams.duel-seconds', 600)
        );
    }

    private function duelWinXp(): int
    {
        return max(
            0,
            $this->main->getConfigManager()->getInt('teams.duel-win-xp', 50)
        );
    }

    private function xpPerLevel(): int
    {
        return max(
            1,
            $this->main->getConfigManager()->getInt('teams.xp-per-level', 100)
        );
    }
}