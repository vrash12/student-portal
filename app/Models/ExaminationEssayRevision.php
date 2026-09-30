<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ExaminationEssayRevision extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['previous_score' => 'decimal:2', 'new_score' => 'decimal:2', 'version' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Grading history cannot be changed.'));
        static::deleting(fn () => throw new LogicException('Grading history cannot be deleted.'));
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
