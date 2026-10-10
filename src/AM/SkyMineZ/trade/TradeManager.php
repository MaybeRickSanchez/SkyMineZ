<?php

declare(strict_types=1);

namespace AM\SkyMineZ\trade;

use AM\SkyMineZ\Main;
use AM\SkyMineZ\config\Messages;
use AM\SkyMineZ\useless\Items;
use AM\SkyMineZ\useless\Positions;
use AM\SkyMineZ\useless\VirtualInventory;
use pocketmine\inventory\Inventory;
use pocketmine\block\VanillaBlocks;
use pocketmine\item\Item;
use pocketmine\math\Vector3;
use pocketmine\player\Player;

/**
 * Chest-style player-to-player trading.
 *
 * One shared 54-slot window per session: slots 0-26 belong to side A, row 3
 * is an untouchable divider, slots 36-53 belong to side B. Both players see
 * everything, each touches only their own side, and any change resets both
 * confirmations — so nobody can swap items after the other side confirmed.
 *
 * Safety properties, all enforced here rather than hoped for:
 *
 *  - items live in the session container, never in a real inventory, until
 *    the moment they change hands;
 *  - both sides must confirm; a single touch afterwards voids both;
 *  - close, cancel, timeout, death and disconnect all return every item to
 *    its owner (offline owners get their items dropped where they stood);
 *  - completing moves each item exactly once, so duplication and loss are
 *    structurally impossible.
 */
final class TradeManager
{
    public const SIDE_A_SLOTS = 27;
    public const DIVIDER_START = 27;
    public const SIDE_B_START = 36;
    public const SIZE = 54;

    /**
     * @var array<int, array{
     *     a: string,
     *     b: string,
     *     inventory: VirtualInventory,
     *     confirmedA: bool,
     *     confirmedB: bool,
     *     expires: int,
     *     returnA: array{world: string, x: float, y: float, z: float, yaw: float, pitch: float},
     *     returnB: array{world: string, x: float, y: float, z: float, yaw: float, pitch: float}
     * }>
     */
    private array $sessions = [];

    /** @var array<string, int> lowercase player name => session id */
    private array $byPlayer = [];

    /**
     * @var array<string, array{from: string, expires: int}> lowercase target => request
     */
    private array $requests = [];

    private int $nextId = 1;

    public function __construct(
        private Main $main
    ) {
        $this->main->getScheduler()->scheduleRepeatingTask(
            new TradeTask($this),
            TradeTask::INTERVAL
        );
    }

    public function getInventory(
        int $id
    ): ?VirtualInventory {
        return $this->sessions[$id]['inventory'] ?? null;
    }

    public function sessionIdOf(
        string $playerName
    ): ?int {
        $id = $this->byPlayer[strtolower($playerName)] ?? null;

        if ($id === null || !isset($this->sessions[$id])) {
            return null;
        }

        return $id;
    }

    public function sideOf(
        int $id,
        string $playerName
    ): ?string {
        $session = $this->sessions[$id] ?? null;

        if ($session === null) {
            return null;
        }

        $playerName = strtolower($playerName);

        if ($session['a'] === $playerName) {
            return 'a';
        }

        if ($session['b'] === $playerName) {
            return 'b';
        }

        return null;
    }

    /**
     * The session id plus shared container for a player, or null when they
     * are not trading. One canonical lookup shared by the transaction guards,
     * which otherwise repeat the same two calls.
     *
     * @return array{int, VirtualInventory}|null
     */
    public function sessionInventoryOf(
        string $playerName
    ): ?array {
        $id = $this->sessionIdOf($playerName);

        if ($id === null) {
            return null;
        }

        $inventory = $this->getInventory($id);

        if ($inventory === null) {
            return null;
        }

        return [$id, $inventory];
    }

    /**
     * The session id when $inventory is that player's trade window, else
     * null. Used by the close handler so closing any other window never
     * cancels a trade.
     */
    public function sessionWindowId(
        string $playerName,
        Inventory $inventory
    ): ?int {
        $session = $this->sessionInventoryOf($playerName);

        if ($session === null || $session[1] !== $inventory) {
            return null;
        }

        return $session[0];
    }

