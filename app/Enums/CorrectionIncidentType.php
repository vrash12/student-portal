<?php

namespace App\Enums;

/**
 * What went wrong with a finalized score, chosen on the incident report of a
 * grade correction request. Values are also enforced by a CHECK constraint.
 */
enum CorrectionIncidentType: string
{
    case EncodingError = 'encoding_error';
    case WrongCandidate = 'wrong_candidate';
    case ComputationError = 'computation_error';
    case Rechecked = 'rechecked';
    case LateRequirement = 'late_requirement';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::EncodingError => 'Encoding error',
            self::WrongCandidate => 'Score entered for the wrong candidate',
            self::ComputationError => 'Computation or checking error',
            self::Rechecked => 'Paper rechecked',
            self::LateRequirement => 'Late or make-up requirement',
            self::Other => 'Other',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::EncodingError => 'The wrong score was typed in.',
            self::WrongCandidate => 'The score belongs to another candidate.',
            self::ComputationError => 'The score was added up or checked wrongly.',
            self::Rechecked => 'The paper was checked again and the score changed.',
            self::LateRequirement => 'A late or make-up requirement was completed.',
            self::Other => 'Explain fully in the incident report.',
        };
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
            fn (self $type): array => ['value' => $type->value, 'label' => $type->label(), 'description' => $type->description()],
            self::cases(),
        );
    }
}
