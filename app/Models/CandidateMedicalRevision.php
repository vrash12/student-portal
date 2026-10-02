<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only history of a candidate's medical record: the previous and new
 * value of one field, who changed it and when. Only staff who may view
 * medical records read it.
 */
class CandidateMedicalRevision extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Medical record revisions cannot be modified.');
        });
    }

    /**
     * @return BelongsTo<MedicalField, $this>
     */
    public function field(): BelongsTo
    {
        return $this->belongsTo(MedicalField::class, 'medical_field_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function changer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
