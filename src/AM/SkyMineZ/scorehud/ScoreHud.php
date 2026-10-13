<?php

declare(strict_types=1);

namespace AM\SkyMineZ\scorehud;

use AM\SkyMineZ\event\EconomyChangeEvent;
use AM\SkyMineZ\event\ScoreHudUpdateEvent;
use AM\SkyMineZ\Main;
use AM\SkyMineZ\useless\CollisionBox;
use AM\SkyMineZ\useless\SpreadTask;
use pocketmine\event\EventPriority;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\network\mcpe\protocol\RemoveObjectivePacket;
use pocketmine\network\mcpe\protocol\SetDisplayObjectivePacket;
use pocketmine\network\mcpe\protocol\SetScorePacket;
use pocketmine\network\mcpe\protocol\types\ScorePacketEntry;
use pocketmine\player\Player;
use pocketmine\scheduler\TaskHandler;

/**
 * The sidebar.
 *
 * Three layouts exist: a welcome screen inside named zones, a duel screen
 * while fighting a team battle, and a stats screen everywhere else.
 *
 * Places are the generic mechanism behind the welcome screen: any cuboid can
 * be registered with a board kind, and the first matching place wins (a live
 * duel always wins over places). The mid-lobby zone is the built-in place,
 * synced from the lobby manager; future features register their own.
 *
 * Switching in and out of a zone has hysteresis (see
 * `scoreboard.spawn-welcome-seconds` and `scoreboard.spawn-leave-seconds`) so
 * walking two blocks across the border does not make the board flicker.
 *
 * Performance notes, because this runs on every player:
 *
 *  - A pass over the online players is spread across several ticks with
 *    {@link SpreadTask}, which self-cancels once every entry was visited.
 *  - Only boards that actually changed are re-sent. An unchanged board costs
 *    a few comparisons and zero packets, so economy/kill hooks can refresh
 *    eagerly.
 */
final class ScoreHud implements Listener
{
    private const OBJECTIVE = 'skymine';

    private const MODE_MIDDLE = 'middle';
    private const MODE_LOBBY = 'lobby';
    private const MODE_DUEL = 'duel';

    private const PLACE_MID = 'mid';

    private MiddleLobbyScoreHud $middleHud;

    private LobbyScoreHud $lobbyHud;

    private DuelScoreHud $duelHud;

    /**
     * Named scoreboard places: id => box + board kind. The mid-lobby zone is
     * synced from the lobby manager; anything else is registered by the
     * feature that owns it. First match in registration order wins.
     *
     * @var array<string, array{box: CollisionBox, board: string}>
     */
    private array $places = [];

    /** @var TaskHandler<ScoreHudTask>|null */
    private ?TaskHandler $task = null;

    /**
     * Current layout per player, keyed by lower-case name.
     *
     * @var array<string, string>
     */
    private array $modes = [];

    /**
     * Timestamp the player last entered a welcome zone, or null.
     *
     * @var array<string, int|null>
     */
    private array $zoneEnteredAt = [];

    /**
     * Timestamp the player last left the welcome zone, or null.
     *
     * @var array<string, int|null>
     */
    private array $leftZoneAt = [];

    /**
     * The lines currently on each player's screen, so only changes are sent.
     *
     * @var array<string, list<string>>
     */
    private array $lines = [];

    /**
     * The title currently on each player's screen, keyed like $lines. Titles
     * must be tracked too: switching between the welcome and stats layouts
     * changes the title, and sending new lines under the old title mixes the
     * two boards with each other.
     *
     * @var array<string, string>
     */
    private array $titles = [];

    /**
     * Players who switched the sidebar off with /hud.
     *
     * @var array<string, true>
     */
    private array $disabled = [];

    public function __construct(
        private Main $main
    ) {
        $this->middleHud = new MiddleLobbyScoreHud($main);
        $this->lobbyHud = new LobbyScoreHud($main);
        $this->duelHud = new DuelScoreHud($main);

        /*
         * Refresh eagerly on every landed balance change (money AND gold),
         * the same MONITOR pattern the quest tracker uses: cancelled events
         * changed nothing, so they are skipped. updatePlayer() is delta-only,
         * so this costs nothing when the visible board did not change.
         */
        $this->main->getServer()->getPluginManager()->registerEvent(
            EconomyChangeEvent::class,
            function(EconomyChangeEvent $event): void {
                if ($event->isCancelled()) {
                    return;
                }

                $player = $this->main->getServer()->getPlayerExact(
                    $event->getPlayerName()
                );

                if ($player !== null) {
                    $this->updatePlayer($player);
                }
            },
            EventPriority::MONITOR,
            $this->main
        );

        $this->start();
    }

