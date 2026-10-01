<?php

namespace App\Enums;

/**
 * A candidate's attendance at one training session. Present and late count
 * as attended; absent does not; excused is left out of the attendance rate
 * (AttendanceLedger). Values are also enforced by a CHECK constraint.
 */
enum AttendanceStatus: string
{
    case Present = 'present';
    case Late = 'late';
    case Excused = 'excused';
    case Absent = 'absent';

    public function label(): string
    {
        return match ($this) {
            self::Present => 'Present',
            self::Late => 'Late',
            self::Excused => 'Excused',
            self::Absent => 'Absent',
        };
    }

    /**
     * Badge tone used by the interface. Always shown together with the label.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Present => 'success',
            self::Late => 'warning',
            self::Excused => 'info',
            self::Absent => 'danger',
        };
    }

    /** Whether the candidate attended (counts toward the rate and the hours). */
    public function attended(): bool
    {
        return $this === self::Present || $this === self::Late;
    }

    /**
     * @return array{value: string, label: string, tone: string}
     */
    public function toArray(): array
    {
        return ['value' => $this->value, 'label' => $this->label(), 'tone' => $this->tone()];
    }

    /**
     * @return list<array{value: string, label: string, tone: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $status): array => $status->toArray(), self::cases());
    }
}
