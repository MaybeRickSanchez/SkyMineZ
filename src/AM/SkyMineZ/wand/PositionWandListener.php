<?php

declare(strict_types=1);

namespace AM\SkyMineZ\wand;

use AM\SkyMineZ\Main;
use AM\SkyMineZ\useless\Positions;
use AM\SkyMineZ\config\Messages;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\player\Player;
use pocketmine\world\Position;

/**
 * Turns wand clicks into cuboid selections.
 *
 * Holding the wand, breaking (or right-clicking) a block never touches the
 * world: the event is cancelled and the block position is recorded as pos1,
 * or pos2 while sneaking. Feedback always carries the coordinates so a
 * mis-click is immediately visible.
 */
final class PositionWandListener implements Listener
{
    public function __construct(
        private Main $main
    ) {
    }

    public function onBreak(
        BlockBreakEvent $event
    ): void {
        if ($this->handleWandClick(
            $event->getPlayer(),
            $event->getBlock()->getPosition()
        )) {
            $event->cancel();
        }
    }

    public function onInteract(
        PlayerInteractEvent $event
    ): void {
        if ($event->getAction() !== PlayerInteractEvent::RIGHT_CLICK_BLOCK) {
            return;
        }

        if ($this->handleWandClick(
            $event->getPlayer(),
            $event->getBlock()->getPosition()
        )) {
            $event->cancel();
        }
    }

    /**
     * Records a wand click as pos1 (or pos2 while sneaking). Returns false
     * when the held item is not a wand, so the caller leaves the event alone.
     */
    private function handleWandClick(
        Player $player,
        Position $position
    ): bool {
        if (!PositionWand::isWand($player->getInventory()->getItemInHand())) {
            return false;
        }

        $this->select(
            $player,
            $position,
            $player->isSneaking()
        );

        return true;
    }

    private function select(
        Player $player,
        Position $position,
        bool $second
    ): void {
        $selection = $this->main->getSelectionManager();

        if ($second) {
            $selection->setPos2($player, $position);
        } else {
            $selection->setPos1($player, $position);
        }

        $player->sendMessage(
            $this->main->getConfigManager()->getPrefix()
            . Messages::get(
                $this->main,
                $second ? Messages::WAND_POS2 : Messages::WAND_POS1,
                ['where' => Positions::describe($position)]
            )
        );
    }

}