<?php

declare(strict_types=1);

namespace AM\SkyMineZ\crate;

use AM\SkyMineZ\Main;
use AM\SkyMineZ\config\Messages;
use AM\SkyMineZ\ui\Ui;
use AM\SkyMineZ\useless\Arrays;
use AM\SkyMineZ\useless\NumberFormatter;
use AM\SkyMineZ\event\CrateOpenEvent;
use AM\SkyMineZ\useless\ReadOnlyInventory;
use AM\SkyMineZ\useless\TextParticle;
use AM\SkyMineZ\useless\VirtualInventory;
use InvalidArgumentException;
use pocketmine\block\DyedShulkerBox;
use pocketmine\block\tile\ShulkerBox as ShulkerTile;
use pocketmine\block\utils\DyeColor;
use pocketmine\block\VanillaBlocks;
use pocketmine\color\Color;
use pocketmine\entity\Location;
use pocketmine\entity\object\ItemEntity;
use pocketmine\inventory\Inventory;
use pocketmine\item\Item;
use pocketmine\player\Player;
use pocketmine\scheduler\ClosureTask;
use pocketmine\world\particle\DustParticle;
use pocketmine\world\particle\ExplodeParticle;
use pocketmine\world\particle\HappyVillagerParticle;
use pocketmine\world\particle\ItemBreakParticle;
use pocketmine\world\Position;
use pocketmine\world\sound\PopSound;
use pocketmine\world\sound\XpLevelUpSound;
use pocketmine\world\World;

final class Crate
{
    private string $name;

    private Position $position;

    private DyeColor $color;

    private TextParticle $textParticle;

    /**
     * @var array<string, true>
     */
    private array $keys = [];

    /**
     * @var array<int, Reward>
     */
    private array $rewards = [];

    /**
     * @var array<string, true> lower-case player names watching a preview
     */
    private array $previewViewers = [];

    /**
     * The live animation window, if an opening is running. Virtual, like
     * previews: the real shulker inventory is never shown to anyone, so
     * hoppers and snoopers can never reach the items on screen.
     */
    private ?Inventory $animationInventory = null;

    /**
     * One preview window per viewing player, keyed by lower-case name.
     * Name keys (not spl_object_id) can never collide after an object is
     * freed and its id reused.
     *
     * @var array<string, Inventory>
     */
    private array $previewWindows = [];

    /** Lower-case name of the player running the animation, if any. */
    private ?string $openingPlayerId = null;

    private ?ItemEntity $floatingItem = null;

    private bool $busy = false;

    private ?Reward $pendingReward = null;

    public function __construct(
        private Main              $main,
        private ReadOnlyInventory $readOnlyInventory,
        string                    $name,
        Position                  $position,
        ?DyeColor                 $color = null
    )
    {
        $this->name = $name;
        $this->position = $position;
        $this->color = $color ?? DyeColor::PURPLE();

        $this->textParticle = new TextParticle(
            "§d$name Crate\n" .
            "§7Right Click §fwith a key §7to open\n" .
            "§7Shift + Right Click §fto preview",
            $position->add(
                0.5,
                1.5,
                0.5
            ),
            $position->getWorld()
        );
    }

    public function getColor(): DyeColor
    {
        return $this->color;
    }

    public function setColor(
        DyeColor $color
    ): self {
        $this->color = $color;

        $this->spawn();

        return $this;
    }

    public function spawn(): void
    {
        $world = $this->getWorld();

        $block = $world->getBlock($this->position);

        /*
         * Shulkers do not pair, have no double inventory, and read clearly as
         * "not a normal chest". Anything else standing here (including a
         * leftover chest from before the shulker migration, or a shulker in
         * the wrong color) is replaced.
         */
        if (
            !$block instanceof DyedShulkerBox
            || $block->getColor() !== $this->color
        ) {
            $world->setBlock(
                $this->position,
                VanillaBlocks::DYED_SHULKER_BOX()->setColor($this->color)
            );
        }

        $tile = $world->getTile(
            $this->position
        );

        if ($tile instanceof ShulkerTile) {
            $tile->setName(
                '§5' . $this->name . ' Crate'
            );
        }

        if (!$this->textParticle->isSpawned()) {
            $this->textParticle->spawn();
        }
    }

    public function despawn(): void
    {
        $this->textParticle->deSpawn();

        $this->destroyFloatingItem();
    }

    public function spawnText(
        Player $player
    ): void
    {
        $this->textParticle->spawn(
            $player
        );
    }

