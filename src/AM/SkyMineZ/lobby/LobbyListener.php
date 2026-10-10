<?php

declare(strict_types=1);

namespace AM\SkyMineZ\lobby;

use AM\SkyMineZ\Main;
use AM\SkyMineZ\useless\BlockTransactions;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\block\BlockPlaceEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerExhaustEvent;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\player\Player;

/**
 * Guards the lobby and brings players to it.
 *
 * Join teleport: first join always (when a lobby exists), every join only when
 * `lobby.join-teleport` is `always`. Anything else (`never`, typos) behaves
 * like first-join-only, which is the safe direction.
 *
 * Protection, fall damage, void rescue and hunger all key off the same
 * {@link LobbyManager::isGuarded()} check, so the rules apply exactly inside
 * the protected cuboid and normal survival gameplay anywhere else is
 * untouched.
 */
final class LobbyListener implements Listener
{
    public function __construct(
        private Main $main
    ) {
    }

    public function onJoin(
        PlayerJoinEvent $event
    ): void {
        $player = $event->getPlayer();

        $lobby = $this->main->getLobbyManager()->getLobby();

        if ($lobby === null) {
            return;
        }

        $mode = $this->main->getLobbyManager()->joinTeleportMode();

        if ($mode === 'never') {
            return;
        }

        if ($mode === 'always' || !$player->hasPlayedBefore()) {
            $player->teleport($lobby);
        }
    }

    public function onBreak(
        BlockBreakEvent $event
    ): void {
        if ($event->isCancelled()) {
            return;
        }

        $block = $event->getBlock()->getPosition();

        if (
            $this->main->getLobbyManager()->isGuarded(
                $block->getWorld(),
                $block
            )
        ) {
            $event->cancel();
        }
    }

    public function onPlace(
        BlockPlaceEvent $event
    ): void {
        if ($event->isCancelled()) {
            return;
        }

        $manager = $this->main->getLobbyManager();
        $world = $event->getPlayer()->getWorld();

        foreach (BlockTransactions::vectors($event) as $position) {
            if ($manager->isGuarded($world, $position)) {
                $event->cancel();

                return;
            }
        }
    }

    public function onInteract(
        PlayerInteractEvent $event
    ): void {
        if (
            $event->getAction() !== PlayerInteractEvent::RIGHT_CLICK_BLOCK
            || $event->isCancelled()
        ) {
            return;
        }

        $block = $event->getBlock()->getPosition();

        if (
            $this->main->getLobbyManager()->isGuarded(
                $block->getWorld(),
                $block
            )
        ) {
            /*
             * Containers, doors and buttons stay shut in the lobby. The wand
             * and crate/slapper handlers run on the same event; protection
             * cancelling first keeps them from firing inside the lobby.
             */
            $event->cancel();
        }
    }

    public function onDamage(
        EntityDamageEvent $event
    ): void {
        if ($event->isCancelled()) {
            return;
        }

        $entity = $event->getEntity();

        if (!$entity instanceof Player) {
            return;
        }

        $manager = $this->main->getLobbyManager();

        if (
            !$manager->isGuarded(
                $entity->getWorld(),
                $entity->getPosition()
            )
        ) {
            return;
        }

        if (
            $event->getCause() === EntityDamageEvent::CAUSE_FALL
            && $manager->noFallDamage()
        ) {
            $event->cancel();

            return;
        }

        if (
            $event->getCause() === EntityDamageEvent::CAUSE_VOID
            && $manager->voidRescue()
        ) {
            $event->cancel();

            $lobby = $manager->getLobby();

            if ($lobby !== null) {
                $entity->teleport($lobby);
            }
        }
    }

    public function onExhaust(
        PlayerExhaustEvent $event
    ): void {
        if ($event->isCancelled()) {
            return;
        }

        $player = $event->getPlayer();

        if (
            $this->main->getLobbyManager()->noHunger()
            && $this->main->getLobbyManager()->isGuarded(
                $player->getWorld(),
                $player->getPosition()
            )
        ) {
            $event->cancel();
        }
    }
}