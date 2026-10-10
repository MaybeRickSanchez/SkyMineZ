<?php

declare(strict_types=1);

namespace AM\SkyMineZ\quest;

use AM\SkyMineZ\Main;
use AM\SkyMineZ\config\Messages;
use AM\SkyMineZ\ui\Ui;
use AM\SkyMineZ\useless\NumberFormatter;
use pocketmine\player\Player;

/**
 * The daily quest window: every quest with a progress bar, and a claim button
 * on each finished, unclaimed one.
 *
 * Claiming goes through {@link QuestManager::claim()}, which marks the reward
 * before granting it — pressing the button twice (or on two devices at once)
 * can never pay out twice.
 */
final class QuestMenuForm
{
    public function __construct(
        private Main $plugin
    ) {
    }

    public function send(
        Player $player
    ): bool {
        $manager = $this->plugin->getQuestManager();
        $quests = $manager->getQuests();

        if ($quests === []) {
            Ui::error($this->plugin, $player, Messages::get($this->plugin, Messages::QUEST_NONE));

            return true;
        }

        $handlers = [];
        $lines = ['§7New quests every day. Progress resets at midnight.'];

        foreach ($quests as $id => $quest) {
            $progress = $manager->progressOf($player->getName(), $id);
            $claimed = $manager->isClaimed($player->getName(), $id);
            $complete = $manager->isComplete($player->getName(), $id);

            $state = $claimed
                ? '§8Claimed'
                : ($complete ? '§aClaim!' : '§7' . $progress . '/' . $quest['target']);

            $handlers['§e' . $quest['name'] . ' §8- ' . $state] =
                function(Player $who) use ($id, $claimed, $complete): void {
                    if ($claimed || !$complete) {
                        $this->send($who);

                        return;
                    }

                    if (!$this->plugin->getQuestManager()->claim($who->getName(), $id)) {
                        Ui::error($this->plugin, $who, Messages::get($this->plugin, Messages::QUEST_UNAVAILABLE));

                        return;
                    }

                    Ui::success($this->plugin, $who, Messages::get($this->plugin, Messages::QUEST_CLAIMED));
                    $this->send($who);
                };

            $lines[] = '§f' . $quest['name'] . ' §8| §7' . $quest['desc'];
            $lines[] = '§8' . NumberFormatter::bar(
                $quest['target'] > 0 ? $progress / $quest['target'] : 0.0
            ) . ' §7' . min($progress, $quest['target']) . '/' . $quest['target'];
        }

        return Ui::menu(
            $this->plugin,
            $player,
            'Daily quests',
            implode("\n", $lines),
            $handlers
        );
    }
}