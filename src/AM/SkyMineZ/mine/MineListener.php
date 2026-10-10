<?php

declare(strict_types=1);

namespace AM\SkyMineZ\mine;

use AM\SkyMineZ\Main;
use AM\SkyMineZ\useless\BlockTransactions;
use pocketmine\event\Listener;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\block\BlockPlaceEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\math\Vector3;

/**
 * Mining inside mines is the core loop: breaks are allowed, and the block's
 * resource goes straight into the breaker's inventory instead of scattering
 * across the floor (see "mine block rewards"). Placement stays forbidden so
 * mines cannot be griefed or sealed.
 */
final class MineListener implements Listener
{
    public function __construct(
        private Main $main
    ) {
    }

    public function onJoin(PlayerJoinEvent $event): void
    {
        $this->main->getMineManager()->spawnTo(
            $event->getPlayer()
        );
    }

    public function onBreak(
        BlockBreakEvent $event
    ): void {
        if ($event->isCancelled()) {
            return;
        }

        $player = $event->getPlayer();

        if ($player->isCreative()) {
            return;
        }

        $block = $event->getBlock();

        $mine = $this->main->getMineManager()->getMineAt(
            $player->getWorld(),
            $block->getPosition()
        );

        if ($mine === null) {
            return;
        }

        $drops = $block->getDrops(
            $player->getInventory()->getItemInHand()
        );

        if ($drops === []) {
            return;
        }

        /*
         * Suppress the world drop first: the items below are the same drops
         * handed over directly, so this can neither duplicate nor lose them.
         */
        $event->setDrops([]);

        $inventory = $player->getInventory();

        foreach ($drops as $drop) {
            if ($drop->isNull()) {
                continue;
            }

            foreach ($inventory->addItem($drop) as $leftover) {
                $player->getWorld()->dropItem(
                    $player->getPosition(),
                    $leftover,
                    new Vector3(0, 0, 0)
                );
            }
        }
    }

    public function onPlace(
        BlockPlaceEvent $event
    ): void {
        $player = $event->getPlayer();
        $world = $player->getWorld();

        foreach (BlockTransactions::vectors($event) as $position) {
            if (
                $this->main
                    ->getMineManager()
                    ->getMineAt($world, $position) !== null
            ) {
                $event->cancel();

                return;
            }
        }
    }

}