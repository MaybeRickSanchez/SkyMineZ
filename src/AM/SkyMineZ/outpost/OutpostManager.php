<?php

declare(strict_types=1);

namespace AM\SkyMineZ\outpost;

use AM\SkyMineZ\economy\EconomyChangeEventReason;
use AM\SkyMineZ\economy\GoldEconomy;
use AM\SkyMineZ\config\Messages;
use AM\SkyMineZ\Main;
use AM\SkyMineZ\useless\Arrays;
use AM\SkyMineZ\useless\Positions;
use AM\SkyMineZ\useless\SpreadTask;
use AM\SkyMineZ\useless\Worlds;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\scheduler\TaskHandler;
use pocketmine\utils\Config;
use pocketmine\world\Position;
use pocketmine\world\World;
use pocketmine\world\WorldManager;
use RuntimeException;

/**
 * Owns every outpost, drives their capture logic and pays out the owner's gold.
 *
 * Outposts live in plugin_data/outposts.json and are restored with their owner,
 * state and timers, so a restart does not hand a captured outpost back to
 * nobody.
 */
final class OutpostManager
{
    private const FILE_NAME = 'outposts.json';

    /** @var array<string, Outpost> */
    private array $outposts = [];

    private Config $db;

    /** @var TaskHandler<OutpostTask>|null */
    private ?TaskHandler $task = null;

    public function __construct(
        private Main $main,
        private GoldEconomy $goldEconomy
    ) {
        $this->db = new Config(
            $this->main->getDataFolder() . self::FILE_NAME,
            Config::JSON
        );
    }

    public function load(): void
    {
        $this->despawnAll();

        $this->outposts = [];

        $worldManager = $this->main->getServer()
            ->getWorldManager();

        foreach (
            $this->db->getAll() as $name => $data
        ) {
            if (
                !is_string($name)
                || !is_array($data)
                || !Arrays::isStringMap($data)
            ) {
                continue;
            }

            $outpost = $this->createFromArray(
                $name,
                $data,
                $worldManager
            );

            if ($outpost === null) {
                $this->main->getLogger()->warning(
                    "Skipped malformed outpost '{$name}' in " . self::FILE_NAME
                );

                continue;
            }

            $this->outposts[$name] = $outpost;

            $outpost->spawn();
        }

        $this->startTask();
    }

    public function saveAll(): void
    {
        $data = [];

        foreach (
            $this->outposts as $name => $outpost
        ) {
            $data[$name] = $outpost->toArray();
        }

        $this->db->setAll($data);
        $this->db->save();
    }

    public function save(
        string $name
    ): void {
        $outpost = $this->outposts[$name] ?? null;

        if ($outpost === null) {
            return;
        }

        $this->db->set(
            $name,
            $outpost->toArray()
        );

        $this->db->save();
    }

    /**
     * @throws RuntimeException when the name is taken or the positions differ
     */
    public function create(
        string $name,
        Position $pos1,
        Position $pos2
    ): Outpost {
        if ($this->has($name)) {
            throw new RuntimeException(
                "Outpost '$name' already exists."
            );
        }

        if ($pos1->getWorld() !== $pos2->getWorld()) {
            throw new RuntimeException(
                'Both positions must be in the same world.'
            );
        }

        $outpost = $this->buildOutpost(
            $name,
            $pos1,
            $pos2,
            $pos1->getWorld()
        );

        $this->outposts[$name] = $outpost;

        $outpost->spawn();

        $this->save($name);

        return $outpost;
    }

    /**
     * Builds an outpost with the tuning from config.yml. Both creation paths
     * (new outpost, restored from disk) funnel through here so the values can
     * never disagree.
     */
    private function buildOutpost(
        string $name,
        Vector3 $pos1,
        Vector3 $pos2,
        World $world
    ): Outpost {
        $config = $this->main->getConfigManager();

        return new Outpost(
            $name,
            $pos1,
            $pos2,
            $world,
            $config->getInt('outposts.capture-required', 100),
            $config->getInt('outposts.cooldown-duration', 1800),
            $config->getInt('outposts.gold-interval', 600),
            $config->getInt('outposts.gold-chance', 50),
            $config->getInt('outposts.gold-reward', 1)
        );
    }

    public function remove(
        string $name
    ): bool {
        $outpost = $this->outposts[$name] ?? null;

        if ($outpost === null) {
            return false;
        }

        $outpost->deSpawn();

        unset($this->outposts[$name]);

        $this->db->remove($name);
        $this->db->save();

        return true;
    }

    public function has(
        string $name
    ): bool {
        return isset($this->outposts[$name]);
    }

    public function get(
        string $name
    ): ?Outpost {
        return $this->outposts[$name] ?? null;
    }

    /**
     * @return array<string, Outpost>
     */
    public function getAll(): array
    {
        return $this->outposts;
    }

    public function count(): int
    {
        return count($this->outposts);
    }

    /**
     * Outposts a player currently owns.
     *
     * @return list<string>
     */
    public function getOwnedBy(
        string $playerName
    ): array {
        $result = [];

        foreach (
            $this->outposts as $name => $outpost
        ) {
            if (
                $outpost->getOwner() !== null
                && strcasecmp(
                    $outpost->getOwner(),
                    $playerName
                ) === 0
            ) {
                $result[] = (string) $name;
            }
        }

        return $result;
    }