    public function getTextParticle(): TextParticle
    {
        return $this->textParticle;
    }

    public function update(
        int $delay = 0
    ): void
    {
        if ($delay > 0) {
            $this->main->getScheduler()
                ->scheduleDelayedTask(
                    new ClosureTask(
                        function (): void {
                            $this->updateContents();
                        }
                    ),
                    $delay
                );

            return;
        }

        $this->updateContents();
    }

    private function updateContents(): void
    {
        if ($this->busy) {
            return;
        }

        $inventory = $this->getInventory();

        if ($inventory === null) {
            return;
        }

        // The real shulker stays empty: previews and animations run in virtual
        // windows (showPreview/open). Filling the real container would expose
        // items to hoppers and other viewers.
        $inventory->clearAll();
    }

    public function addReward(
        Item   $item,
        float  $weight,
        string $type = Reward::TYPE_COMMON
    ): self
    {
        $this->rewards[] = new Reward(
            $item,
            $weight,
            $type
        );

        return $this;
    }

    public function addRewardByType(
        Item   $item,
        string $type
    ): self
    {
        return $this->addReward(
            $item,
            Reward::getDefaultWeightForType($type),
            $type
        );
    }

    public function addRewardObject(
        Reward $reward
    ): self
    {
        $this->rewards[] = $reward;

        return $this;
    }

    public function getReward(
        int $index
    ): ?Reward
    {
        return $this->rewards[$index] ?? null;
    }

    public function setRewardWeight(
        int   $index,
        float $weight
    ): self
    {
        $reward = $this->getReward($index);

        if ($reward === null) {
            throw new InvalidArgumentException(
                "Reward index $index does not exist."
            );
        }

        $reward->setWeight($weight);

        return $this;
    }

    public function setRewardType(
        int    $index,
        string $type
    ): self
    {
        $reward = $this->getReward($index);

        if ($reward === null) {
            throw new InvalidArgumentException(
                "Reward index $index does not exist."
            );
        }

        $reward->setType($type);

        return $this;
    }

    public function removeReward(
        int $index
    ): self
    {
        $this->rewards = Arrays::removeIndex($this->rewards, $index);

        return $this;
    }

    /**
     * @return array<int, Reward>
     */
    public function getRewards(): array
    {
        return $this->rewards;
    }

    public function getTotalWeight(): float
    {
        $total = 0.0;

        foreach ($this->rewards as $reward) {
            $total += $reward->getWeight();
        }

        return $total;
    }

    public function getRewardChance(
        int $index
    ): ?float
    {
        $reward = $this->getReward($index);

        if ($reward === null) {
            return null;
        }

        return $reward->getChancePercent(
            $this->getTotalWeight()
        );
    }

    public function addKey(
        string $keyId
    ): self
    {
        $this->keys[$keyId] = true;

        return $this;
    }

    public function hasKey(
        string $keyId
    ): bool
    {
        return isset(
            $this->keys[$keyId]
        );
    }

    /**
     * @return list<string>
     */
    public function getKeys(): array
    {
        return array_keys(
            $this->keys
        );
    }

    public function isBusy(): bool
    {
        return $this->busy;
    }

