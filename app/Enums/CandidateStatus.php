<?php

namespace App\Enums;

/**
 * Enrollment status of a candidate record. This is distinct from academic
 * standing (Passing, Needs Improvement, Failing, Incomplete; see AcademicStanding),
 * which the grade engine calculates. Withdrawn candidates have no standing.
 *
 * Values are also enforced by a CHECK constraint on candidates.status.
 */
enum CandidateStatus: string
{
    case Enrolled = 'enrolled';
    case OnLeave = 'on_leave';
    case Withdrawn = 'withdrawn';
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::Enrolled => 'Enrolled',
            self::OnLeave => 'On Leave',
            self::Withdrawn => 'Withdrawn',
            self::Completed => 'Completed',
        };
    }

    /**
     * Badge tone used by the interface. Always shown together with the label.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Enrolled => 'success',
            self::OnLeave => 'warning',
            self::Withdrawn => 'neutral',
            self::Completed => 'info',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $status): array => ['value' => $status->value, 'label' => $status->label()],
            self::cases(),
        );
    }
}