    /**
     * Sends a trade request. Returns false when either side is busy, offline
     * in the relevant sense, or it is a self-trade.
     */
    public function request(
        Player $from,
        string $target
    ): bool {
        $fromName = strtolower($from->getName());
        $targetPlayer = $this->main->getServer()->getPlayerExact($target);

        if ($targetPlayer === null || !$targetPlayer->isConnected()) {
            return false;
        }

        $targetName = strtolower($targetPlayer->getName());

        if ($targetName === $fromName) {
            return false;
        }

        if ($this->sessionIdOf($fromName) !== null) {
            return false;
        }

        if ($this->sessionIdOf($targetName) !== null) {
            return false;
        }

        $this->requests[$targetName] = [
            'from' => $fromName,
            'expires' => time() + $this->requestTtl()
        ];

        $targetPlayer->sendMessage(
            $this->main->getConfigManager()->getPrefix()
            . Messages::get(
                $this->main,
                Messages::TRADE_REQUEST_RECEIVED,
                ['player' => $from->getName()]
            )
        );

        return true;
    }

    public function acceptRequest(
        Player $player
    ): bool {
        $name = strtolower($player->getName());
        $request = $this->requests[$name] ?? null;

        if ($request === null || $request['expires'] < time()) {
            unset($this->requests[$name]);

            return false;
        }

        $from = $this->main->getServer()->getPlayerExact($request['from']);

        unset($this->requests[$name]);

        if ($from === null || !$from->isConnected()) {
            return false;
        }

        if (
            $this->sessionIdOf($name) !== null
            || $this->sessionIdOf($request['from']) !== null
        ) {
            return false;
        }

        return $this->start($from, $player);
    }

    public function denyRequest(
        Player $player
    ): bool {
        $name = strtolower($player->getName());

        if (!isset($this->requests[$name])) {
            return false;
        }

        unset($this->requests[$name]);

        return true;
    }

    /**
     * Confirms the player's side. When both sides confirmed, the trade
     * completes immediately.
     */
    public function confirm(
        Player $player
    ): bool {
        $id = $this->sessionIdOf($player->getName());

        if ($id === null) {
            return false;
        }

        $session = &$this->sessions[$id];

        if ($session['a'] === strtolower($player->getName())) {
            $session['confirmedA'] = true;
        } else {
            $session['confirmedB'] = true;
        }

        if ($session['confirmedA'] && $session['confirmedB']) {
            $this->complete($id);

            return true;
        }

        $other = $this->main->getServer()->getPlayerExact(
            $session['a'] === strtolower($player->getName()) ? $session['b'] : $session['a']
        );

        $other?->sendMessage(
            $this->main->getConfigManager()->getPrefix()
            . Messages::get(
                $this->main,
                Messages::TRADE_CONFIRM_NOTICE,
                ['player' => $player->getName()]
            )
        );

        return true;
    }

    /**
     * Called after any successful change inside the trade window: both
     * confirmations die, because the deal on screen is no longer the deal that
     * was confirmed.
     */
    public function invalidateConfirmations(
        int $id
    ): void {
        if (!isset($this->sessions[$id])) {
            return;
        }

        if (
            !$this->sessions[$id]['confirmedA']
            && !$this->sessions[$id]['confirmedB']
        ) {
            return;
        }

        $this->sessions[$id]['confirmedA'] = false;
        $this->sessions[$id]['confirmedB'] = false;

        foreach (['a', 'b'] as $side) {
            $player = $this->main->getServer()->getPlayerExact(
                $this->sessions[$id][$side]
            );

            $player?->sendMessage(
                $this->main->getConfigManager()->getPrefix()
                . Messages::get($this->main, Messages::TRADE_INVALIDATED)
            );
        }
    }

    public function isConfirmed(
        int $id,
        string $side
    ): bool {
        $session = $this->sessions[$id] ?? null;

        if ($session === null) {
            return false;
        }

        return $side === 'a' ? $session['confirmedA'] : $session['confirmedB'];
    }

