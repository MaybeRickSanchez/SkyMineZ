<?php

declare(strict_types=1);

namespace AM\SkyMineZ\useless;

use pocketmine\event\EventPriority;
use pocketmine\event\inventory\InventoryCloseEvent;
use pocketmine\event\inventory\InventoryTransactionEvent;
use pocketmine\inventory\Inventory;
use pocketmine\inventory\transaction\action\SlotChangeAction;
use pocketmine\player\Player;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\plugin\PluginBase;
use pocketmine\Server;

final class ReadOnlyInventory
{
    /**
     * spl_object_id() values are reused after garbage collection, so the object
     * itself is stored too and identity-checked on lookup: a different inventory
     * that happens to reuse an id must never inherit the read-only flag (for
     * example when a crate chunk unloads mid-preview).
     *
     * @var array<int, Inventory>
     */
    private array $inventories = [];

    public function __construct(
        Server $server,
        PluginBase $plugin,
        private ?VirtualWindow $virtualWindow = null
    ) {
        $pluginManager = $server->getPluginManager();

        $pluginManager->registerEvent(
            InventoryTransactionEvent::class,
            function (InventoryTransactionEvent $event): void {
                foreach ($event->getTransaction()->getActions() as $action) {
                    if (
                        !$action instanceof SlotChangeAction
                    ) {
                        continue;
                    }

                    if (
                        $this->isReadOnly(
                            $action->getInventory()
                        )
                    ) {
                        $event->cancel();

                        return;
                    }
                }
            },
            EventPriority::HIGHEST,
            $plugin
        );

        $pluginManager->registerEvent(
            InventoryCloseEvent::class,
            function (InventoryCloseEvent $event): void {
                $this->remove(
                    $event->getInventory()
                );
            },
            EventPriority::MONITOR,
            $plugin
        );

        /*
         * A forced disconnect never raises InventoryCloseEvent, so the tracked
         * inventories would stay locked forever. Release whatever the quitting
         * player still had open.
         */
        $pluginManager->registerEvent(
            PlayerQuitEvent::class,
            function (PlayerQuitEvent $event): void {
                $current = $event->getPlayer()->getCurrentWindow();

                if ($current !== null) {
                    $this->remove($current);
                }
            },
            EventPriority::MONITOR,
            $plugin
        );
    }

    public function add(
        Inventory $inventory
    ): void {
        $this->inventories[
        spl_object_id($inventory)
        ] = $inventory;
    }

    public function remove(
        Inventory $inventory
    ): void {
        unset(
            $this->inventories[
            spl_object_id($inventory)
            ]
        );
    }

    public function isReadOnly(
        ?Inventory $inventory
    ): bool {
        if ($inventory === null) {
            return false;
        }

        $id = spl_object_id($inventory);

        return isset($this->inventories[$id])
            && $this->inventories[$id] === $inventory;
    }

    public function open(
        Player $player,
        Inventory $inventory,
        string $title = 'Chest'
    ): bool {
        $this->add($inventory);

        $opened = $inventory instanceof VirtualInventory && $this->virtualWindow !== null
            ? $this->virtualWindow->open($player, $inventory, $title)
            : $player->setCurrentWindow($inventory);

        if (!$opened) {
            $this->remove($inventory);

            return false;
        }

        return true;
    }

    public function clear(): void
    {
        $this->inventories = [];
    }
}