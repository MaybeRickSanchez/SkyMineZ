<?php

declare(strict_types=1);

namespace AM\SkyMineZ\event;

use AM\SkyMineZ\economy\Economy;
use AM\SkyMineZ\economy\EconomyChangeEventReason;

/**
 * Raised after a balance changed. Cancelling it reverts the balance, which makes
 * it safe for listeners to enforce their own economy rules.
 *
 * "reason" is a free-form tag such as "crate", "outpost", "command" or "admin"
 * (see {@link EconomyChangeEventReason} for the built-in tags).
 */
final class EconomyChangeEvent extends CancellableSkyMineEvent
{
    public function __construct(
        private Economy $economy,
        private string $playerName,
        private int $oldBalance,
        private int $newBalance,
        private string $reason = EconomyChangeEventReason::CREDITS
    ) {
    }

    public function getEconomy(): Economy
    {
        return $this->economy;
    }

    public function getPlayerName(): string
    {
        return $this->playerName;
    }

    public function getOldBalance(): int
    {
        return $this->oldBalance;
    }

    public function getNewBalance(): int
    {
        return $this->newBalance;
    }

    public function getDelta(): int
    {
        return $this->newBalance - $this->oldBalance;
    }

    public function getReason(): string
    {
        return $this->reason;
    }
}