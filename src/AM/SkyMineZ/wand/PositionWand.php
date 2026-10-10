<?php

declare(strict_types=1);

namespace AM\SkyMineZ\wand;

use pocketmine\item\Item;
use pocketmine\item\VanillaItems;

/**
 * The Position Wand: a stick that selects cuboid corners instead of breaking
 * blocks.
 *
 * A normal click records pos1, a click while sneaking records pos2. The actual
 * coordinates live in {@link \AM\SkyMineZ\command\SelectionManager}, which is
 * what the mine/outpost commands already read — the wand is only another way
 * to fill it.
 */
final class PositionWand
{
    private const TAG_WAND = 'SkyMineZ_Wand';

    private function __construct()
    {
    }

    public static function create(): Item
    {
        $item = VanillaItems::STICK();

        $item->setCustomName("§dPosition Wand");
        $item->setLore([
            "§7Left-click a block: select §epos1",
            "§7Sneak + left-click: select §epos2",
            "§7Used by §d/mine create§7, §d/outpost create"
        ]);

        $tag = $item->getNamedTag();
        $tag->setByte(self::TAG_WAND, 1);

        $item->setNamedTag($tag);
        $item->setCount(1);

        return $item;
    }

    public static function isWand(
        Item $item
    ): bool {
        return $item->getNamedTag()->getByte(self::TAG_WAND, 0) === 1;
    }
}