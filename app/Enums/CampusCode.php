<?php

namespace App\Enums;

/**
 * The institution's four campuses (owner decision 2026-10-04). The list is
 * fixed: no campus is added or removed through the application, and the
 * `campuses_code_check` constraint keeps any other code out of the database.
 * Administrators may only change a campus's address and switch it on or off.
 */
enum CampusCode: string
{
    case South = 'SOUTH';
    case North = 'NORTH';
    case East = 'EAST';
    case West = 'WEST';

    public function label(): string
    {
        return match ($this) {
            self::South => 'South Campus',
            self::North => 'North Campus',
            self::East => 'East Campus',
            self::West => 'West Campus',
        };
    }

    /** Position in lists: the order the owner gave (South, North, East, West). */
    public function position(): int
    {
        return (int) array_search($this, self::cases(), true);
    }
}
