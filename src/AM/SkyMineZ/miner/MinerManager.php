<?php

declare(strict_types=1);

namespace AM\SkyMineZ\miner;

use AM\SkyMineZ\Main;
use AM\SkyMineZ\useless\Arrays;
use pocketmine\utils\Config;

final class MinerManager
{
    /**
     * @var array<string, Miner>
     */
    private array $miners = [];

    private Config $db;

    public function __construct(
        Main $main
    ) {
        $this->db = $main->getMinerDB();
    }

    public function load(string $playerName): Miner
    {
        $playerName = $this->normalizeName($playerName);

        if (isset($this->miners[$playerName])) {
            return $this->miners[$playerName];
        }

        $data = $this->db->get(
            $playerName,
            null
        );

        $miner = new Miner(
            $playerName,
            is_array($data) && Arrays::isStringMap($data)
                ? MinerStates::fromArray($data)
                : new MinerStates()
        );

        $this->miners[$playerName] = $miner;

        return $miner;
    }

    public function save(string $playerName): void
    {
        $playerName = $this->normalizeName($playerName);

        if (!isset($this->miners[$playerName])) {
            return;
        }

        $this->db->set(
            $playerName,
            $this->miners[$playerName]->toArray()
        );
    }

    public function unload(string $playerName): void
    {
        $playerName = $this->normalizeName($playerName);

        unset(
            $this->miners[$playerName]
        );
    }

    /**
     * Writes the stats into the config and drops the player from memory.
     *
     * The file itself is not saved here: {@link saveAll()} writes it during
     * onDisable, which keeps a busy server from hitting the disk on every quit.
     */
    public function saveAndUnload(string $playerName): void
    {
        $this->save($playerName);
        $this->unload($playerName);
    }

    public function saveAll(): void
    {
        foreach ($this->miners as $miner) {
            $this->db->set(
                $miner->getPlayerName(),
                $miner->toArray()
            );
        }

        $this->db->save();
    }

    public function get(string $playerName): ?Miner
    {
        $playerName = $this->normalizeName($playerName);

        return $this->miners[$playerName] ?? null;
    }

    public function getOrLoad(string $playerName): Miner
    {
        return $this->get($playerName)
            ?? $this->load($playerName);
    }

    public function has(string $playerName): bool
    {
        return isset(
            $this->miners[
            $this->normalizeName($playerName)
            ]
        );
    }

    public function isLoaded(string $playerName): bool
    {
        return $this->has($playerName);
    }

    /**
     * @return array<string, Miner>
     */
    public function getAll(): array
    {
        return $this->miners;
    }

    private function normalizeName(string $playerName): string
    {
        return strtolower($playerName);
    }

    /**
     * @return array<string, array{
     *     mined: int,
     *     deaths: int,
     *     kills: int,
     *     killStreak: int
     * }>
     */
    public function getSnapshot(): array
    {
        $result = [];

        foreach ($this->db->getAll() as $playerName => $data) {
            if (
                !is_array($data)
                || !Arrays::isStringMap($data)
            ) {
                continue;
            }

            $states = MinerStates::fromArray($data);

            $result[$this->normalizeName(
                (string) $playerName
            )] = $states->toArray();
        }

        foreach ($this->miners as $miner) {
            $playerName = $miner->getPlayerName();

            $result[$playerName] = [
                'mined' => $miner->getMined(),
                'deaths' => $miner->getDeaths(),
                'kills' => $miner->getKills(),
                'killStreak' => $miner->getKillStreak()
            ];
        }

        return $result;
    }
}