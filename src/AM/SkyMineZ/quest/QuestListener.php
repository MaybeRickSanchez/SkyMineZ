<?php

declare(strict_types=1);

namespace AM\SkyMineZ\quest;

use AM\SkyMineZ\event\MinerBlockMinedEvent;
use AM\SkyMineZ\Main;
use AM\SkyMineZ\useless\Combat;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerDeathEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerQuitEvent;

/**
 * Feeds gameplay into quest progress: mined blocks, player kills and money
 * earned.
 *
 * Breaks and kills arrive through gameplay events; earnings arrive through a
 * MONITOR hook on {@link EconomyChangeEvent} registered by the manager (not
 * here), because only MONITOR sees the final, uncancelled amount. Nothing here
 * modifies any event — quest tracking is strictly read-only.
 */
final class QuestListener implements Listener
{
    public function __construct(
        private Main $main
    ) {
    }

    public function onJoin(
        PlayerJoinEvent $event
    ): void {
        // Touch the sheet so a new day starts fresh on first sight, not mid-play.
        $this->main->getQuestManager()->sheet(
            $event->getPlayer()->getName()
        );
    }

    public function onQuit(
        PlayerQuitEvent $event
    ): void {
        $this->main->getQuestManager()->unloadPlayer(
            $event->getPlayer()->getName()
        );
    }

    public function onMined(
        MinerBlockMinedEvent $event
    ): void {
        if ($event->isCancelled()) {
            return;
        }

        $this->main->getQuestManager()->addProgress(
            $event->getPlayerName(),
            QuestManager::TYPE_MINE,
            $event->getAmount()
        );
    }

    public function onDeath(
        PlayerDeathEvent $event
    ): void {
        $killer = Combat::resolveKiller($event->getPlayer());

        if ($killer === null) {
            return;
        }

        $this->main->getQuestManager()->addProgress(
            $killer->getName(),
            QuestManager::TYPE_KILL
        );
    }
}