<?php

namespace App\Enums;

/**
 * Where a notice stands at a moment, derived from its dates and whether it
 * was withdrawn (never stored): candidates see only current notices.
 */
enum AnnouncementStatus: string
{
    case Scheduled = 'scheduled';
    case Current = 'current';
    case Expired = 'expired';
    case Withdrawn = 'withdrawn';

    public function label(): string
    {
        return match ($this) {
            self::Scheduled => 'Scheduled',
            self::Current => 'Showing',
            self::Expired => 'Ended',
            self::Withdrawn => 'Withdrawn',
        };
    }

    /** Badge tone used by the interface. Always shown together with the label. */
    public function tone(): string
    {
        return match ($this) {
            self::Scheduled => 'info',
            self::Current => 'success',
            self::Expired, self::Withdrawn => 'neutral',
        };
    }

    /**
     * @return array{value: string, label: string, tone: string}
     */
    public function toArray(): array
    {
        return ['value' => $this->value, 'label' => $this->label(), 'tone' => $this->tone()];
    }
}