    /**
     * Registers a named scoreboard place: players inside $box see $board
     * (one of 'middle', 'lobby' or 'duel'). First registered match wins; a
     * live duel always wins over places. Future features (arenas, event
     * zones) register here instead of growing their own board logic.
     */
    public function registerPlace(
        string $id,
        CollisionBox $box,
        string $board
    ): void {
        if (
            $board !== self::MODE_MIDDLE
            && $board !== self::MODE_LOBBY
            && $board !== self::MODE_DUEL
        ) {
            throw new \InvalidArgumentException(
                "Unknown scoreboard '{$board}' for place '{$id}'."
            );
        }

        $this->places[$id] = ['box' => $box, 'board' => $board];
    }

    public function unregisterPlace(
        string $id
    ): void {
        unset($this->places[$id]);
    }

    /**
     * @return array<string, array{box: CollisionBox, board: string}>
     */
    public function getPlaces(): array
    {
        return $this->places;
    }

    private function start(): void
    {
        $this->task?->cancel();

        $this->task = $this->main->getScheduler()->scheduleRepeatingTask(
            new ScoreHudTask($this),
            $this->getUpdateTicks()
        );
    }

    /**
     * Re-reads the config and re-arms the task with the new interval.
     */
    public function restart(): void
    {
        // Keys in $lines are lower-case, but getPlayerExact() is
        // case-sensitive, so resolve through the online list instead of the
        // key to never miss mixed-case names.
        foreach ($this->main->getServer()->getOnlinePlayers() as $player) {
            $name = $this->key($player);

            if (!isset($this->lines[$name])) {
                continue;
            }

            $mode = $this->modes[$name] ?? self::MODE_LOBBY;

            if (
                $mode === self::MODE_DUEL
                && $this->main->getTeamManager()->getBattleFor($name) === null
            ) {
                // Stale duel board (battle ended while config reloaded):
                // re-resolve like a fresh join instead of repainting it.
                $this->initializePlayer($player);

                continue;
            }

            $this->createScoreboard(
                $player,
                $this->titleFor($mode),
                $this->linesFor($player, $mode)
            );
        }

        $this->start();
    }

    public function onJoin(PlayerJoinEvent $event): void
    {
        $this->initializePlayer($event->getPlayer());
    }

    public function onQuit(PlayerQuitEvent $event): void
    {
        $this->remove($event->getPlayer());
    }

    /**
     * One full pass over the online players, spread over several ticks.
     */
    public function tick(): void
    {
        $online = $this->main->getServer()->getOnlinePlayers();

        if ($online === []) {
            return;
        }

        // Scale the slice so a pass always finishes before the next tick:
        // otherwise two SpreadTasks overlap and send duplicate/out-of-order
        // packets on busy servers.
        $interval = $this->getUpdateTicks();
        $perTick = max(
            $this->getPlayersPerTick(),
            (int) ceil(count($online) / max(1, $interval))
        );

        SpreadTask::spread(
            $this->main,
            /*
             * No array_values() here: SpreadTask::flatten() already reindexes,
             * so one copy per pass is enough.
             */
            $online,
            $perTick,
            function(mixed $player): void {
                if ($player instanceof Player) {
                    $this->updatePlayer($player);
                }
            }
        );
    }

    public function initializePlayer(
        Player $player
    ): void {
        $name = $this->key($player);

        unset(
            $this->zoneEnteredAt[$name],
            $this->leftZoneAt[$name],
            $this->lines[$name],
            $this->titles[$name]
        );

        if ($this->main->getTeamManager()->getBattleFor($name) !== null) {
            $this->modes[$name] = self::MODE_DUEL;

            $this->render(
                $player,
                self::MODE_DUEL
            );

            return;
        }

        if ($this->inWelcomeZone($player)) {
            $this->modes[$name] = self::MODE_MIDDLE;
            $this->zoneEnteredAt[$name] = time();
            $this->leftZoneAt[$name] = null;

            $this->render(
                $player,
                self::MODE_MIDDLE
            );

            return;
        }

        $this->modes[$name] = self::MODE_LOBBY;
        $this->leftZoneAt[$name] = null;

        $this->render(
            $player,
            self::MODE_LOBBY
        );
    }

