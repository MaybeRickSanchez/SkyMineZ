<?php

declare(strict_types=1);

namespace AM\SkyMineZ\team;

use AM\SkyMineZ\useless\Arrays;

/**
 * One team: an owner, a member list, a level driven by duel XP, and a
 * win/loss record.
 *
 * Names are stored as typed; member and owner names are stored lowercase so
 * lookups never depend on how anyone capitalizes anything.
 */
final class Team
{
    /**
     * @param list<string> $members lowercase names, owner included
     */
    public function __construct(
        private string $name,
        private string $owner,
        private array $members = [],
        private int $level = 1,
        private int $xp = 0,
        private int $wins = 0,
        private int $losses = 0
    ) {
        $this->owner = strtolower($owner);

        $clean = [];

        foreach ($members as $member) {
            if (is_string($member) && $member !== '') {
                $clean[] = strtolower($member);
            }
        }

        if (!in_array($this->owner, $clean, true)) {
            $clean[] = $this->owner;
        }

        $this->members = $clean;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getOwner(): string
    {
        return $this->owner;
    }

    public function isOwner(
        string $playerName
    ): bool {
        return strtolower($playerName) === $this->owner;
    }

    /**
     * @return list<string>
     */
    public function getMembers(): array
    {
        return $this->members;
    }

    public function isMember(
        string $playerName
    ): bool {
        return in_array(strtolower($playerName), $this->members, true);
    }

    public function memberCount(): int
    {
        return count($this->members);
    }

    public function addMember(
        string $playerName
    ): bool {
        $playerName = strtolower($playerName);

        if (in_array($playerName, $this->members, true)) {
            return false;
        }

        $this->members[] = $playerName;

        return true;
    }

    /**
     * Removes a member. When the owner leaves, ownership passes to the oldest
     * remaining member so the team never ends up leaderless. Returns false when
     * the player was not on the team at all.
     */
    public function removeMember(
        string $playerName
    ): bool {
        $playerName = strtolower($playerName);

        $index = array_search($playerName, $this->members, true);

        if ($index === false) {
            return false;
        }

        $this->members = Arrays::removeIndex($this->members, (int) $index);

        if ($playerName === $this->owner && $this->members !== []) {
            $this->owner = $this->members[0];
        }

        return true;
    }

    public function getLevel(): int
    {
        return $this->level;
    }

    public function getXp(): int
    {
        return $this->xp;
    }

    public function getWins(): int
    {
        return $this->wins;
    }

    public function getLosses(): int
    {
        return $this->losses;
    }

    /**
     * @return bool true when this XP pushed the team over a level boundary
     */
    public function addXp(
        int $amount,
        int $xpPerLevel
    ): bool {
        if ($amount <= 0 || $xpPerLevel <= 0) {
            return false;
        }

        $before = $this->level;

        $this->xp += $amount;
        $this->level = 1 + intdiv($this->xp, $xpPerLevel);

        return $this->level > $before;
    }

    public function addWin(): void
    {
        ++$this->wins;
    }

    public function addLoss(): void
    {
        ++$this->losses;
    }

    /**
     * @return array{
     *     owner: string,
     *     members: list<string>,
     *     level: int,
     *     xp: int,
     *     wins: int,
     *     losses: int
     * }
     */
    public function toArray(): array
    {
        return [
            'owner' => $this->owner,
            'members' => $this->members,
            'level' => $this->level,
            'xp' => $this->xp,
            'wins' => $this->wins,
            'losses' => $this->losses
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(
        string $name,
        array $data
    ): ?self {
        if (
            !isset($data['owner'])
            || !is_string($data['owner'])
            || $data['owner'] === ''
        ) {
            return null;
        }

        $members = [];

        foreach ((array) ($data['members'] ?? []) as $member) {
            if (is_string($member) && $member !== '') {
                $members[] = $member;
            }
        }

        $readInt = static function(
            array $data,
            string $key
        ): int {
            $value = $data[$key] ?? 0;

            return is_numeric($value) ? max(0, (int) $value) : 0;
        };

        return new self(
            $name,
            $data['owner'],
            $members,
            max(1, $readInt($data, 'level')),
            $readInt($data, 'xp'),
            $readInt($data, 'wins'),
            $readInt($data, 'losses')
        );
    }
}