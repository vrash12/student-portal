<?php

namespace App\Enums;

/**
 * Whether an assessment built from the question bank is a quiz or an
 * examination (Milestone 8). Both follow the same rules; the kind is a label
 * that instructors choose and candidates see.
 *
 * Values are also enforced by a CHECK constraint on examinations.kind.
 */
enum ExaminationKind: string
{
    case Quiz = 'quiz';
    case Examination = 'examination';

    public function label(): string
    {
        return match ($this) {
            self::Quiz => 'Quiz',
            self::Examination => 'Examination',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Quiz => 'A shorter check of understanding, usually within a lesson or topic.',
            self::Examination => 'A formal examination, such as a midterm or final examination.',
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
            fn (self $kind): array => [...$kind->toArray(), 'description' => $kind->description()],
            self::cases(),
        );
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $kind): string => $kind->value, self::cases());
    }
}
