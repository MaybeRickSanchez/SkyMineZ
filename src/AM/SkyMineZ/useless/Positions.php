<?php

declare(strict_types=1);

namespace AM\SkyMineZ\useless;

use pocketmine\entity\Location;
use pocketmine\math\Vector3;
use pocketmine\world\Position;
use pocketmine\world\World;
use pocketmine\world\WorldManager;

/**
 * One canonical position serialization for every JSON store added from here
 * on: an associative record that keeps yaw/pitch, so teleports restore the
 * exact view direction.
 *
 * Older stores (mines, outposts, crates, slappers, leaderboards) keep their
 * own list-shaped formats untouched — changing them would orphan every
 * existing data file on upgrade.
 */
final class Positions
{
    private function __construct()
    {
    }

    /**
     * @return array{world: string, x: float, y: float, z: float, yaw: float, pitch: float}
     */
    public static function toArray(
        Vector3 $position,
        ?World $world = null
    ): array {
        $world ??= $position instanceof Position
            ? $position->getWorld()
            : null;

        if ($world === null) {
            throw new \InvalidArgumentException(
                'A world is required to serialize a plain Vector3.'
            );
        }

        $yaw = 0.0;
        $pitch = 0.0;

        if ($position instanceof Location) {
            $yaw = $position->yaw;
            $pitch = $position->pitch;
        }

        return [
            'world' => $world->getFolderName(),
            'x' => $position->x,
            'y' => $position->y,
            'z' => $position->z,
            'yaw' => $yaw,
            'pitch' => $pitch
        ];
    }

    /**
     * Always returns a Location (which is a Position), so teleports restore
     * the saved view direction as well.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(
        array $data,
        WorldManager $worldManager
    ): ?Location {
        if (
            !isset($data['world'], $data['x'], $data['y'], $data['z'])
            || !is_string($data['world'])
            || !is_numeric($data['x'])
            || !is_numeric($data['y'])
            || !is_numeric($data['z'])
        ) {
            return null;
        }

        $world = Worlds::resolve($worldManager, $data['world']);

        if ($world === null) {
            return null;
        }

        $yaw = $data['yaw'] ?? 0.0;
        $pitch = $data['pitch'] ?? 0.0;

        return new Location(
            (float) $data['x'],
            (float) $data['y'],
            (float) $data['z'],
            $world,
            is_numeric($yaw) ? (float) $yaw : 0.0,
            is_numeric($pitch) ? (float) $pitch : 0.0
        );
    }

    /**
     * Legacy list-shaped `[world, x, y, z]` record used by the older stores
     * (mines, outposts). One canonical parser so the two managers cannot drift
     * apart in what they accept.
     *
     * @param mixed $data
     */
    public static function fromTuple(
        mixed $data,
        WorldManager $worldManager
    ): ?Position {
        if (
            !is_array($data)
            || !isset($data[0], $data[1], $data[2], $data[3])
            || !is_string($data[0])
            || !is_numeric($data[1])
            || !is_numeric($data[2])
            || !is_numeric($data[3])
        ) {
            return null;
        }

        $world = Worlds::resolve($worldManager, $data[0]);

        if ($world === null) {
            return null;
        }

        return new Position(
            (float) $data[1],
            (float) $data[2],
            (float) $data[3],
            $world
        );
    }

    public static function toLocation(
        Position $position,
        float $yaw = 0.0,
        float $pitch = 0.0
    ): Location {
        if ($position instanceof Location) {
            $yaw = $position->yaw;
            $pitch = $position->pitch;
        }

        return new Location(
            $position->x,
            $position->y,
            $position->z,
            $position->getWorld(),
            $yaw,
            $pitch
        );
    }

    /**
     * A copy raised by $dy blocks, keeping the world. Labels and holograms
     * float above heads and blocks, and Vector3::add() would downgrade the
     * Position to a plain Vector3.
     */
    public static function above(
        Position $position,
        float $dy
    ): Position {
        return new Position(
            $position->x,
            $position->y + $dy,
            $position->z,
            $position->getWorld()
        );
    }

    public static function describe(
        Vector3 $position,
        ?World $world = null
    ): string {
        $world ??= $position instanceof Position
            ? $position->getWorld()
            : null;

        $where = $world === null
            ? 'unknown world'
            : $world->getFolderName();

        return $where
            . ' (' . $position->getFloorX()
            . ', ' . $position->getFloorY()
            . ', ' . $position->getFloorZ() . ')';
    }
}