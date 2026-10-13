<?php

declare(strict_types=1);

namespace AM\SkyMineZ\scorehud;

use AM\SkyMineZ\Main;
use AM\SkyMineZ\useless\NumberFormatter;
use pocketmine\player\Player;

/**
 * One canonical placeholder table for every sidebar layout.
 *
 * Each board used to build its own search/replace pair inline; the stat
 * half was identical in all of them and drifted whenever a key was added to
 * one board but not the others. Layouts now share {@link stats()} and only
 * add their own extra keys (duel info, ...).
 *
 * Unknown placeholders are left as-is so a typo in config.yml stays visible
 * in-game instead of silently vanishing.
 */
final class ScorePlaceholders
{
    private function __construct()
    {
    }

    /**
     * @return array<string, string>
     */
    public static function stats(
        Main $main,
        Player $player
    ): array {
        $name = $player->getName();

        $miner = $main->getMinerManager()->getOrLoad(
            $name
        );

        return [
            'name' => $name,
            'prefix_name' => $name,
            'gold' => NumberFormatter::short(
                $main->getGoldEconomy()->get($name)
            ),
            'money' => NumberFormatter::short(
                $main->getMoneyEconomy()->get($name)
            ),
            'mined' => NumberFormatter::short(
                $miner->getMined()
            ),
            'deaths' => NumberFormatter::short(
                $miner->getDeaths()
            ),
            'kills' => NumberFormatter::short(
                $miner->getKills()
            ),
            'kill_streak' => (string) $miner->getKillStreak(),
            'pvp' => $main->getPvpManager()->getState(
                $name
            ) ? 'ON' : 'OFF',
            'server_address' => ServerAddress::of($main)
        ];
    }

    /**
     * @param array<string, string> $placeholders
     * @param list<string> $lines
     *
     * @return list<string>
     */
    public static function expand(
        array $placeholders,
        array $lines
    ): array {
        /*
         * The search/replace pair is built once per pass, not once per line:
         * the old code allocated three arrays plus a closure per line.
         */
        $search = [];
        $replace = [];

        foreach ($placeholders as $key => $value) {
            $search[] = '{' . $key . '}';
            $replace[] = $value;
        }

        $result = [];

        foreach ($lines as $line) {
            $result[] = str_replace(
                $search,
                $replace,
                $line
            );
        }

        return $result;
    }
}
