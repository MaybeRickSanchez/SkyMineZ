<?php

declare(strict_types=1);

namespace AM\SkyMineZ\event;

use pocketmine\player\Player;

/**
 * Raised after a player's PvP preference changed, including the change that
 * happened when their stored value was loaded on join. Cancelling it reverts
 * the preference.
 */
final class PlayerPvPChangeEvent extends CancellableSkyMineEvent
{
    public function __construct(
        private Player $player,
        private bool $oldState,
        private bool $newState
    ) {
    }

    public function getPlayer(): Player
    {
        return $this->player;
    }

    public function getOldState(): bool
    {
        return $this->oldState;
    }

    public function getNewState(): bool
    {
        return $this->newState;
    }
}