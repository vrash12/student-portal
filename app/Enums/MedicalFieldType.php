<?php

namespace App\Enums;

/**
 * Kind of answer a medical record field takes. Values are stored as text in
 * candidate_medical_values; the type decides how they are entered, checked
 * and shown. Values are also enforced by a CHECK constraint.
 */
enum MedicalFieldType: string
{
    case Text = 'text';
    case LongText = 'long_text';
    case Choice = 'choice';
    case Date = 'date';
    case YesNo = 'yes_no';

    public const TEXT_MAX = 255;

    public const LONG_TEXT_MAX = 2000;

    public function label(): string
    {
        return match ($this) {
            self::Text => 'Short text',
            self::LongText => 'Long text',
            self::Choice => 'Choice from a list',
            self::Date => 'Date',
            self::YesNo => 'Yes or no',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Text => 'One line, up to '.self::TEXT_MAX.' characters. For example, a physician\'s name.',
            self::LongText => 'Several lines, up to '.number_format(self::LONG_TEXT_MAX).' characters. For example, allergies or conditions.',
            self::Choice => 'One answer from a list you set. For example, a blood type.',
            self::Date => 'A calendar date. For example, the last physical examination.',
            self::YesNo => 'Yes or no. For example, cleared for strenuous training.',
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
