<?php

declare(strict_types=1);

namespace AM\SkyMineZ\trade;

use AM\SkyMineZ\Main;
use pocketmine\event\EventPriority;
use pocketmine\event\Listener;
use pocketmine\event\inventory\InventoryCloseEvent;
use pocketmine\event\inventory\InventoryTransactionEvent;
use pocketmine\event\player\PlayerDeathEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\inventory\transaction\action\SlotChangeAction;
use pocketmine\plugin\Plugin;

/**
 * Enforces trade window rules and routes every exit through the manager.
 *
 * Two different priorities for two different jobs, registered explicitly like
 * {@link \AM\SkyMineZ\useless\ReadOnlyInventory} does: HIGH cancels illegal
 * touches (wrong side, divider) before they apply; MONITOR observes finished
 * transactions and voids both confirmations when the deal on screen changed.
 * The MONITOR half never mutates anything, so reading there is safe.
 */
final class TradeListener implements Listener
{
    public function __construct(
        private Main $main,
        Plugin $plugin
    ) {
        $manager = $plugin->getServer()->getPluginManager();

        $manager->registerEvent(
            InventoryTransactionEvent::class,
            function(InventoryTransactionEvent $event): void {
                $this->guardTransaction($event);
            },
            EventPriority::HIGH,
            $plugin
        );

        $manager->registerEvent(
            InventoryTransactionEvent::class,
            function(InventoryTransactionEvent $event): void {
                $this->watchTransaction($event);
            },
            EventPriority::MONITOR,
            $plugin
        );

        $manager->registerEvent(
            InventoryCloseEvent::class,
            function(InventoryCloseEvent $event): void {
                $this->onClose($event);
            },
            EventPriority::NORMAL,
            $plugin
        );

        $manager->registerEvent(
            PlayerQuitEvent::class,
            function(PlayerQuitEvent $event): void {
                $this->onQuit($event);
            },
            EventPriority::MONITOR,
            $plugin
        );

        $manager->registerEvent(
            PlayerDeathEvent::class,
            function(PlayerDeathEvent $event): void {
                $this->onDeath($event);
            },
            EventPriority::MONITOR,
            $plugin
        );
    }

    private function guardTransaction(
        InventoryTransactionEvent $event
    ): void {
        if ($event->isCancelled()) {
            return;
        }

        $manager = $this->main->getTradeManager();
        $source = $event->getTransaction()->getSource();

        $session = $manager->sessionInventoryOf($source->getName());

        if ($session === null) {
            return;
        }

        [$id, $inventory] = $session;

        $side = $manager->sideOf($id, $source->getName());

        if ($side === null) {
            return;
        }

        foreach ($event->getTransaction()->getActions() as $action) {
            if (!$action instanceof SlotChangeAction) {
                continue;
            }

            if ($action->getInventory() !== $inventory) {
                continue;
            }

            if (!$this->slotAllowed($action->getSlot(), $side)) {
                $event->cancel();

                $source->sendMessage(
                    $this->main->getConfigManager()->getPrefix()
                    . \AM\SkyMineZ\config\Messages::get($this->main, \AM\SkyMineZ\config\Messages::TRADE_WRONG_SIDE)
                );

                return;
            }
        }
    }

    private function watchTransaction(
        InventoryTransactionEvent $event
    ): void {
        if ($event->isCancelled()) {
            return;
        }

        $manager = $this->main->getTradeManager();
        $source = $event->getTransaction()->getSource();

        $session = $manager->sessionInventoryOf($source->getName());

        if ($session === null) {
            return;
        }

        [$id, $inventory] = $session;

        foreach ($event->getTransaction()->getActions() as $action) {
            if (
                $action instanceof SlotChangeAction
                && $action->getInventory() === $inventory
            ) {
                $manager->invalidateConfirmations($id);

                return;
            }
        }
    }

    private function onClose(
        InventoryCloseEvent $event
    ): void {
        $manager = $this->main->getTradeManager();

        $id = $manager->sessionWindowId(
            $event->getPlayer()->getName(),
            $event->getInventory()
        );

        if ($id === null) {
            return;
        }

        /*
         * Closing cancels. Players close windows to check their own inventory
         * all the time; silently keeping the trade open would strand items in
         * a window nobody is looking at.
         */
        $manager->cancel($id, \AM\SkyMineZ\config\Messages::get($this->main, \AM\SkyMineZ\config\Messages::TRADE_CANCELLED));
    }

    private function onQuit(
        PlayerQuitEvent $event
    ): void {
        $this->main->getTradeManager()->cancelByPlayer(
            $event->getPlayer()->getName(),
            \AM\SkyMineZ\config\Messages::get($this->main, \AM\SkyMineZ\config\Messages::TRADE_CANCEL_QUIT)
        );
    }

    private function onDeath(
        PlayerDeathEvent $event
    ): void {
        $this->main->getTradeManager()->cancelByPlayer(
            $event->getPlayer()->getName(),
            \AM\SkyMineZ\config\Messages::get($this->main, \AM\SkyMineZ\config\Messages::TRADE_CANCEL_DEATH)
        );
    }

    private function slotAllowed(
        int $slot,
        string $side
    ): bool {
        if ($side === 'a') {
            return $slot >= 0 && $slot < TradeManager::SIDE_A_SLOTS;
        }

        return $slot >= TradeManager::SIDE_B_START && $slot < TradeManager::SIZE;
    }
}