    /**
     * Cancels a session and returns everything. Covers close, manual cancel,
     * timeout, death and disconnect alike.
     */
    public function cancel(
        int $id,
        string $reason
    ): void {
        $session = $this->detachSession($id);

        if ($session === null) {
            return;
        }

        $this->closeWindows($session);
        $this->returnOffers($session);

        if ($reason !== '') {
            foreach (['a', 'b'] as $side) {
                $player = $this->main->getServer()->getPlayerExact($session[$side]);

                $player?->sendMessage(
                    $this->main->getConfigManager()->getPrefix() . $reason
                );
            }
        }
    }

    /**
     * Cancels whatever session $playerName is in, if any. Shared by the
     * close/quit/death forwards, which otherwise repeat the same lookup.
     */
    public function cancelByPlayer(
        string $playerName,
        string $reason
    ): void {
        $id = $this->sessionIdOf($playerName);

        if ($id === null) {
            return;
        }

        $this->cancel($id, $reason);
    }

    /**
     * Expiry sweep for requests and sessions. Runs on a slow timer; anything
     * it finds is cancelled with its items returned.
     */
    public function tick(): void
    {
        $now = time();

        foreach ($this->requests as $target => $request) {
            if ($request['expires'] < $now) {
                unset($this->requests[$target]);
            }
        }

        foreach (array_keys($this->sessions) as $id) {
            // Sessions carry their own expiry via the request window plus a
            // hard cap below; idle ones are reaped here.
            $session = $this->sessions[$id] ?? null;

            if ($session === null) {
                continue;
            }

            if ($session['expires'] < $now) {
                $this->cancel(
                    $id,
                    Messages::get($this->main, Messages::TRADE_TIMEOUT)
                );
            }
        }
    }

    private function start(
        Player $a,
        Player $b
    ): bool {
        $inventory = new VirtualInventory($a->getPosition(), self::SIZE);

        $divider = VanillaBlocks::STAINED_GLASS_PANE()->asItem();
        $divider->setCustomName(' ');

        for ($slot = self::DIVIDER_START; $slot < self::SIDE_B_START; ++$slot) {
            $inventory->setItem($slot, clone $divider);
        }

        $id = $this->nextId++;

        $aName = strtolower($a->getName());
        $bName = strtolower($b->getName());

        $this->sessions[$id] = [
            'a' => $aName,
            'b' => $bName,
            'inventory' => $inventory,
            'confirmedA' => false,
            'confirmedB' => false,
            'expires' => time() + $this->sessionTtl(),
            'returnA' => Positions::toArray($a->getLocation()),
            'returnB' => Positions::toArray($b->getLocation())
        ];

        $this->byPlayer[$aName] = $id;
        $this->byPlayer[$bName] = $id;

        // Shared storage, but each side gets its own fake chest at its own
        // current position: the holder is retargeted between the two opens
        // so each ContainerOpen points at a loaded chunk for that viewer.
        // On any half-open the session is cancelled, which closes the other
        // side and restores its fake block, so nobody is left stuck.
        try {
            $windows = $this->main->getVirtualWindow();
            $openedA = $windows->open($a, $inventory, 'Trade');
            $openedB = $openedA && $windows->open($b, $inventory, 'Trade');
        } catch (\Error) {
            $openedA = $a->setCurrentWindow($inventory);
            $openedB = $openedA && $b->setCurrentWindow($inventory);
        }

        if (!$openedA || !$openedB) {
            $this->cancel(
                $id,
                Messages::get($this->main, Messages::TRADE_OPEN_FAIL)
            );

            return false;
        }

        foreach ([$a, $b] as $player) {
            $player->sendMessage(
                $this->main->getConfigManager()->getPrefix()
                . Messages::get($this->main, Messages::TRADE_OPEN)
            );
        }

        return true;
    }

    private function complete(
        int $id
    ): void {
        $session = $this->sessions[$id] ?? null;

        if ($session === null) {
            return;
        }

        $inventory = $session['inventory'];

        [$offersA, $offersB] = $this->collectBoth($inventory);

        if ($offersA === [] && $offersB === []) {
            foreach (['a', 'b'] as $side) {
                $player = $this->main->getServer()->getPlayerExact($session[$side]);

                $player?->sendMessage(
                    $this->main->getConfigManager()->getPrefix()
                    . Messages::get($this->main, Messages::TRADE_EMPTY)
                );
            }

            $session['confirmedA'] = false;
            $session['confirmedB'] = false;
            $this->sessions[$id] = $session;

            return;
        }

        $this->detachSession($id);

        $this->closeWindows($session);

        $playerA = $this->main->getServer()->getPlayerExact($session['a']);
        $playerB = $this->main->getServer()->getPlayerExact($session['b']);

        $this->paySide($session['a'], $session['returnA'], $offersB);
        $this->paySide($session['b'], $session['returnB'], $offersA);

        foreach ([$playerA, $playerB] as $player) {
            $player?->sendMessage(
                $this->main->getConfigManager()->getPrefix()
                . Messages::get($this->main, Messages::TRADE_DONE)
            );
        }
    }

