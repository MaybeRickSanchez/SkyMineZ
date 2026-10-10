<?php

declare(strict_types=1);

namespace AM\SkyMineZ\label;

use AM\SkyMineZ\Main;
use AM\SkyMineZ\useless\Arrays;
use AM\SkyMineZ\useless\MultiLineTextParticle;
use AM\SkyMineZ\useless\Positions;
use AM\SkyMineZ\useless\SpreadTask;
use pocketmine\player\Player;
use pocketmine\utils\Config;
use pocketmine\world\Position;
use RuntimeException;

/**
 * General-purpose floating labels: wiki notes, help boards, tutorials, area
 * signs, server information.
 *
 * Each label is a named {@link MultiLineTextParticle}, so text edits reuse the
 * delta-only update path: changing one line re-sends one line, never the whole
 * stack. Positions persist in labels.json as canonical records.
 */
final class LabelManager
{
    private const FILE_NAME = 'labels.json';

    /** @var array<string, MultiLineTextParticle> */
    private array $labels = [];

    private Config $db;

    public function __construct(
        private Main $main
    ) {
        $this->db = new Config(
            $this->main->getDataFolder() . self::FILE_NAME,
            Config::JSON
        );
    }

    public function load(): void
    {
        foreach ($this->labels as $label) {
            $label->deSpawn();
        }

        $this->labels = [];

        $worldManager = $this->main->getServer()->getWorldManager();

        foreach ($this->db->getAll() as $name => $data) {
            if (
                !is_string($name)
                || !is_array($data)
                || !Arrays::isStringMap($data)
                || !isset($data['position'], $data['lines'])
                || !is_array($data['position'])
                || !is_array($data['lines'])
                || !Arrays::isStringMap($data['position'])
            ) {
                continue;
            }

            $position = Positions::fromArray($data['position'], $worldManager);

            if ($position === null) {
                $this->main->getLogger()->warning(
                    "Skipped label '{$name}': world is missing."
                );

                continue;
            }

            $lines = [];

            foreach ($data['lines'] as $line) {
                if (is_scalar($line)) {
                    $lines[] = (string) $line;
                }
            }

            $this->labels[$name] = new MultiLineTextParticle(
                $position,
                $position->getWorld(),
                $lines === [] ? [''] : $lines
            );
        }

        $this->spawnAll();
    }

    public function saveAll(): void
    {
        $data = [];

        foreach ($this->labels as $name => $label) {
            $data[$name] = $this->serialize($label);
        }

        $this->db->setAll($data);
        $this->db->save();
    }

    public function save(
        string $name
    ): void {
        $label = $this->labels[$name] ?? null;

        if ($label === null) {
            return;
        }

        $this->db->set($name, $this->serialize($label));
        $this->db->save();
    }

    /**
     * One canonical label record shared by single and bulk saves.
     *
     * @return array{position: array<string, mixed>, lines: list<string>}
     */
    private function serialize(
        MultiLineTextParticle $label
    ): array {
        return [
            'position' => Positions::toArray($label->getPosition(), $label->getWorld()),
            'lines' => $label->getLines()
        ];
    }

    /**
     * @param list<string> $lines
     *
     * @throws RuntimeException when the name is taken
     */
    public function create(
        string $name,
        Position $position,
        array $lines = ['']
    ): MultiLineTextParticle {
        if (isset($this->labels[$name])) {
            throw new RuntimeException("Label '{$name}' already exists.");
        }

        $label = new MultiLineTextParticle(
            $position,
            $position->getWorld(),
            $lines
        );

        $this->labels[$name] = $label;

        $label->spawn();

        $this->save($name);

        return $label;
    }

    public function remove(
        string $name
    ): bool {
        $label = $this->labels[$name] ?? null;

        if ($label === null) {
            return false;
        }

        $label->deSpawn();

        unset($this->labels[$name]);

        $this->db->remove($name);
        $this->db->save();

        return true;
    }

    public function move(
        string $name,
        Position $position
    ): bool {
        $label = $this->labels[$name] ?? null;

        if ($label === null) {
            return false;
        }

        $label->setPosition($position);

        $this->save($name);

        return true;
    }

    public function get(
        string $name
    ): ?MultiLineTextParticle {
        return $this->labels[$name] ?? null;
    }

    public function has(
        string $name
    ): bool {
        return isset($this->labels[$name]);
    }

    /**
     * @return array<string, MultiLineTextParticle>
     */
    public function getAll(): array
    {
        return $this->labels;
    }

    public function count(): int
    {
        return count($this->labels);
    }

    public function spawnAll(): void
    {
        foreach ($this->labels as $label) {
            if (!$label->isSpawned()) {
                $label->spawn();
            }
        }
    }

    /**
     * Pushes every label to a player who just spawned in.
     */
    public function spawnTo(
        Player $player
    ): void {
        SpreadTask::spread(
            $this->main,
            $this->labels,
            8,
            static function(mixed $label) use ($player): void {
                if (!$label instanceof MultiLineTextParticle) {
                    return;
                }

                if ($label->getWorld() !== $player->getWorld()) {
                    return;
                }

                $label->spawn($player);
            }
        );
    }

}