    public function tickAll(): void
    {
        $now = time();

        $config = $this->main->getConfigManager();

        $captureMin = $config->getInt('outposts.capture-min', 1);
        $captureMax = $config->getInt('outposts.capture-max', 3);

        foreach (
            $this->outposts as $name => $outpost
        ) {
            $previousOwner = $outpost->getOwner();
            $previousState = $outpost->getState();

            $changed = $outpost->tick(
                $now,
                $captureMin,
                $captureMax
            );

            if ($outpost->getState() !== $previousState) {
                $this->announceStateChange(
                    $outpost,
                    $previousState
                );
            }

            if ($outpost->getOwner() !== $previousOwner) {
                $this->announceOwnerChange(
                    $outpost,
                    $previousOwner
                );
            }

            $goldPaid = $this->handleGold($outpost, $now);

            // Persist state changes (cooldown expiry, capture, gold timer)
            // so a crash never double-pays or resets timers.
            if ($changed || $goldPaid || $outpost->getOwner() !== $previousOwner) {
                $this->save((string) $name);
            }
        }
    }

    public function despawnAll(): void
    {
        foreach (
            $this->outposts as $outpost
        ) {
            $outpost->deSpawn();
        }
    }

    /**
     * Shows the outpost holograms to a player who just spawned in.
     */
    public function spawnTo(
        Player $player
    ): void {
        SpreadTask::spread(
            $this->main,
            $this->outposts,
            2,
            function(
                mixed $outpost
            ) use ($player): void {
                if (
                    !$outpost instanceof Outpost
                    || !$outpost->getInfo()->isSpawned()
                    || $outpost->getWorld() !== $player->getWorld()
                ) {
                    return;
                }

                $outpost->getInfo()
                    ->getParticle()
                    ?->spawn($player);
            }
        );
    }

    private function startTask(): void
    {
        $this->task?->cancel();

        $interval = max(
            1,
            $this->main
                ->getConfigManager()
                ->getInt('outposts.tick-interval', 20)
        );

        $this->task = $this->main->getScheduler()->scheduleRepeatingTask(
            new OutpostTask($this),
            $interval
        );
    }

    private function announceStateChange(
        Outpost $outpost,
        string $previousState
    ): void {
        if (
            $previousState !== Outpost::STATE_COOLDOWN
            || $outpost->getState() !== Outpost::STATE_CAPTABLE
        ) {
            return;
        }

        $this->main->getServer()->broadcastMessage(
            Messages::get(
                $this->main,
                Messages::OUTPOST_OPEN,
                ['name' => $outpost->getName()]
            )
        );
    }

    private function announceOwnerChange(
        Outpost $outpost,
        ?string $previousOwner
    ): void {
        $owner = $outpost->getOwner();

        if ($owner === null) {
            return;
        }

        if ($previousOwner === null) {
            $this->main->getServer()->broadcastMessage(
                Messages::get(
                    $this->main,
                    Messages::OUTPOST_CAPTURED,
                    ['player' => $owner, 'name' => $outpost->getName()]
                )
            );

            return;
        }

        $this->main->getServer()->broadcastMessage(
            Messages::get(
                $this->main,
                Messages::OUTPOST_TAKEN,
                [
                    'player' => $owner,
                    'name' => $outpost->getName(),
                    'previous' => $previousOwner
                ]
            )
        );
    }

    private function handleGold(
        Outpost $outpost,
        int $now
    ): bool {
        $owner = $outpost->getOwner();

        if (
            $owner === null
            || !$outpost->isGoldDue($now)
        ) {
            return false;
        }

        $reward = $outpost->getGoldReward();

        if ($reward <= 0) {
            return false;
        }

        $this->goldEconomy->add(
            $owner,
            $reward,
            EconomyChangeEventReason::OUTPOST
        );

        $player = $this->main->getServer()->getPlayerExact(
            $owner
        );

        $player?->sendMessage(
            Messages::get(
                $this->main,
                Messages::OUTPOST_GOLD,
                ['reward' => $reward, 'name' => $outpost->getName()]
            )
        );

        return true;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function createFromArray(
        string $name,
        array $data,
        WorldManager $worldManager
    ): ?Outpost {
        if (
            !isset(
                $data['world'],
                $data['pos1'],
                $data['pos2']
            )
            || !is_string($data['world'])
        ) {
            return null;
        }

        $pos1 = $data['pos1'];
        $pos2 = $data['pos2'];

        if (
            !Arrays::isVectorTriple($pos1)
            || !Arrays::isVectorTriple($pos2)
        ) {
            return null;
        }

        $world = Worlds::resolve(
            $worldManager,
            $data['world']
            );

        if ($world === null) {
            return null;
        }

        $outpost = $this->buildOutpost(
            $name,
            new Vector3(
                $pos1[0],
                $pos1[1],
                $pos1[2]
            ),
            new Vector3(
                $pos2[0],
                $pos2[1],
                $pos2[2]
            ),
            $world
        );

        $owner = $data['owner'] ?? null;
        $state = $data['state'] ?? Outpost::STATE_CAPTABLE;

        $outpost->restore(
            is_string($owner) ? $owner : null,
            is_string($state) ? $state : Outpost::STATE_CAPTABLE,
            isset($data['progress']) && is_numeric($data['progress'])
                ? (int) $data['progress']
                : 0,
            isset($data['availableAt']) && is_numeric($data['availableAt'])
                ? (int) $data['availableAt']
                : 0,
            isset($data['lastGoldAt']) && is_numeric($data['lastGoldAt'])
                ? (int) $data['lastGoldAt']
                : 0
        );

        $label = Positions::fromTuple($data['label'] ?? null, $worldManager);

        if ($label !== null) {
            $outpost->setLabelPosition($label);
        }

        return $outpost;
    }

    /**
     * @param mixed $value
     *
     * @phpstan-assert-if-true array{float, float, float} $value
     */
}