<?php

namespace App\Enums;

/**
 * Which candidates see a notice (owner request, 2026-10-06): every candidate,
 * the candidates of one campus, or the candidates of one class. Values are
 * also enforced by a CHECK constraint together with the campus and class
 * columns each one needs.
 */
enum AnnouncementAudience: string
{
    case Everyone = 'everyone';
    case Campus = 'campus';
    case ClassBatch = 'class';

    public function label(): string
    {
        return match ($this) {
            self::Everyone => 'Every candidate',
            self::Campus => 'A campus',
            self::ClassBatch => 'A class',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Everyone => 'Every candidate of every campus.',
            self::Campus => 'Every candidate of one campus.',
            self::ClassBatch => 'The candidates of one class.',
        };
    }
}