    public function remove(
        Player $player
    ): void {
        $name = $this->key($player);

        $this->removeScoreboard($player);

        unset(
            $this->modes[$name],
            $this->zoneEnteredAt[$name],
            $this->leftZoneAt[$name],
            $this->lines[$name],
            $this->titles[$name],
            $this->disabled[$name]
        );
    }

    /**
     * Flips the sidebar for one player.
     *
     * @return bool the new state, true meaning the sidebar is visible again
     */
    public function toggle(
        Player $player
    ): bool {
        $name = $this->key($player);

        if (isset($this->disabled[$name])) {
            unset(
                $this->disabled[$name],
                $this->modes[$name],
                $this->zoneEnteredAt[$name],
                $this->leftZoneAt[$name],
                $this->titles[$name]
            );

            $this->initializePlayer($player);

            return true;
        }

        $this->disabled[$name] = true;

        $this->removeScoreboard($player);

        unset(
            $this->modes[$name],
            $this->zoneEnteredAt[$name],
            $this->leftZoneAt[$name],
            $this->titles[$name]
        );

        return false;
    }

    public function isEnabledFor(
        Player $player
    ): bool {
        return !isset(
            $this->disabled[$this->key($player)]
        );
    }

    public function updatePlayer(
        Player $player
    ): void {
        if (!$player->isConnected()) {
            return;
        }

        $name = $this->key($player);

        if (isset($this->disabled[$name])) {
            /*
             * Turned off after the board was created: send the removal once and
             * stop touching this player.
             */
            if (isset($this->lines[$name])) {
                $this->removeScoreboard($player);

                unset($this->modes[$name]);
            }

            return;
        }

        if (!isset($this->modes[$name])) {
            $this->initializePlayer($player);

            return;
        }

        $now = time();
        $mode = $this->modes[$name];

        // A live duel always wins immediately, no hysteresis: duels start
        // and end explicitly, so there is nothing to debounce.
        if ($this->main->getTeamManager()->getBattleFor($name) !== null) {
            $this->modes[$name] = self::MODE_DUEL;

            $this->render(
                $player,
                self::MODE_DUEL
            );

            return;
        }

        if ($mode === self::MODE_DUEL) {
            // The duel just ended: re-resolve from scratch like a fresh join
            // so the title is recreated instead of lingering.
            $this->initializePlayer($player);

            return;
        }

        if ($this->inWelcomeZone($player)) {
            $this->leftZoneAt[$name] = null;

            if ($mode !== self::MODE_MIDDLE) {
                $this->modes[$name] = self::MODE_MIDDLE;
                $this->zoneEnteredAt[$name] = $now;
            }

            $enteredAt = $this->zoneEnteredAt[$name] ?? $now;

            /*
             * The welcome screen only turns into the stats screen after the
             * player has stayed in the zone for a while, so spawning and dying
             * in the welcome area does not make the board change constantly.
             */
            $mode = ($now - $enteredAt) >= $this->getWelcomeSeconds()
                ? self::MODE_LOBBY
                : self::MODE_MIDDLE;

            $this->modes[$name] = $mode;

            $this->render(
                $player,
                $mode
            );

            return;
        }

        if ($mode === self::MODE_MIDDLE) {
            if ($this->leftZoneAt[$name] === null) {
                $this->leftZoneAt[$name] = $now;
            }

            /*
             * Same idea in reverse: stepping two blocks out of the zone does
             * not immediately swap the board.
             */
            if (($now - $this->leftZoneAt[$name]) >= $this->getLeaveSeconds()) {
                $this->modes[$name] = self::MODE_LOBBY;
                $this->zoneEnteredAt[$name] = 0;
            }

            $mode = $this->modes[$name];
        }

        $this->render(
            $player,
            $mode
        );
    }

