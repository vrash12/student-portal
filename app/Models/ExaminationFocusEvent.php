<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One departure from the examination screen during an attempt. Written only
 * by App\Services\Examinations\ExaminationFocusService with server times.
 */
class ExaminationFocusEvent extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'examination_attempt_id' => 'integer',
            'left_at' => 'datetime',
            'returned_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ExaminationAttempt, $this>
     */
    public function attempt(): BelongsTo
    {
        return $this->belongsTo(ExaminationAttempt::class, 'examination_attempt_id');
    }
}
