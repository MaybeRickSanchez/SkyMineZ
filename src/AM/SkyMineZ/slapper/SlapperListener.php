<?php

declare(strict_types=1);

namespace AM\SkyMineZ\slapper;

use AM\SkyMineZ\event\SlapperInteractEvent;
use AM\SkyMineZ\Main;
use pocketmine\event\Listener;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\player\PlayerEntityInteractEvent;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\player\Player;
use pocketmine\scheduler\ClosureTask;

/**
 * Wires slappers to player interaction.
 *
 * A slapper is an invisible NPC with a name tag that runs commands and prints
 * messages when right-clicked. Slapper blocks are the same thing attached to a
 * block in the world, so a right-click on their block runs the parent slapper.
 *
 * Both are invulnerable and neither may be broken by hand; use the commands.
 */
final class SlapperListener implements Listener
{
    public function __construct(
        private SlapperManager $manager,
        private Main $main
    ) {
    }

    public function onSlapperInteract(
        PlayerEntityInteractEvent $event
    ): void {
        $slapper = $this->manager->getByEntity(
            $event->getEntity()
        );

        if ($slapper === null) {
            return;
        }

        $event->cancel();

        $player = $event->getPlayer();

        $this->interact($slapper, $player);
    }

    public function onSlapperDamage(
        EntityDamageByEntityEvent $event
    ): void {
        if (
            $this->manager->getByEntity(
                $event->getEntity()
            ) === null
        ) {
            return;
        }

        $event->cancel();
    }

    public function onBlockInteract(
        PlayerInteractEvent $event
    ): void {
        if (
            $event->getAction() !==
            PlayerInteractEvent::RIGHT_CLICK_BLOCK
        ) {
            return;
        }

        $slapperBlock = $this->manager->getBlockAt(
            $event->getBlock()
                ->getPosition()
        );

        if ($slapperBlock === null) {
            return;
        }

        $event->cancel();

        $slapper = $this->manager->getSlapper(
            $slapperBlock->getSlapperName()
        );

        if ($slapper === null) {
            return;
        }

        $player = $event->getPlayer();

        $this->interact($slapper, $player);
    }

    public function onBlockBreak(
        BlockBreakEvent $event
    ): void {
        if (
            $this->manager->getBlockAt(
                $event->getBlock()
                    ->getPosition()
            ) === null
        ) {
            return;
        }

        $event->cancel();
    }

    public function onJoin(
        PlayerJoinEvent $event
    ): void {
        $player = $event->getPlayer();

        /*
         * Chunk data is still arriving on the join tick, so spawning the entities
         * and the labels has to wait a moment. This is also why the manager does
         * not respawn everything: only the player that just arrived needs the
         * packets.
         */
        $this->main->getScheduler()->scheduleDelayedTask(
            new ClosureTask(
                function() use ($player): void {
                    if ($player->isConnected()) {
                        $this->manager->spawnTo($player);
                    }
                }
            ),
            1
        );
    }

    /**
     * Raises {@link SlapperInteractEvent} and runs the slapper unless a
     * listener cancelled the interaction. Shared by entity and block
     * right-clicks, which otherwise repeat the same tail.
     */
    private function interact(
        Slapper $slapper,
        Player $player
    ): void {
        if (!$this->dispatch(
            $slapper,
            $player
        )) {
            return;
        }

        $slapper->execute($player);
    }

    /**
     * Raises {@link SlapperInteractEvent}.
     *
     * @return bool false when a listener cancelled the interaction
     */
    private function dispatch(
        Slapper $slapper,
        Player $player
    ): bool {
        if (!SlapperInteractEvent::hasHandlers()) {
            return true;
        }

        $event = new SlapperInteractEvent(
            $slapper,
            $player
        );

        $event->call();

        return !$event->isCancelled();
    }
}