    /**
     * Sends the board if it differs from what the player already sees.
     *
     * A title change (welcome <-> stats layout) recreates the whole board:
     * line packets cannot change the title, so sending new lines under the
     * old title would mix the two layouts with each other.
     */
    private function render(
        Player $player,
        string $mode
    ): void {
        $name = $this->key($player);
        $title = $this->titleFor($mode);
        $lines = $this->linesFor(
            $player,
            $mode
        );

        if (ScoreHudUpdateEvent::hasHandlers()) {
            $event = new ScoreHudUpdateEvent(
                $player->getName(),
                $title,
                $lines
            );

            $event->call();

            if ($event->isCancelled()) {
                return;
            }
        }

        $oldLines = $this->lines[$name] ?? null;
        $oldTitle = $this->titles[$name] ?? null;

        if ($oldLines === null || $oldTitle !== $title) {
            $this->createScoreboard(
                $player,
                $title,
                $lines
            );

            return;
        }

        if ($oldLines === $lines) {
            return;
        }

        $this->sendChangedLines(
            $player,
            $oldLines,
            $lines
        );

        $this->lines[$name] = $lines;
    }

    /**
     * @param list<string> $lines
     */
    private function createScoreboard(
        Player $player,
        string $title,
        array $lines
    ): void {
        $this->removeScoreboard($player);

        $packet = SetDisplayObjectivePacket::create(
            SetDisplayObjectivePacket::DISPLAY_SLOT_SIDEBAR,
            self::OBJECTIVE,
            $title,
            'dummy',
            SetDisplayObjectivePacket::SORT_ORDER_DESCENDING
        );

        $player->getNetworkSession()->sendDataPacket(
            $packet
        );

        $this->sendChangedLines(
            $player,
            [],
            $lines
        );

        $this->lines[$this->key($player)] = $lines;
        $this->titles[$this->key($player)] = $title;
    }

    /**
     * Sends only the lines whose text or position changed, plus removals for
     * lines that disappeared.
     *
     * @param list<string> $oldLines
     * @param list<string> $newLines
     */
    private function sendChangedLines(
        Player $player,
        array $oldLines,
        array $newLines
    ): void {
        $changes = [];
        $removals = [];

        $max = max(
            count($oldLines),
            count($newLines)
        );

        for (
            $index = 0;
            $index < $max;
            ++$index
        ) {
            $old = $oldLines[$index] ?? null;
            $new = $newLines[$index] ?? null;

            if ($old === $new) {
                continue;
            }

            /*
             * Bedrock identifies a scoreboard row by its id, not by its text, so
             * a line has to be removed by sending the *old* text with the same
             * id. That is what the padding below is for: it makes the text unique
             * per row, which also stops the client from merging two rows that
             * happen to read the same.
             */
            $entry = new ScorePacketEntry();

            $entry->objectiveName = self::OBJECTIVE;
            $entry->type = ScorePacketEntry::TYPE_FAKE_PLAYER;
            $entry->scoreboardId = $index + 1;

            if ($new === null) {
                // Row disappeared: tell the client to drop it, not to show
                // stale text with a bogus (<=0) score.
                $entry->customName = $this->makeUniqueLine(
                    (string) $old,
                    $index
                );
                $entry->score = count($oldLines) - $index;

                $removals[] = $entry;

                continue;
            }

            $entry->customName = $this->makeUniqueLine(
                $new,
                $index
            );

            $entry->score = count($newLines) - $index;

            $changes[] = $entry;
        }

        if ($removals !== []) {
            $removePacket = new SetScorePacket();
            $removePacket->type = SetScorePacket::TYPE_REMOVE;
            $removePacket->entries = $removals;

            $player->getNetworkSession()->sendDataPacket(
                $removePacket
            );
        }

        if ($changes === []) {
            return;
        }

        $packet = new SetScorePacket();
        $packet->type = SetScorePacket::TYPE_CHANGE;
        $packet->entries = $changes;

        $player->getNetworkSession()->sendDataPacket(
            $packet
        );
    }

    private function makeUniqueLine(
        string $line,
        int $index
    ): string {
        return $line . str_repeat(
            '§r',
            $index + 1
        );
    }

