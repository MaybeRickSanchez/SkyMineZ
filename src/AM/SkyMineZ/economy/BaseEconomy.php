<?php

declare(strict_types=1);

namespace AM\SkyMineZ\economy;

use AM\SkyMineZ\event\EconomyChangeEvent;
use pocketmine\utils\Config;

/**
 * JSON-file backed currency.
 *
 * Balances are kept in memory for online players only; everything else is read
 * from and written to the underlying file. Balances never go below zero, and
 * every mutation raises {@link EconomyChangeEvent} so other plugins can observe
 * or veto it.
 */
abstract class BaseEconomy implements Economy
{
    /** @var array<string, int> */
    protected array $balances = [];

    public function __construct(
        protected Config $database,
        private int $defaultBalance = 0
    ) {
    }

    public function getType(): string
    {
        return $this->type;
    }

    /**
     * @var string
     */
    protected string $type = 'balance';

    public function setDefaultBalance(int $defaultBalance): void
    {
        $this->defaultBalance = max(
            0,
            $defaultBalance
        );
    }

    public function loadPlayer(string $playerName): void
    {
        $playerName = $this->normalizeName($playerName);

        if (isset($this->balances[$playerName])) {
            return;
        }

        $stored = $this->database->get(
            $playerName,
            null
        );

        $this->balances[$playerName] = is_numeric($stored)
            ? max(0, (int) $stored)
            : $this->defaultBalance;
    }

    public function savePlayer(string $playerName): void
    {
        $playerName = $this->normalizeName($playerName);

        if (!isset($this->balances[$playerName])) {
            return;
        }

        $this->database->set(
            $playerName,
            $this->balances[$playerName]
        );
    }

    public function unloadPlayer(string $playerName): void
    {
        $playerName = $this->normalizeName($playerName);

        if (!isset($this->balances[$playerName])) {
            return;
        }

        $this->savePlayer($playerName);

        unset($this->balances[$playerName]);
    }

    public function saveAll(): void
    {
        foreach (
            $this->balances as $playerName => $balance
        ) {
            $this->database->set(
                $playerName,
                $balance
            );
        }

        $this->database->save();
    }

    public function isLoaded(string $playerName): bool
    {
        return isset(
            $this->balances[
            $this->normalizeName($playerName)
            ]
        );
    }

    public function get(string $playerName): int
    {
        return $this->balances[
            $this->normalizeName($playerName)
        ] ?? 0;
    }

    public function set(
        string $playerName,
        int $amount,
        string $reason = EconomyChangeEventReason::SET
    ): void {
        $playerName = $this->normalizeName($playerName);

        $newBalance = max(0, $amount);

        if (!$this->applyChange(
            $playerName,
            $newBalance,
            $reason
        )) {
            return;
        }

        $this->balances[$playerName] = $newBalance;
    }

    public function add(
        string $playerName,
        int $amount,
        string $reason = EconomyChangeEventReason::CREDITS
    ): int {
        $playerName = $this->normalizeName($playerName);

        $newBalance = max(
            0,
            ($this->balances[$playerName] ?? 0) + $amount
        );

        if (!$this->applyChange(
            $playerName,
            $newBalance,
            $amount < 0 ? EconomyChangeEventReason::DEBITS : $reason
        )) {
            return $this->get($playerName);
        }

        $this->balances[$playerName] = $newBalance;

        return $newBalance;
    }

    public function reduce(
        string $playerName,
        int $amount,
        string $reason = EconomyChangeEventReason::DEBITS
    ): int {
        if ($amount <= 0) {
            return $this->get($playerName);
        }

        return $this->add(
            $playerName,
            -$amount,
            $reason
        );
    }

    public function has(string $playerName, int $amount): bool
    {
        if ($amount < 0) {
            return false;
        }

        return $this->get($playerName) >= $amount;
    }

    /**
     * Every stored balance, including players who are currently offline. Used
     * by the leaderboards, which therefore read the whole file.
     *
     * @return array<string, int>
     */
    public function getSnapshot(): array
    {
        $result = [];

        foreach (
            $this->database->getAll() as $playerName => $amount
        ) {
            if (!is_numeric($amount)) {
                continue;
            }

            $result[
                $this->normalizeName((string) $playerName)
            ] = (int) $amount;
        }

        foreach (
            $this->balances as $playerName => $balance
        ) {
            $result[$playerName] = $balance;
        }

        return $result;
    }

    protected function normalizeName(
        string $playerName
    ): string {
        return strtolower($playerName);
    }

    /**
     * Raises {@link EconomyChangeEvent} and reports whether the change may go
     * through.
     */
    private function applyChange(
        string $playerName,
        int $newBalance,
        string $reason
    ): bool {
        $oldBalance = $this->get($playerName);

        if ($oldBalance === $newBalance) {
            return true;
        }

        if (!EconomyChangeEvent::hasHandlers()) {
            return true;
        }

        $event = new EconomyChangeEvent(
            $this,
            $playerName,
            $oldBalance,
            $newBalance,
            $reason
        );

        $event->call();

        return !$event->isCancelled();
    }
}