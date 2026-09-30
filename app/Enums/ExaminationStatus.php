<?php

namespace App\Enums;

enum ExaminationStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft', self::Published => 'Published', self::Archived => 'Archived',
        };
    }

    public function toArray(): array
    {
        return ['value' => $this->value, 'label' => $this->label(), 'tone' => $this === self::Published ? 'success' : 'neutral'];
    }
}
