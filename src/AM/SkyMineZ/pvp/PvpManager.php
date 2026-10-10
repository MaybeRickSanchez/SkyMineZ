<?php

declare(strict_types=1);

namespace AM\SkyMineZ\pvp;

use AM\SkyMineZ\event\PlayerPvPChangeEvent;
use AM\SkyMineZ\Main;

/**
 * Per-player PvP preference, stored in plugin_data/pvp.json.
 *
 * Changing a preference raises {@link PlayerPvPChangeEvent} so a listener can
 * veto it (region protection, minigames, arenas).
 */
final class PvpManager
{
    private Main $main;

    /**
     * @var array<string, bool>
     */
    private array $states = [];

    public function __construct(Main $main)
    {
        $this->main = $main;
    }

    public function loadPlayer(
        string $playerName
    ): bool {
        $playerName = strtolower($playerName);

        if (isset($this->states[$playerName])) {
            return $this->states[$playerName];
        }

        $stored = $this->main
            ->getPvpDB()
            ->get($playerName, null);

        $default = $this->main
            ->getConfigManager()
            ->getBool('pvp.default-enabled', true);

        $state = is_bool($stored) ? $stored : $default;

        $this->states[$playerName] = $state;

        return $state;
    }

    public function savePlayer(
        string $playerName
    ): void {
        $playerName = strtolower($playerName);

        if (!isset($this->states[$playerName])) {
            return;
        }

        $this->main
            ->getPvpDB()
            ->set(
                $playerName,
                $this->states[$playerName]
            );
    }

    public function saveAll(): void
    {
        $db = $this->main->getPvpDB();

        foreach (
            $this->states as $playerName => $state
        ) {
            $db->set($playerName, $state);
        }

        $db->save();
    }

    public function unloadPlayer(
        string $playerName
    ): void {
        $this->savePlayer($playerName);

        unset(
            $this->states[strtolower($playerName)]
        );
    }

    public function getState(
        string $playerName
    ): bool {
        $playerName = strtolower($playerName);

        if (isset($this->states[$playerName])) {
            return $this->states[$playerName];
        }

        return $this->main
            ->getConfigManager()
            ->getBool('pvp.default-enabled', true);
    }

    /**
     * @return bool the state after the change; unchanged when vetoed
     */
    public function setState(
        string $playerName,
        bool $state
    ): bool {
        $playerName = strtolower($playerName);

        $previous = $this->getState($playerName);

        if ($previous === $state) {
            $this->states[$playerName] = $state;

            return $state;
        }

        $player = $this->main->getServer()->getPlayerExact(
            $playerName
        );

        if (
            $player !== null
            && PlayerPvPChangeEvent::hasHandlers()
        ) {
            $event = new PlayerPvPChangeEvent(
                $player,
                $previous,
                $state
            );

            $event->call();

            if ($event->isCancelled()) {
                return $previous;
            }
        }

        $this->states[$playerName] = $state;

        return $state;
    }

    /**
     * Flips the preference and returns the new value.
     */
    public function toggle(
        string $playerName
    ): bool {
        return $this->setState(
            $playerName,
            !$this->getState($playerName)
        );
    }

    /**
     * PvP between two players is only allowed when both have it enabled.
     */
    public function canDamage(
        string $attacker,
        string $victim
    ): bool {
        if (
            strcasecmp($attacker, $victim) === 0
        ) {
            return false;
        }

        return $this->getState($attacker)
            && $this->getState($victim);
    }
}