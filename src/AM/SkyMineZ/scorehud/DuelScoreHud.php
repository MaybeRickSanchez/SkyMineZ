<?php

declare(strict_types=1);

namespace AM\SkyMineZ\scorehud;

use AM\SkyMineZ\Main;
use AM\SkyMineZ\useless\NumberFormatter;
use pocketmine\player\Player;

/**
 * The sidebar players see while they fight in a live team duel.
 *
 * Same stat placeholders as the lobby board plus the duel ones: {team} is
 * the viewer's own team, {enemy} the other side, {duel_time} the time left.
 * Everything comes from `scoreboard.duel.*` in config.yml.
 */
final class DuelScoreHud
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
                'scoreboard.duel.title',
                '§c§lDuel'
            );
    }

    /**
     * @return list<string>
     */
    public function getLines(
        Player $player
    ): array {
        $battle = $this->main->getTeamManager()->getBattleFor(
            $player->getName()
        );

        $placeholders = ScorePlaceholders::stats($this->main, $player);

        $placeholders['team'] = $battle['own'] ?? $player->getName();
        $placeholders['enemy'] = $battle['enemy'] ?? '?';
        $placeholders['duel_time'] = $battle === null
            ? '0s'
            : NumberFormatter::duration(
                max(0, $battle['endsAt'] - time())
            );

        return ScorePlaceholders::expand(
            $placeholders,
            $this->main->getConfigManager()->getStringList(
                'scoreboard.duel.lines'
            )
        );
    }
}
