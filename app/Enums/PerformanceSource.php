<?php

namespace App\Enums;

/**
 * Where a performance area takes its grade from. Subject areas average the
 * grades of the subjects mapped to them; the other sources read the latest
 * fitness test, the conduct ledger or the attendance ledger, and at most one
 * active area may use each of them. Values are also enforced by a CHECK
 * constraint on performance_areas.source.
 */
enum PerformanceSource: string
{
    case Subjects = 'subjects';
    case Fitness = 'fitness';
    case Conduct = 'conduct';
    case Attendance = 'attendance';

    public function label(): string
    {
        return match ($this) {
            self::Subjects => 'Subject Grades',
            self::Fitness => 'Military Fitness',
            self::Conduct => 'Conduct',
            self::Attendance => 'Attendance',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Subjects => 'The mean of the current grades of the subjects mapped to the area.',
            self::Fitness => 'The overall points of the latest fitness test of the class. Failing an event fails the area.',
            self::Conduct => 'A rating from a base value plus merit points and minus demerit points, limited to 0–100.',
            self::Attendance => 'The attendance rate: sessions attended (present or late) out of the sessions counted.',
        };
    }

    /** Only subject areas hold subjects. */
    public function holdsSubjects(): bool
    {
        return $this === self::Subjects;
    }

    /** At most one active area may use this source: it has a single result per candidate. */
    public function allowsSingleActiveArea(): bool
    {
        return $this !== self::Subjects;
    }

    /**
     * @return array{value: string, label: string}
     */
    public function toArray(): array
    {
        return ['value' => $this->value, 'label' => $this->label()];
    }

    /**
     * @return list<array{value: string, label: string, description: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $source): array => ['value' => $source->value, 'label' => $source->label(), 'description' => $source->description()],
            self::cases(),
        );
    }
}
