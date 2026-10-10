<?php

declare(strict_types=1);

namespace AM\SkyMineZ\command;

use pocketmine\event\Listener;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\player\Player;
use pocketmine\world\Position;

/**
 * Per-player cuboid selection, the same workflow world editors use:
 * `/skymine pos1` and `/skymine pos2` remember two corners, and the cuboid
 * commands then read them.
 *
 * Selections are session only; there is no reason to persist them.
 * Corners are floored to block coords on store so fractional player
 * positions never cause off-by-one fill/contain mismatches.
 */
final class SelectionManager implements Listener
{
    /** @var array<string, array{pos1: Position|null, pos2: Position|null}> */
    private array $selections = [];

    public function onQuit(PlayerQuitEvent $event): void
    {
        $this->clear($event->getPlayer());
    }

    public function setPos1(
        Player $player,
        Position $position
    ): void {
        $this->setPos($player, $position, 'pos1');
    }

    public function setPos2(
        Player $player,
        Position $position
    ): void {
        $this->setPos($player, $position, 'pos2');
    }

    /**
     * Corners are floored to block coords on store so fractional player
     * positions never cause off-by-one fill/contain mismatches.
     */
    private function setPos(
        Player $player,
        Position $position,
        string $which
    ): void {
        $this->selections[$this->key($player)][$which] = new Position(
            (float) $position->getFloorX(),
            (float) $position->getFloorY(),
            (float) $position->getFloorZ(),
            $position->getWorld()
        );
    }

    public function getPos1(
        Player $player
    ): ?Position {
        return $this->getPos($player, 'pos1');
    }

    public function getPos2(
        Player $player
    ): ?Position {
        return $this->getPos($player, 'pos2');
    }

    private function getPos(
        Player $player,
        string $which
    ): ?Position {
        return $this->selections[$this->key(
            $player
        )][$which] ?? null;
    }

    /**
     * Both corners in world space, or null when the selection is incomplete or
     * the corners are in different worlds.
     *
     * @return array{Position, Position}|null
     */
    public function getRegion(
        Player $player
    ): ?array {
        $pos1 = $this->getPos1($player);
        $pos2 = $this->getPos2($player);

        if (
            $pos1 === null
            || $pos2 === null
        ) {
            return null;
        }

        // Compare by folder name, not object identity: after a world reload
        // the manager hands out a new World instance for the same folder.
        if ($pos1->getWorld()->getFolderName() !== $pos2->getWorld()->getFolderName()) {
            return null;
        }

        return [$pos1, $pos2];
    }

    public function clear(
        Player $player
    ): void {
        unset(
            $this->selections[$this->key($player)]
        );
    }

    private function key(
        Player $player
    ): string {
        return strtolower(
            $player->getName()
        );
    }
}