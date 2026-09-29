<?php

namespace App\Enums;

/**
 * Question types supported by the question bank (Milestone 7).
 *
 * Objective types are scored automatically from their single correct choice.
 * Essays have no choices and are graded manually by an instructor.
 * True/False questions always have exactly two choices, "True" (position 1)
 * and "False" (position 2).
 *
 * Values are also enforced by a CHECK constraint on questions.type. New types
 * (multiple response, matching, ...) can be added later by extending this
 * enum and that constraint.
 */
enum QuestionType: string
{
    case MultipleChoice = 'multiple_choice';
    case TrueFalse = 'true_false';
    case Essay = 'essay';

    public const MIN_CHOICES = 2;

    public const MAX_CHOICES = 6;

    public const TRUE_FALSE_CHOICES = ['True', 'False'];

    public function label(): string
    {
        return match ($this) {
            self::MultipleChoice => 'Multiple Choice',
            self::TrueFalse => 'True / False',
            self::Essay => 'Essay',
        };
    }

    /**
     * Scored automatically from the correct choice.
     */
    public function isObjective(): bool
    {
        return $this !== self::Essay;
    }

    public function hasChoices(): bool
    {
        return $this->isObjective();
    }

    /**
     * @return array{value: string, label: string}
     */
    public function toArray(): array
    {
        return ['value' => $this->value, 'label' => $this->label()];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $type): array => $type->toArray(), self::cases());
    }
}
