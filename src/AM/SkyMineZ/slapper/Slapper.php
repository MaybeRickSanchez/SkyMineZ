<?php

declare(strict_types=1);

namespace AM\SkyMineZ\slapper;

use AM\SkyMineZ\useless\Arrays;
use pocketmine\entity\Human;
use pocketmine\entity\Location;
use pocketmine\entity\Skin;
use pocketmine\player\Player;
use pocketmine\world\Position;

final class Slapper
{
    /**
     * @var list<string>
     */
    private array $commands = [];

    /**
     * @var list<string>
     */
    private array $messages = [];

    private ?Human $entity = null;

    public function __construct(
        private string $name,
        private Location $location,
        private Skin $skin
    ) {
    }

    public function spawn(): void
    {
        $this->despawn();

        $this->entity = new Human(
            $this->location,
            $this->skin
        );

        $this->entity->setNameTag(
            $this->name
        );

        $this->entity->setNameTagVisible(
            true
        );

        $this->entity->setNameTagAlwaysVisible(
            true
        );

        $this->entity->setHasGravity(
            false
        );

        $this->entity->setCanSaveWithChunk(
            false
        );

        $this->entity->spawnToAll();
    }

    public function despawn(): void
    {
        if ($this->entity === null) {
            return;
        }

        if (
            !$this->entity
                ->isFlaggedForDespawn()
        ) {
            $this->entity->flagForDespawn();
        }

        $this->entity = null;
    }

    public function getEntity(): ?Human
    {
        return $this->entity;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(
        string $name
    ): self {
        $this->name = $name;

        if ($this->entity !== null) {
            $this->entity->setNameTag(
                $name
            );
        }

        return $this;
    }

    public function getLocation(): Location
    {
        return $this->location;
    }

    /**
     * Moves the slapper, keeping its yaw and pitch.
     *
     * The live entity is teleported rather than respawned so the skin and name
     * tag stay put and no client sees a disappear/reappear flicker.
     */
    public function setPosition(
        Position $position
    ): self {
        return $this->setLocation(
            new Location(
                $position->x,
                $position->y,
                $position->z,
                $position->getWorld(),
                $this->location->yaw,
                $this->location->pitch
            )
        );
    }

    public function setLocation(
        Location $location
    ): self {
        $this->location = $location;

        if ($this->entity !== null) {
            $this->entity->teleport($location);
        }

        return $this;
    }

    public function getSkin(): Skin
    {
        return $this->skin;
    }

    public function setSkin(
        Skin $skin
    ): self {
        $this->skin = $skin;

        if ($this->entity !== null) {
            $this->entity->setSkin(
                $skin
            );

            $this->entity->sendSkin();
        }

        return $this;
    }

    public function addCommand(
        string $command
    ): self {
        $this->commands[] = $command;

        return $this;
    }

    public function addMessage(
        string $message
    ): self {
        $this->messages[] = $message;

        return $this;
    }

    public function clearCommands(): self
    {
        $this->commands = [];

        return $this;
    }

    public function clearMessages(): self
    {
        $this->messages = [];

        return $this;
    }

    /**
     * Removes one entry by its list index, repacking the list so the remaining
     * indexes stay consecutive.
     *
     * @return bool false when the index does not exist
     */
    public function removeCommand(
        int $index
    ): bool {
        if (!isset($this->commands[$index])) {
            return false;
        }

        $this->commands = Arrays::removeIndex($this->commands, $index);

        return true;
    }

    /**
     * Removes one entry by its list index, repacking the list so the remaining
     * indexes stay consecutive.
     *
     * @return bool false when the index does not exist
     */
    public function removeMessage(
        int $index
    ): bool {
        if (!isset($this->messages[$index])) {
            return false;
        }

        $this->messages = Arrays::removeIndex($this->messages, $index);

        return true;
    }

    /**
     * @return list<string>
     */
    public function getCommands(): array
    {
        return $this->commands;
    }

    /**
     * @return list<string>
     */
    public function getMessages(): array
    {
        return $this->messages;
    }

    public function execute(
        Player $player
    ): void {
        foreach ($this->messages as $message) {
            $player->sendMessage(
                $this->replacePlaceholders(
                    $message,
                    $player
                )
            );
        }

        foreach ($this->commands as $command) {
            $command =
                $this->replacePlaceholders(
                    $command,
                    $player
                );

            // "console: give ..." runs as the console (so give/warp work for
            // non-op clickers); anything else runs as the clicking player.
            if (str_starts_with(strtolower(ltrim($command)), 'console:')) {
                $consoleCmd = trim(substr($command, strpos($command, ':') + 1));

                $this->location
                    ->getWorld()
                    ->getServer()
                    ->dispatchCommand(
                        new \pocketmine\console\ConsoleCommandSender(
                            $this->location->getWorld()->getServer(),
                            $this->location->getWorld()->getServer()->getLanguage()
                        ),
                        ltrim($consoleCmd, '/')
                    );

                continue;
            }

            $this->location
                ->getWorld()
                ->getServer()
                ->dispatchCommand(
                    $player,
                    ltrim($command, '/')
                );
        }
    }

    private function replacePlaceholders(
        string $text,
        Player $player
    ): string {
        return str_replace(
            [
                '{player}',
                '{name}'
            ],
            [
                $player->getName(),
                $player->getName()
            ],
            $text
        );
    }

    /**
     * @return array{
     *     world: string,
     *     x: float,
     *     y: float,
     *     z: float,
     *     yaw: float,
     *     pitch: float,
     *     commands: list<string>,
     *     messages: list<string>,
     *     skin: array{
     *         id: string,
     *         data: string,
     *         cape: string,
     *         geometryName: string,
     *         geometryData: string
     *     }
     * }
     */
    public function toArray(): array
    {
        return [
            'world' =>
                $this->location
                    ->getWorld()
                    ->getFolderName(),

            'x' => $this->location->x,
            'y' => $this->location->y,
            'z' => $this->location->z,

            'yaw' => $this->location->yaw,
            'pitch' => $this->location->pitch,

            'commands' => $this->commands,
            'messages' => $this->messages,

            'skin' => [
                'id' => $this->skin->getSkinId(),
                'data' => base64_encode(
                    $this->skin->getSkinData()
                ),
                'cape' => base64_encode(
                    $this->skin->getCapeData()
                ),
                'geometryName' =>
                    $this->skin->getGeometryName(),
                'geometryData' => base64_encode(
                    $this->skin->getGeometryData()
                )
            ]
        ];
    }
}