    /**
     * @return list<Item>
     */
    private function collect(
        Inventory $inventory,
        int $from,
        int $to
    ): array {
        $items = [];

        for ($slot = $from; $slot < $to; ++$slot) {
            $item = $inventory->getItem($slot);

            if ($item->isNull()) {
                continue;
            }

            $items[] = clone $item;
            $inventory->clear($slot);
        }

        return $items;
    }

    private function closeWindows(
        array $session
    ): void {
        foreach (['a', 'b'] as $side) {
            $player = $this->main->getServer()->getPlayerExact($session[$side]);

            if (
                $player !== null
                && $player->getCurrentWindow() === $session['inventory']
            ) {
                $player->removeCurrentWindow();
            }
        }
    }

    private function returnOffers(
        array $session
    ): void {
        [$offersA, $offersB] = $this->collectBoth($session['inventory']);

        $this->paySide($session['a'], $session['returnA'], $offersA);
        $this->paySide($session['b'], $session['returnB'], $offersB);
    }

    /**
     * Drains both offer sides at once. One canonical call shared by completion
     * (sides swap owners) and cancellation (each side goes home).
     *
     * @return array{list<Item>, list<Item>} [side A offers, side B offers]
     */
    private function collectBoth(
        Inventory $inventory
    ): array {
        return [
            $this->collect($inventory, 0, self::SIDE_A_SLOTS),
            $this->collect($inventory, self::SIDE_B_START, self::SIZE)
        ];
    }

    /**
     * Forgets a session from both indexes, returning its record. Shared by
     * completion and cancellation so the two can never drift apart.
     *
     * @return array<string, mixed>|null
     */
    private function detachSession(
        int $id
    ): ?array {
        $session = $this->sessions[$id] ?? null;

        if ($session === null) {
            return null;
        }

        unset(
            $this->sessions[$id],
            $this->byPlayer[$session['a']],
            $this->byPlayer[$session['b']]
        );

        return $session;
    }

    /**
     * Hands items to one side: inventory when online, feet-drop at their
     * recorded spot otherwise. One canonical give-or-drop shared by completion
     * and cancellation.
     *
     * @param list<Item> $items
     * @param array{world: string, x: float, y: float, z: float, yaw: float, pitch: float} $returnAt
     */
    private function paySide(
        string $side,
        array $returnAt,
        array $items
    ): void {
        $player = $this->main->getServer()->getPlayerExact($side);

        if ($player !== null && $player->isConnected()) {
            Items::give($player, ...$items);
        } else {
            $this->dropAt($returnAt, $items);
        }
    }

    /**
     * @param list<Item> $items
     */
    private function dropAt(
        array $record,
        array $items
    ): void {
        if ($items === []) {
            return;
        }

        $worldManager = $this->main->getServer()->getWorldManager();

        // One canonical record format (see Positions): a corrupt or orphaned
        // record falls back to the default spawn instead of voiding items.
        $position = Positions::fromArray($record, $worldManager)
            ?? $worldManager->getDefaultWorld()?->getSpawnLocation();

        if ($position === null) {
            return;
        }

        $spot = new Vector3($position->x, $position->y, $position->z);

        foreach ($items as $item) {
            $position->getWorld()->dropItem($spot, $item, new Vector3(0, 0, 0));
        }
    }

    private function requestTtl(): int
    {
        return max(
            10,
            $this->main->getConfigManager()->getInt('trade.request-seconds', 60)
        );
    }

    private function sessionTtl(): int
    {
        return max(
            30,
            $this->main->getConfigManager()->getInt('trade.session-seconds', 300)
        );
    }
}