<?php

declare(strict_types=1);

namespace AM\SkyMineZ\scorehud;

use AM\SkyMineZ\Main;
use pocketmine\player\Player;

/**
 * The sidebar players see once they leave the spawn area.
 *
 * Every line comes from `scoreboard.lobby.lines` in config.yml, with
 * {placeholders} replaced. A missing placeholder is left as-is so a typo is
 * visible in-game instead of silently vanishing.
 */
final class LobbyScoreHud
{
    public function __construct(
        private Main $main
    ) {
    }

    public function getTitle(): string
    {
        return $this->main
            ->getConfigManager()
            ->getString(
                'scoreboard.lobby.title',
                '§d§lSkyMine'
            );
    }

    /**
     * @return list<string>
     */
    public function getLines(
        Player $player
    ): array {
        return ScorePlaceholders::expand(
            ScorePlaceholders::stats($this->main, $player),
            $this->main->getConfigManager()->getStringList(
                'scoreboard.lobby.lines'
            )
        );
    }
}