    private function removeScoreboard(
        Player $player
    ): void {
        $packet = new RemoveObjectivePacket();
        $packet->objectiveName = self::OBJECTIVE;

        $player->getNetworkSession()->sendDataPacket(
            $packet
        );

        unset(
            $this->lines[$this->key($player)],
            $this->titles[$this->key($player)]
        );
    }

    private function titleFor(
        string $mode
    ): string {
        return match ($mode) {
            self::MODE_MIDDLE => $this->middleHud->getTitle(),
            self::MODE_DUEL => $this->duelHud->getTitle(),
            default => $this->lobbyHud->getTitle()
        };
    }

    /**
     * @return list<string>
     */
    private function linesFor(
        Player $player,
        string $mode
    ): array {
        return match ($mode) {
            self::MODE_MIDDLE => $this->middleHud->getLines(),
            self::MODE_DUEL => $this->duelHud->getLines($player),
            default => $this->lobbyHud->getLines($player)
        };
    }

    /**
     * Whether the player stands in a welcome-board place. A live duel is not
     * a welcome zone even if its arena overlaps one; the duel board is
     * resolved separately and always wins.
     */
    private function inWelcomeZone(
        Player $player
    ): bool {
        $this->syncMidZone();

        foreach ($this->places as $place) {
            if ($place['board'] !== self::MODE_MIDDLE) {
                continue;
            }

            if ($place['box']->isInWorld($player->getPosition(), $player->getWorld())) {
                return true;
            }
        }

        return $this->inWelcomeFallback($player);
    }

    /**
     * Keeps the built-in mid place in sync with the lobby manager. Compares
     * box instances, so the steady state costs one memoized getter and no
     * allocation; a zone change re-registers exactly once.
     */
    private function syncMidZone(): void
    {
        $box = $this->main->getLobbyManager()->midZoneBox();
        $current = $this->places[self::PLACE_MID]['box'] ?? null;

        if ($box === $current) {
            return;
        }

        if ($box === null) {
            unset($this->places[self::PLACE_MID]);

            return;
        }

        $this->places[self::PLACE_MID] = ['box' => $box, 'board' => self::MODE_MIDDLE];
    }

    /**
     * Pre-zone behaviour for servers that never set a mid zone: the old
     * spawn-radius circle around the mid-lobby point (or the default spawn
     * when even that is missing).
     */
    private function inWelcomeFallback(
        Player $player
    ): bool {
        if ($this->main->getLobbyManager()->midZoneBox() !== null) {
            return false;
        }

        $center = $this->main->getLobbyManager()->getMidLobby();

        if ($center === null) {
            $defaultWorld = $this->main
                ->getServer()
                ->getWorldManager()
                ->getDefaultWorld();

            if ($defaultWorld === null) {
                return false;
            }

            $center = $defaultWorld->getSpawnLocation();
        }

        if ($player->getWorld()->getFolderName() !== $center->getWorld()->getFolderName()) {
            return false;
        }

        $radius = $this->getSpawnRadius();

        return $player->getPosition()->distanceSquared(
            $center
        ) <= ($radius * $radius);
    }

    private function key(
        Player $player
    ): string {
        return strtolower(
            $player->getName()
        );
    }

    private function getWelcomeSeconds(): int
    {
        return max(
            0,
            $this->main
                ->getConfigManager()
                ->getInt(
                    'scoreboard.spawn-welcome-seconds',
                    60
                )
        );
    }

    private function getLeaveSeconds(): int
    {
        return max(
            0,
            $this->main
                ->getConfigManager()
                ->getInt(
                    'scoreboard.spawn-leave-seconds',
                    5
                )
        );
    }

    private function getSpawnRadius(): float
    {
        return $this->main
            ->getConfigManager()
            ->getFloat(
                'scoreboard.spawn-radius',
                3.0
            );
    }

    private function getPlayersPerTick(): int
    {
        return max(
            1,
            $this->main
                ->getConfigManager()
                ->getInt(
                    'scoreboard.players-per-tick',
                    4
                )
        );
    }

    private function getUpdateTicks(): int
    {
        return max(
            1,
            $this->main
                ->getConfigManager()
                ->getInt(
                    'scoreboard.update-ticks',
                    20
                )
        );
    }
}