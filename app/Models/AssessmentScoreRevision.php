<?php

namespace App\Models;

use App\Enums\ScoreRevisionKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only grade change history: previous value, new value, actor, time,
 * and (for corrections of finalized scores) the reason.
 *
 * Revisions are removed only together with a deleted draft assessment, and
 * that deletion is itself recorded in the audit log with the scores.
 */
class AssessmentScoreRevision extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => ScoreRevisionKind::class,
            'previous_score' => 'decimal:2',
            'new_score' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Score revisions cannot be modified.');
        });
    }

    /**
     * @return BelongsTo<AssessmentScore, $this>
     */
    public function assessmentScore(): BelongsTo
    {
        return $this->belongsTo(AssessmentScore::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function changer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
