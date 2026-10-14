<?php

declare(strict_types=1);

namespace AM\SkyMineZ\lobby;

use AM\SkyMineZ\Main;
use AM\SkyMineZ\useless\BlockTransactions;
use pocketmine\entity\Location;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\block\BlockPlaceEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerExhaustEvent;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\player\Player;

/**
 * Guards the lobby map and brings players to it.
 *
 * Join teleport: first join always (when a lobby exists), every join only when
 * `lobby.join-teleport` is `always`. Anything else (`never`, typos) behaves
 * like first-join-only, which is the safe direction.
 *
 * Protection is map-wide: the world holding the hub target is the lobby map,
 * and non-staff cannot break, place or right-click blocks anywhere in it.
 * Staff means server ops and `skyminez.admin` holders. Blocks owned by other
 * features (crates, slapper blocks, mines) are left for their own handlers
 * so those systems keep working inside the lobby map — this listener is
 * registered first precisely so it can step aside for them.
 *
 * Fall damage, void rescue and hunger apply to everyone in the lobby map:
 * void victims are pulled back onto solid ground above them when there is
 * any, and teleported to the hub otherwise.
 */
final class LobbyListener implements Listener
{
    public function __construct(
        private Main $main
    ) {
    }

    public function onJoin(
        PlayerJoinEvent $event
    ): void {
        $player = $event->getPlayer();

        $lobby = $this->main->getLobbyManager()->getLobby();

        if ($lobby === null) {
            return;
        }

        $mode = $this->main->getLobbyManager()->joinTeleportMode();

        if ($mode === 'never') {
            return;
        }

        if ($mode === 'always' || !$player->hasPlayedBefore()) {
            $player->teleport($lobby);
        }
    }

    public function onBreak(
        BlockBreakEvent $event
    ): void {
        if ($event->isCancelled()) {
            return;
        }

        $player = $event->getPlayer();

        if ($this->isExempt($player)) {
            return;
        }

        $block = $event->getBlock()->getPosition();
        $manager = $this->main->getLobbyManager();

        if (!$manager->isGuarded($block->getWorld())) {
            return;
        }

        // Feature blocks own their breaks: crates refuse with a message,
        // slapper blocks are protected, and mine breaks pay out rewards.
        if (
            $this->main->getCrateManager()->getCrateAt($block) !== null
            || $this->main->getSlapperManager()->getBlockAt($block) !== null
            || $this->main->getMineManager()->getMineAt($block->getWorld(), $block) !== null
        ) {
            return;
        }

        $event->cancel();
    }

    public function onPlace(
        BlockPlaceEvent $event
    ): void {
        if ($event->isCancelled()) {
            return;
        }

        $player = $event->getPlayer();

        if ($this->isExempt($player)) {
            return;
        }

        $manager = $this->main->getLobbyManager();
        $world = $player->getWorld();

        if (!$manager->isGuarded($world)) {
            return;
        }

        foreach (BlockTransactions::vectors($event) as $position) {
            // Mine placements stay owned by the mine handler (which refuses
            // them); everything else in the lobby map is refused here.
            if ($this->main->getMineManager()->getMineAt($world, $position) !== null) {
                continue;
            }

            $event->cancel();

            return;
        }
    }

    public function onInteract(
        PlayerInteractEvent $event
    ): void {
        if (
            $event->getAction() !== PlayerInteractEvent::RIGHT_CLICK_BLOCK
            || $event->isCancelled()
        ) {
            return;
        }

        $player = $event->getPlayer();

        if ($this->isExempt($player)) {
            return;
        }

        $block = $event->getBlock()->getPosition();

        if (!$this->main->getLobbyManager()->isGuarded($block->getWorld())) {
            return;
        }

        /*
         * Crates and slapper blocks need the click: previews, openings and
         * slapper commands run in their own handlers on this same event, and
         * this listener runs first precisely so it can step aside for them.
         * Everything else (containers, doors, buttons) stays shut.
         */
        if (
            $this->main->getCrateManager()->getCrateAt($block) !== null
            || $this->main->getSlapperManager()->getBlockAt($block) !== null
        ) {
            return;
        }

        $event->cancel();
    }

    public function onDamage(
        EntityDamageEvent $event
    ): void {
        if ($event->isCancelled()) {
            return;
        }

        $entity = $event->getEntity();

        if (!$entity instanceof Player) {
            return;
        }

        $manager = $this->main->getLobbyManager();

        if (!$manager->isGuarded($entity->getWorld())) {
            return;
        }

        if (
            $event->getCause() === EntityDamageEvent::CAUSE_FALL
            && $manager->noFallDamage()
        ) {
            $event->cancel();

            return;
        }

        if (
            $event->getCause() === EntityDamageEvent::CAUSE_VOID
            && $manager->voidRescue()
        ) {
            $event->cancel();

            $this->rescueFromVoid($entity);
        }
    }

    public function onExhaust(
        PlayerExhaustEvent $event
    ): void {
        if ($event->isCancelled()) {
            return;
        }

        $player = $event->getPlayer();

        if (
            $this->main->getLobbyManager()->noHunger()
            && $this->main->getLobbyManager()->isGuarded($player->getWorld())
        ) {
            $event->cancel();
        }
    }

    /**
     * Staff bypass map protection: server ops and `skyminez.admin` holders can
     * still build and use blocks in the lobby map.
     */
    private function isExempt(
        Player $player
    ): bool {
        return $this->main->getServer()->isOp($player->getName()) # Lets test
            || $player->hasPermission(Main::PERMISSION_ADMIN);
    }

    /**
     * Pulls a void victim back onto solid ground above them when the column
     * has any, and teleports them to the hub (then the default spawn) when it
     * has not. Cancelling void damage without moving the player would leave
     * them falling forever.
     */
    private function rescueFromVoid(
        Player $player
    ): void {
        $location = $player->getLocation();
        $world = $location->getWorld();

        try {
            $surface = $world->getHighestBlockAt(
                $location->getFloorX(),
                $location->getFloorZ()
            );
        } catch (\Throwable) {
            $surface = null;
        }

        if ($surface !== null) {
            $y = $surface + 1.0;

            if (method_exists($world, 'getMaxY')) {
                try {
                    /** @var int $maxY */
                    $maxY = $world->getMaxY();
                    $y = min($y, (float) $maxY);
                } catch (\Throwable) {
                }
            }

            $player->teleport(
                new Location(
                    $location->x,
                    $y,
                    $location->z,
                    $world,
                    $location->yaw,
                    $location->pitch
                )
            );

            return;
        }

        $hub = $this->main->getLobbyManager()->getHub()
            ?? $this->main->getServer()->getWorldManager()->getDefaultWorld()?->getSpawnLocation();

        if ($hub !== null) {
            $player->teleport($hub);
        }
    }
}
