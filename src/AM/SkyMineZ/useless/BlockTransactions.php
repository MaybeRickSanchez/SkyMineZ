<?php

declare(strict_types=1);

namespace AM\SkyMineZ\useless;

use pocketmine\event\block\BlockPlaceEvent;
use pocketmine\math\Vector3;

/**
 * Shared unpacking of multi-block placement transactions.
 *
 * Doors, beds and similar structures touch more than one position, so every
 * placement guard (lobby protection, mine grief protection) must check all
 * of them — checking only the first would let players push the second half
 * of a bed into a protected area. PMMP yields each entry as an [x, y, z, ...]
 * tuple; anything else is skipped so a core shape change can never fatal.
 */
final class BlockTransactions
{
    private function __construct()
    {
    }

    /**
     * @return list<Vector3>
     */
    public static function vectors(
        BlockPlaceEvent $event
    ): array {
        $result = [];

        foreach ($event->getTransaction()->getBlocks() as $entry) {
            if (!is_array($entry) || count($entry) < 3) {
                continue;
            }

            [$x, $y, $z] = [$entry[0], $entry[1], $entry[2]];

            if (!is_numeric($x) || !is_numeric($y) || !is_numeric($z)) {
                continue;
            }

            $result[] = new Vector3(
                (float) $x,
                (float) $y,
                (float) $z
            );
        }

        return $result;
    }
}
