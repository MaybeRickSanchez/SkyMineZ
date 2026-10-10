<?php

declare(strict_types=1);

namespace AM\SkyMineZ\useless;

use pocketmine\block\VanillaBlocks;
use pocketmine\event\inventory\InventoryCloseEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\BlockActorDataPacket;
use pocketmine\network\mcpe\protocol\types\BlockPosition;
use pocketmine\network\mcpe\protocol\types\CacheableNbt;
use pocketmine\network\mcpe\protocol\UpdateBlockPacket;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\Server;
use pocketmine\world\Position;

/**
 * Opens VirtualInventory windows so the client actually shows them.
 *
 * The client only displays a ContainerOpen when a container block exists at
 * the packet's position. Sending an open for air (the old behaviour) shows
 * nothing, yet the server marks a current window as open: the player can
 * still walk and chat but cannot open their inventory because the client
 * believes it is already inside one. That is the reported soft-lock.
 *
 * Flow per open (all client-side only, the server world is never touched):
 *
 *  1. Take the player's current block position and offset it by -2 X and
 *     +2 Y. A fake chest exactly at the feet block is rendered inside the
 *     player and shoves them; the offset keeps it in the same loaded chunk
 *     column area but clear of the body.
 *  2. Remember the real block state there and send a fake chest +
 *     chest tile (with the window title) to that one player.
 *  3. Point the inventory holder at the fake position and call
 *     setCurrentWindow(). On failure the fake is restored immediately so no
 *     stuck window is left behind.
 *  4. On InventoryCloseEvent / quit the real block is re-sent, erasing the
 *     fake chest.
 */
final class VirtualWindow implements Listener
{
    /**
     * Lower player name => fake block that must be restored on close.
     *
     * @var array<string, array{world: string, x: int, y: int, z: int, stateId: int}>
     */
    private array $fakes = [];

    public function __construct(
        Server $server,
        PluginBase $plugin
    ) {
        $pluginManager = $server->getPluginManager();
        $pluginManager->registerEvent(
            InventoryCloseEvent::class,
            function(InventoryCloseEvent $event): void {
                $this->onClose($event);
            },
            \pocketmine\event\EventPriority::MONITOR,
            $plugin
        );
        $pluginManager->registerEvent(
            PlayerQuitEvent::class,
            function(PlayerQuitEvent $event): void {
                // Session is gone; just forget tracking so nothing leaks.
                unset($this->fakes[strtolower($event->getPlayer()->getName())]);
            },
            \pocketmine\event\EventPriority::MONITOR,
            $plugin
        );
    }

    /**
     * Opens a virtual window with a fake chest at the player's current
     * position. Returns false when the window could not be opened; in that
     * case no current window is left behind and no fake block remains.
     */
    public function open(
        Player $player,
        VirtualInventory $inventory,
        string $title = 'Chest'
    ): bool {
        if (!$player->isConnected()) {
            return false;
        }

        // Close any previous window first so its fake block is restored
        // (via the close event) before we place a new one.
        $player->removeCurrentWindow();
        $this->restore($player);

        $playerPos = $player->getPosition();
        $world = $player->getWorld();
        // Offset so the fake chest never spawns inside the player's body and
        // pushes them: 2 up, 2 on -X (same Z). Still within the loaded area
        // around the player, so the client accepts the ContainerOpen.
        $fx = $playerPos->getFloorX() - 2;
        $fy = $playerPos->getFloorY() + 2;
        $fz = $playerPos->getFloorZ();
        if (method_exists($world, 'getMinY') && method_exists($world, 'getMaxY')) {
            try {
                /** @var int $minY */
                $minY = $world->getMinY();
                /** @var int $maxY */
                $maxY = $world->getMaxY();
                $fy = max($minY, min($maxY - 1, $fy));
            } catch (\Throwable) {
            }
        }
        $fakePos = new Position($fx, $fy, $fz, $world);

        $realStateId = $world->getBlock($fakePos)->getStateId();
        $this->fakes[strtolower($player->getName())] = [
            'world' => $world->getFolderName(),
            'x' => $fx,
            'y' => $fy,
            'z' => $fz,
            'stateId' => $realStateId
        ];

        $inventory->setHolder($fakePos);
        $this->sendFake($player, $fx, $fy, $fz, $title);

        try {
            if (!$player->setCurrentWindow($inventory)) {
                $this->restore($player);

                return false;
            }
        } catch (\Throwable) {
            $this->restore($player);
            // Make sure a half-opened window never strands the player.
            try {
                $player->removeCurrentWindow();
            } catch (\Throwable) {
            }

            return false;
        }

        return true;
    }

    public function onClose(InventoryCloseEvent $event): void
    {
        if (!$event->getInventory() instanceof VirtualInventory) {
            return;
        }

        $this->restore($event->getPlayer());
    }

    /**
     * Re-sends the real block, erasing the fake chest. Safe to call when
     * there is nothing to restore.
     */
    public function restore(Player $player): void
    {
        $key = strtolower($player->getName());
        $fake = $this->fakes[$key] ?? null;

        if ($fake === null) {
            return;
        }

        unset($this->fakes[$key]);

        if (!$player->isConnected()) {
            return;
        }

        // The packet has no world field; if the player changed world since
        // the open, restoring would paint a ghost chest at the same coords
        // in the new world, so skip it (the old chunk is unloaded anyway).
        try {
            if ($player->getWorld()->getFolderName() !== $fake['world']) {
                return;
            }
        } catch (\Throwable) {
            return;
        }

        try {
            $session = $player->getNetworkSession();
            $runtimeId = $session->getTypeConverter()->getBlockTranslator()->internalIdToNetworkId($fake['stateId']);
            $blockPos = new BlockPosition($fake['x'], $fake['y'], $fake['z']);
            $session->sendDataPacket(UpdateBlockPacket::create(
                $blockPos,
                $runtimeId,
                UpdateBlockPacket::FLAG_NETWORK,
                UpdateBlockPacket::DATA_LAYER_NORMAL
            ));
        } catch (\Throwable) {
        }
    }

    private function sendFake(
        Player $player,
        int $fx,
        int $fy,
        int $fz,
        string $title
    ): void {
        $session = $player->getNetworkSession();
        $translator = $session->getTypeConverter()->getBlockTranslator();
        $fakeStateId = VanillaBlocks::CHEST()->getStateId();
        $fakeRuntimeId = $translator->internalIdToNetworkId($fakeStateId);

        $blockPos = new BlockPosition($fx, $fy, $fz);
        $session->sendDataPacket(UpdateBlockPacket::create(
            $blockPos,
            $fakeRuntimeId,
            UpdateBlockPacket::FLAG_NETWORK,
            UpdateBlockPacket::DATA_LAYER_NORMAL
        ));

        $nbt = CompoundTag::create()
            ->setString('id', 'Chest')
            ->setInt('x', $fx)
            ->setInt('y', $fy)
            ->setInt('z', $fz);
        if ($title !== '') {
            $nbt->setString('CustomName', $title);
        }

        $session->sendDataPacket(BlockActorDataPacket::create(
            $blockPos,
            new CacheableNbt($nbt)
        ));
    }
}
