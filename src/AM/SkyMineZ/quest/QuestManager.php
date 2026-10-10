<?php

declare(strict_types=1);

namespace AM\SkyMineZ\quest;

use AM\SkyMineZ\economy\EconomyChangeEventReason;
use AM\SkyMineZ\event\EconomyChangeEvent;
use AM\SkyMineZ\Main;
use pocketmine\event\EventPriority;
use AM\SkyMineZ\useless\Arrays;
use pocketmine\utils\Config;

/**
 * Daily quests: configured challenges that reset every day, with tracked
 * progress, completion detection and one-time-per-day rewards.
 *
 * Quest definitions live in config.yml (`quests.list`); player state lives in
 * quests.json keyed by day, so a date change automatically starts a fresh
 * sheet — that is the entire daily-reset mechanism, no timer needed.
 *
 * Progress is kept in memory and flushed on quit, claim and shutdown (the same
 * durability contract as miner stats). Rewards go through the economy
 * managers, never around them, so no money is ever created off the books.
 */
final class QuestManager
{
    public const TYPE_MINE = 'mine';
    public const TYPE_KILL = 'kill';
    public const TYPE_EARN = 'earn';

    private const FILE_NAME = 'quests.json';

    /**
     * @var array<string, array{date: string, progress: array<string, int>, claimed: list<string>}>
     */
    private array $sheets = [];

    /** @var list<array{id: string, name: string, desc: string, type: string, target: int, rewardMoney: int, rewardGold: int}>|null */
    private ?array $definitions = null;

    private Config $db;

    public function __construct(
        private Main $main
    ) {
        $this->db = new Config(
            $this->main->getDataFolder() . self::FILE_NAME,
            Config::JSON
        );

        /*
         * MONITOR runs after every potential veto, so the counted amount is
         * always the amount that really landed. Read-only: this never touches
         * the event itself.
         */
        $this->main->getServer()->getPluginManager()->registerEvent(
            EconomyChangeEvent::class,
            function(EconomyChangeEvent $event): void {
                if (
                    $event->isCancelled()
                    || $event->getDelta() <= 0
                    || $event->getEconomy()->getType() !== 'money'
                ) {
                    return;
                }

                $this->addProgress(
                    $event->getPlayerName(),
                    self::TYPE_EARN,
                    $event->getDelta()
                );
            },
            EventPriority::MONITOR,
            $this->main
        );
    }

    public function load(): void
    {
        $this->sheets = [];
        $this->definitions = null;
    }

    public function saveAll(): void
    {
        $data = [];

        foreach ($this->sheets as $player => $sheet) {
            $data[$player] = $this->serializeSheet($sheet);
        }

        $this->db->setAll($data);
        $this->db->save();
    }

    public function savePlayer(
        string $playerName
    ): void {
        $key = strtolower($playerName);

        if (!isset($this->sheets[$key])) {
            return;
        }

        $this->db->set($key, $this->serializeSheet($this->sheets[$key]));
        $this->db->save();
    }

    /**
     * One canonical sheet record shared by single and bulk saves.
     *
     * @param array{date: string, progress: array<string, int>, claimed: list<string>} $sheet
     *
     * @return array{date: string, progress: array<string, int>, claimed: list<string>}
     */
    private function serializeSheet(
        array $sheet
    ): array {
        return [
            'date' => $sheet['date'],
            'progress' => $sheet['progress'],
            'claimed' => array_values($sheet['claimed'])
        ];
    }

    public function unloadPlayer(
        string $playerName
    ): void {
        $this->savePlayer($playerName);

        unset($this->sheets[strtolower($playerName)]);
    }

    /**
     * Validated quest definitions from config, keyed by id.
     *
     * @return array<string, array{id: string, name: string, desc: string, type: string, target: int, rewardMoney: int, rewardGold: int}>
     */
    public function getQuests(): array
    {
        if ($this->definitions !== null) {
            return $this->definitions;
        }

        $result = [];

        $list = $this->main->getConfigManager()->get('quests.list', []);

        if (!is_array($list)) {
            $this->definitions = [];

            return [];
        }

        foreach ($list as $entry) {
            if (!is_array($entry) || !Arrays::isStringMap($entry)) {
                continue;
            }

            $id = isset($entry['id']) && is_string($entry['id']) ? trim($entry['id']) : '';

            if ($id === '' || isset($result[$id])) {
                continue;
            }

            $type = strtolower((string) ($entry['type'] ?? ''));

            if (!in_array($type, [self::TYPE_MINE, self::TYPE_KILL, self::TYPE_EARN], true)) {
                continue;
            }

            $target = isset($entry['target']) && is_numeric($entry['target'])
                ? max(1, (int) $entry['target'])
                : 0;

            if ($target <= 0) {
                continue;
            }

            $result[$id] = [
                'id' => $id,
                'name' => isset($entry['name']) && is_string($entry['name']) && $entry['name'] !== ''
                    ? $entry['name']
                    : $id,
                'desc' => isset($entry['desc']) && is_string($entry['desc']) ? $entry['desc'] : '',
                'type' => $type,
                'target' => $target,
                'rewardMoney' => isset($entry['reward-money']) && is_numeric($entry['reward-money'])
                    ? max(0, (int) $entry['reward-money'])
                    : 0,
                'rewardGold' => isset($entry['reward-gold']) && is_numeric($entry['reward-gold'])
                    ? max(0, (int) $entry['reward-gold'])
                    : 0
            ];
        }

        $this->definitions = $result;

        return $result;
    }