    public function hasPreviewViewers(): bool
    {
        return $this->previewViewers !== [];
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getPosition(): Position
    {
        return $this->position;
    }

    public function getWorld(): World
    {
        return $this->position->getWorld();
    }

    public function getInventory(): ?Inventory
    {
        $tile = $this->getWorld()->getTile(
            $this->position
        );

        if (!$tile instanceof ShulkerTile) {
            return null;
        }

        return $tile->getRealInventory();
    }

    /**
     * Shows the reward list in a throwaway virtual window.
     *
     * Deliberately NOT the real shulker inventory: opening the actual container
     * is exactly the "chest opens directly" confusion, and it would expose the
     * rewards to hoppers and to other viewers mid-animation.
     */
    public function showPreview(
        Player $player
    ): bool
    {
        if ($this->busy) {
            $player->sendMessage(
                Messages::get($this->main, Messages::CRATE_OPENING)
            );

            return false;
        }

        if ($this->rewards === []) {
            $player->sendMessage(
                Messages::get($this->main, Messages::CRATE_NO_PREVIEW)
            );

            return false;
        }

        $inventory = new VirtualInventory($player->getPosition(), 27);

        $this->fillPreview($inventory);

        if (
            !$this->readOnlyInventory->open(
                $player,
                $inventory,
                $this->name . ' Preview'
            )
        ) {
            return false;
        }

        $playerId = strtolower($player->getName());

        $this->forgetPreview($playerId);

        $this->previewWindows[$playerId] = $inventory;
        $this->previewViewers[$playerId] = true;

        return true;
    }

    /**
     * Drops one player's preview window from tracking (and unlocks it),
     * e.g. when they open a fresh preview or leave.
     */
    private function forgetPreview(
        string $playerId
    ): void {
        $inventory = $this->previewWindows[$playerId] ?? null;

        if ($inventory !== null) {
            $this->readOnlyInventory->remove($inventory);

            unset($this->previewWindows[$playerId]);
        }

        unset($this->previewViewers[$playerId]);
    }

    /**
     * Whether this inventory is a window opened by this crate (the animation
     * window or any preview). The listener uses it to route close events.
     */
    public function isMyWindow(
        Inventory $inventory
    ): bool {
        if ($inventory === $this->animationInventory) {
            return true;
        }

        foreach ($this->previewWindows as $preview) {
            if ($preview === $inventory) {
                return true;
            }
        }

        return false;
    }

    private function fillPreview(
        Inventory $inventory
    ): void
    {
        $inventory->clearAll();

        $slot = 0;

        foreach (
            $this->rewards as $reward
        ) {
            if (
                $slot >=
                $inventory->getSize()
            ) {
                break;
            }

            $inventory->setItem(
                $slot,
                $reward->getItem()
            );

            ++$slot;
        }
    }

    /**
     * Starts an opening.
     *
     * The key is *not* consumed here: the caller decides that, after this method
     * reported success. Consuming it here would hand out a reward even when the
     * animation could not be started.
     */
    public function open(
        Player $player
    ): bool {
        if ($this->busy) {
            $player->sendMessage(
                Messages::get($this->main, Messages::CRATE_OPENING)
            );

            return false;
        }

        if ($this->hasPreviewViewers()) {
            $player->sendMessage(
                Messages::get($this->main, Messages::CRATE_SOMEONE_VIEWING)
            );

            return false;
        }

        if ($this->rewards === []) {
            $player->sendMessage(
                Messages::get($this->main, Messages::CRATE_NO_REWARDS)
            );

            return false;
        }

        $winner = $this->rollReward();

        if ($winner === null) {
            $player->sendMessage(
                Messages::get($this->main, Messages::CRATE_NO_PICK)
            );

            return false;
        }

        if (CrateOpenEvent::hasHandlers()) {
            $event = new CrateOpenEvent(
                $this,
                $player->getName(),
                $winner
            );

            $event->call();

            if ($event->isCancelled()) {
                return false;
            }

            $winner = $event->getReward();
        }

        $this->busy = true;

        $this->openingPlayerId = strtolower($player->getName());

        $this->pendingReward = $winner;

        /*
         * A fresh virtual window per opening: the real shulker inventory is
         * never exposed, so hoppers cannot steal the spinning items and two
         * openings can never share state. Opened through the fake-chest
         * helper so the client actually shows it (plain setCurrentWindow on
         * air leaves an invisible window and soft-locks the inventory).
         */
        $inventory = new VirtualInventory($player->getPosition(), 27);

        $this->animationInventory = $inventory;

        if (!$this->readOnlyInventory->open($player, $inventory, $this->name)) {
            $this->busy = false;
            $this->openingPlayerId = null;
            $this->pendingReward = null;
            $this->animationInventory = null;

            return false;
        }

        $this->playAnimation(
            $player,
            $winner
        );

        return true;
    }

    /**
     * Rolls one of the configured rewards, weighted by {@link Reward::getWeight()}.
     *
     * Returns a fresh copy, so the caller can never mutate the stored reward.
     */
    private function rollReward(): ?Reward
    {
        $totalWeight = $this->getTotalWeight();

        if ($totalWeight <= 0) {
            return null;
        }

        return $this->rollRewardWithTotal($totalWeight);
    }

    /**
     * Same roll against a precomputed total. The animation calls this ~150
     * times per opening (up to 7 display rolls x ~36 frames); recomputing the
     * O(R) total every time is pure waste since rewards cannot change
     * mid-spin (busy flag). The winner roll still uses rollReward() so admin
     * edits between openings are always honored.
     */
    private function rollRewardWithTotal(float $totalWeight): ?Reward
    {
        if ($this->rewards === [] || $totalWeight <= 0) {
            return null;
        }

        $random = (
            mt_rand() / mt_getrandmax()
        ) * $totalWeight;

        $current = 0.0;

        foreach (
            $this->rewards as $reward
        ) {
            $current += $reward->getWeight();

            if ($random < $current) {
                return clone $reward;
            }
        }

        return null;
    }

    /**
     * A throwaway item used as a decoration while the crate spins.
     */
    private function getRandomDisplayItem(?float $totalWeight = null): ?Item
    {
        $reward = $totalWeight === null
            ? $this->rollReward()
            : $this->rollRewardWithTotal($totalWeight);

        return $reward?->getItem();
    }

    /**
     * Plays the spin, then hands the winner over.
     *
     * Every frame is one delayed task rather than a loop, so a crate opening
     * costs one task per frame for one crate and never blocks a tick. The frame
     * delay grows towards the end so the spin looks like it is slowing down.
     */
    private function playAnimation(
        Player $player,
        Reward $winner
    ): void {
        $inventory = $this->animationInventory;

        if ($inventory === null) {
            $this->finishOpening(
                $player,
                $winner
            );

            return;
        }

        $steps = $this->main
            ->getConfigManager()
            ->getInt('crates.animation-steps', 36);

        // Snapshot the pacing table once: re-reading config and re-scanning
        // the delay ranges on every one of the ~36 frames is pure waste.
        $delays = $this->snapshotAnimationDelays();

        // Snapshot the reward total once for the whole spin (see
        // rollRewardWithTotal): ~150 O(R) scans per opening become 1.
        $displayTotal = $this->getTotalWeight();

        $runStep = function (
            int $step
        ) use (
            &$runStep,
            $player,
            $winner,
            $inventory,
            $steps,
            $delays,
            $displayTotal
        ): void {
            if (!$player->isConnected()) {
                $this->destroyFloatingItem();

                $this->busy = false;
                $this->openingPlayerId = null;
                $this->pendingReward = null;

                $this->readOnlyInventory
                    ->remove($inventory);

                return;
            }

            if (
                $this->openingPlayerId !==
                strtolower($player->getName())
            ) {
                return;
            }

            if ($step >= $steps) {
                $inventory->clearAll();

                $inventory->setItem(
                    13,
                    $winner->getItem()
                );

                $this->showFloatingItem(
                    $winner->getItem()
                );

                $this->playWinEffects();

                $this->main->getScheduler()
                    ->scheduleDelayedTask(
                        new ClosureTask(
                            function () use (
                                $player,
                                $winner
                            ): void {
                                $this->finishOpening(
                                    $player,
                                    $winner
                                );
                            }
                        ),
                        $this->main
                            ->getConfigManager()
                            ->getInt('crates.reveal-delay', 20)
                    );

                return;
            }

            $display =
                $this->getRandomDisplayItem($displayTotal);

            if ($display === null) {
                $this->finishOpening(
                    $player,
                    $winner
                );

                return;
            }

            $inventory->clearAll();

            $slots = [
                10,
                11,
                12,
                13,
                14,
                15,
                16
            ];

            foreach (
                $slots as $index => $slot
            ) {
                if ($index === 3) {
                    continue;
                }

                if (mt_rand(0, 100) <= 45) {
                    $random =
                        $this->getRandomDisplayItem($displayTotal);

                    if ($random !== null) {
                        $inventory->setItem(
                            $slot,
                            $random
                        );
                    }
                }
            }

            $inventory->setItem(
                13,
                clone $display
            );

            $this->showFloatingItem(
                $display
            );

            $this->playRollEffects(
                $display
            );

            $this->main->getScheduler()
                ->scheduleDelayedTask(
                    new ClosureTask(
                        function () use (
                            &$runStep,
                            $step
                        ): void {
                            $runStep(
                                $step + 1
                            );
                        }
                    ),
                    $this->getAnimationDelay(
                        $delays,
                        $step
                    )
                );
        };

        $runStep(0);
    }

    /**
     * Ticks to wait before the next spin frame.
     *
     * The table comes from config (`crates.animation-delays`), keyed by the first
     * frame of each range. A frame past the last key reuses that key's delay, so
     * a server with more steps than the default table still looks correct.
     * Snapshotted once per opening (see playAnimation), not re-read per frame.
     *
     * @return list<array{from: int, delay: int}> sorted by `from`
     */
    private function snapshotAnimationDelays(): array
    {
        $table = $this->main
            ->getConfigManager()
            ->get('crates.animation-delays');

        if (!is_array($table) || $table === []) {
            return [['from' => 0, 'delay' => 3]];
        }

        $delays = [];

        foreach ($table as $from => $ticks) {
            if (!is_numeric($from) || !is_numeric($ticks)) {
                continue;
            }

            $delays[] = ['from' => (int) $from, 'delay' => max(1, (int) $ticks)];
        }

        if ($delays === []) {
            return [['from' => 0, 'delay' => 3]];
        }

        usort(
            $delays,
            static fn(array $a, array $b): int => $a['from'] <=> $b['from']
        );

        return $delays;
    }

    /**
     * @param list<array{from: int, delay: int}> $delays
     */
    private function getAnimationDelay(
        array $delays,
        int $step
    ): int {
        $delay = 3;

        foreach ($delays as $entry) {
            if ($entry['from'] > $step) {
                break;
            }

            $delay = $entry['delay'];
        }

        return max(1, $delay);
    }

    private function showFloatingItem(
        Item $item
    ): void
    {
        $this->destroyFloatingItem();

        $position = $this->position->add(
            0.5,
            1.35,
            0.5
        );

        $location = new Location(
            $position->x,
            $position->y,
            $position->z,
            $this->getWorld(),
            0.0,
            0.0
        );

        $entity = new ItemEntity(
            $location,
            clone $item
        );

        $entity->setHasGravity(false);
        $entity->setGravity(0.0);
        $entity->setPickupDelay(
            ItemEntity::NEVER_DESPAWN
        );
        $entity->setDespawnDelay(
            ItemEntity::NEVER_DESPAWN
        );

        $entity->spawnToAll();

        $this->floatingItem = $entity;
    }

    private function destroyFloatingItem(): void
    {
        if ($this->floatingItem === null) {
            return;
        }

        if (
            !$this->floatingItem
                ->isFlaggedForDespawn()
        ) {
            $this->floatingItem
                ->flagForDespawn();
        }

        $this->floatingItem = null;
    }

    private function playRollEffects(
        Item $item
    ): void
    {
        $world = $this->getWorld();

        $center = $this->position->add(
            0.5,
            1.2,
            0.5
        );

        $world->addSound(
            $center,
            new PopSound(
                mt_rand(80, 120) / 100
            )
        );

        $world->addParticle(
            $center,
            new ItemBreakParticle(
                clone $item
            )
        );

        for ($i = 0; $i < 3; ++$i) {
            $angle =
                (M_PI * 2 / 3) * $i;

            $particlePosition =
                $center->add(
                    cos($angle) * 0.45,
                    mt_rand(0, 5) / 10,
                    sin($angle) * 0.45
                );

            $world->addParticle(
                $particlePosition,
                new DustParticle(
                    new Color(
                        170,
                        60,
                        255
                    )
                )
            );
        }
    }

    private function playWinEffects(): void
    {
        $world = $this->getWorld();

        $center = $this->position->add(
            0.5,
            1.35,
            0.5
        );

        $world->addSound(
            $center,
            new XpLevelUpSound(10)
        );

        $world->addParticle(
            $center,
            new ExplodeParticle()
        );

        for ($i = 0; $i < 12; ++$i) {
            $world->addParticle(
                $center->add(
                    mt_rand(-10, 10) / 10,
                    mt_rand(0, 12) / 10,
                    mt_rand(-10, 10) / 10
                ),
                new HappyVillagerParticle()
            );
        }

        for ($i = 0; $i < 8; ++$i) {
            $angle =
                (M_PI * 2 / 8) * $i;

            $world->addParticle(
                $center->add(
                    cos($angle) * 0.7,
                    0.2,
                    sin($angle) * 0.7
                ),
                new DustParticle(
                    new Color(
                        255,
                        190,
                        30
                    )
                )
            );
        }
    }

    /**
     * Closes the crate window and gives the item to the player. Leftovers are
     * dropped at their feet rather than deleted, so a full inventory never eats
     * a paid reward.
     */
    private function finishOpening(
        Player $player,
        Reward $winner
    ): void {
        $inventory = $this->animationInventory;

        $this->destroyFloatingItem();

        $this->busy = false;
        $this->openingPlayerId = null;
        $this->pendingReward = null;
        $this->animationInventory = null;

        if ($inventory !== null) {
            $this->readOnlyInventory
                ->remove($inventory);

            if (
                $player->getCurrentWindow() ===
                $inventory
            ) {
                $player->removeCurrentWindow();
            }
        }

        if (!$player->isConnected()) {
            return;
        }

        $item = $winner->getItem();

        $leftovers = $player
            ->getInventory()
            ->addItem($item);

        foreach (
            $leftovers as $leftover
        ) {
            $player->dropItem($leftover);
        }

        $this->showReward($player, $winner);
    }

    /**
     * The result screen: what was won and how rare it is. A form rather than
     * chat spam, so the moment reads as a moment.
     */
    private function showReward(
        Player $player,
        Reward $winner
    ): void {
        $item = $winner->getItem();
        $chance = $winner->getChancePercent($this->getTotalWeight());

        Ui::menu(
            $this->main,
            $player,
            $this->name . ' crate',
            "§dCrate Reward: §f" . $item->getName()
            . "\n§7Rarity: §f" . $winner->getType()
            . ' §8| §7Chance: §f' . NumberFormatter::trim($chance) . '%',
            ['§aNice!' => static function(): void {
            }]
        );
    }

    /**
     * Routes a closed window: preview closes just release the lock, while the
     * animation window is forced back open so the spin cannot be skipped.
     * The closed inventory arrives from the listener, which matched it with
     * {@link isMyWindow()} first.
     */
    public function handleClose(
        Player $player,
        Inventory $inventory
    ): void
    {
        $playerId = strtolower($player->getName());

        if (
            $inventory !== $this->animationInventory
            && isset(
                $this->previewViewers[$playerId]
            )
        ) {
            $this->forgetPreview($playerId);

            return;
        }

        if (
            !$this->busy ||
            $this->openingPlayerId !==
            $playerId ||
            $inventory !== $this->animationInventory
        ) {
            return;
        }

        $this->main->getScheduler()
            ->scheduleDelayedTask(
                new ClosureTask(
                    function () use (
                        $player,
                        $inventory
                    ): void {
                        if (
                            !$player->isConnected()
                        ) {
                            return;
                        }

                        if (!$this->busy) {
                            return;
                        }

                        if (
                            $player->getCurrentWindow()
                            !== null
                        ) {
                            return;
                        }

                        // ReadOnlyInventory auto-removed this window on close
                        // (MONITOR), so reopening through it re-arms the
                        // read-only flag and the fake chest together.
                        $this->readOnlyInventory->open($player, $inventory, $this->name);
                    }
                ),
                1
            );
    }

    public function handleQuit(
        Player $player
    ): void
    {
        $playerId = strtolower($player->getName());

        if (
            $this->openingPlayerId ===
            $playerId
        ) {
            $reward =
                $this->pendingReward;

            $this->destroyFloatingItem();

            $this->busy = false;
            $this->openingPlayerId = null;
            $this->pendingReward = null;

            $inventory = $this->animationInventory;

            $this->animationInventory = null;

            if ($inventory !== null) {
                $this->readOnlyInventory
                    ->remove($inventory);
            }

            if ($reward !== null) {
                $this->getWorld()->dropItem(
                    $this->position->add(
                        0.5,
                        1.0,
                        0.5
                    ),
                    $reward->getItem()
                );
            }

            return;
        }

        $this->forgetPreview($playerId);
    }

    public function save(): void
    {
        $this->main
            ->getCrateManager()
            ->save(
                $this->name
            );
    }

    /**
     * @return list<string> every usable color name, for help text
     */
    public static function colorNames(): array
    {
        $names = [];

        foreach (DyeColor::cases() as $case) {
            $names[] = strtolower($case->name);
        }

        return $names;
    }

    /**
     * Resolves a color name typed by an admin ("purple", "red", ...) to a dye
     * color, case-insensitively. Returns null for unknown names.
     */
    public static function colorFromName(
        string $name
    ): ?DyeColor {
        $name = strtoupper(trim($name));

        foreach (DyeColor::cases() as $case) {
            if ($case->name === $name) {
                return $case;
            }
        }

        return null;
    }

    /**
     * @return array{
     *     world: string,
     *     x: float,
     *     y: float,
     *     z: float,
     *     color: string,
     *     keys: list<string>,
     *     rewards: list<array{item: string, weight: float, type: string}>
     * }
     */
    public function toArray(): array
    {
        return [
            'world' =>
                $this->getWorld()->getFolderName(),

            'x' => (float) $this->position->x,
            'y' => (float) $this->position->y,
            'z' => (float) $this->position->z,

            'color' => strtolower($this->color->name),

            'keys' => $this->getKeys(),

            'rewards' => array_values(
                array_map(
                    static fn(
                        Reward $reward
                    ): array => $reward->toArray(),
                    $this->rewards
                )
            )
        ];
    }
}