    public function reloadDefinitions(): void
    {
        $this->definitions = null;
    }

    /**
     * Today's sheet for a player, resetting yesterday's on date change.
     *
     * @return array{date: string, progress: array<string, int>, claimed: list<string>}
     */
    public function sheet(
        string $playerName
    ): array {
        $key = strtolower($playerName);
        $today = date('Y-m-d');

        if (
            !isset($this->sheets[$key])
            || $this->sheets[$key]['date'] !== $today
        ) {
            $stored = null;
            $raw = $this->db->get($key);

            if (is_array($raw) && Arrays::isStringMap($raw)) {
                $stored = $raw;
            }

            $progress = [];
            $claimed = [];

            if (
                $stored !== null
                && ($stored['date'] ?? null) === $today
                && isset($stored['progress']) && is_array($stored['progress'])
            ) {
                foreach ($stored['progress'] as $questId => $amount) {
                    if (is_string($questId) && is_numeric($amount)) {
                        $progress[$questId] = max(0, (int) $amount);
                    }
                }

                foreach ((array) ($stored['claimed'] ?? []) as $questId) {
                    if (is_string($questId)) {
                        $claimed[] = $questId;
                    }
                }
            }

            $this->sheets[$key] = [
                'date' => $today,
                'progress' => $progress,
                'claimed' => array_values(array_unique($claimed))
            ];
        }

        return $this->sheets[$key];
    }

    public function progressOf(
        string $playerName,
        string $questId
    ): int {
        $sheet = $this->sheet($playerName);

        return $sheet['progress'][$questId] ?? 0;
    }

    public function isComplete(
        string $playerName,
        string $questId
    ): bool {
        $quests = $this->getQuests();

        if (!isset($quests[$questId])) {
            return false;
        }

        return $this->progressOf($playerName, $questId) >= $quests[$questId]['target'];
    }

    public function isClaimed(
        string $playerName,
        string $questId
    ): bool {
        return in_array($questId, $this->sheet($playerName)['claimed'], true);
    }

    /**
     * Adds progress to every matching quest. Amounts cap at the target, and
     * quests already claimed today stop accumulating.
     */
    public function addProgress(
        string $playerName,
        string $type,
        int $amount = 1
    ): void {
        if ($amount <= 0) {
            return;
        }

        $quests = $this->getQuests();

        if ($quests === []) {
            return;
        }

        $sheet = $this->sheet($playerName);
        $changed = false;

        foreach ($quests as $id => $quest) {
            if ($quest['type'] !== $type) {
                continue;
            }

            if (in_array($id, $sheet['claimed'], true)) {
                continue;
            }

            $current = $sheet['progress'][$id] ?? 0;

            if ($current >= $quest['target']) {
                continue;
            }

            $sheet['progress'][$id] = min($quest['target'], $current + $amount);
            $changed = true;
        }

        if ($changed) {
            $this->sheets[strtolower($playerName)] = $sheet;
        }
    }

    /**
     * Claims a completed quest exactly once per day: the claimed flag is
     * written before any reward is granted, and rewards flow through the
     * economy managers. Double claims are impossible even if two claim
     * attempts race, because the second sees the flag.
     */
    public function claim(
        string $playerName,
        string $questId
    ): bool {
        $key = strtolower($playerName);
        $quests = $this->getQuests();

        if (!isset($quests[$questId])) {
            return false;
        }

        $sheet = $this->sheet($playerName);

        if (
            in_array($questId, $sheet['claimed'], true)
            || ($sheet['progress'][$questId] ?? 0) < $quests[$questId]['target']
        ) {
            return false;
        }

        $sheet['claimed'][] = $questId;
        $this->sheets[$key] = $sheet;

        $this->savePlayer($playerName);

        $quest = $quests[$questId];

        if ($quest['rewardMoney'] > 0) {
            $this->main->getMoneyEconomy()->add(
                $playerName,
                $quest['rewardMoney'],
                EconomyChangeEventReason::COMMAND
            );
        }

        if ($quest['rewardGold'] > 0) {
            $this->main->getGoldEconomy()->add(
                $playerName,
                $quest['rewardGold'],
                EconomyChangeEventReason::COMMAND
            );
        }

        return true;
    }
}