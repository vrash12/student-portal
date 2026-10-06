<?php

namespace App\Models;

use App\Enums\AnnouncementAudience;
use App\Enums\AnnouncementStatus;
use App\Policies\AnnouncementPolicy;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A notice to candidates (owner request, 2026-10-06), shown on the portal
 * home page of the candidates it is meant for (AnnouncementAudience) from
 * publishes_at until expires_at. The audience is fixed when the notice is
 * posted; a withdrawn notice is kept but never shown again. Change through
 * AnnouncementService.
 */
#[Fillable(['title', 'body', 'is_important', 'publishes_at', 'expires_at'])]
#[UsePolicy(AnnouncementPolicy::class)]
class Announcement extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'audience' => AnnouncementAudience::class,
            'campus_id' => 'integer',
            'class_batch_id' => 'integer',
            'created_by' => 'integer',
            'is_important' => 'boolean',
            'publishes_at' => 'datetime',
            'expires_at' => 'datetime',
            'withdrawn_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Campus, $this>
     */
    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }

    /**
     * @return BelongsTo<ClassBatch, $this>
     */
    public function classBatch(): BelongsTo
    {
        return $this->belongsTo(ClassBatch::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function withdrawer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'withdrawn_by');
    }

    public function status(?CarbonInterface $now = null): AnnouncementStatus
    {
        $now ??= now();

        return match (true) {
            $this->withdrawn_at !== null => AnnouncementStatus::Withdrawn,
            $this->publishes_at->gt($now) => AnnouncementStatus::Scheduled,
            $this->expires_at !== null && $this->expires_at->lte($now) => AnnouncementStatus::Expired,
            default => AnnouncementStatus::Current,
        };
    }

    /**
     * Notices candidates see now: published, not ended, not withdrawn.
     *
     * @param  Builder<Announcement>  $query
     */
    #[Scope]
    protected function showing(Builder $query, ?CarbonInterface $now = null): void
    {
        $now ??= now();

        $query->whereNull('withdrawn_at')
            ->where('publishes_at', '<=', $now)
            ->where(fn (Builder $query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', $now));
    }

    /**
     * Notices meant for the candidate: every candidate, the candidate's
     * campus, or the candidate's current class.
     *
     * @param  Builder<Announcement>  $query
     */
    #[Scope]
    protected function forCandidate(Builder $query, Candidate $candidate): void
    {
        $campusId = $candidate->campusId();
        $classId = $candidate->class_batch_id;

        $query->where(function (Builder $query) use ($campusId, $classId): void {
            $query->where('audience', AnnouncementAudience::Everyone->value);
            if ($campusId !== null) {
                $query->orWhere(fn (Builder $query) => $query->where('audience', AnnouncementAudience::Campus->value)->where('campus_id', $campusId));
            }
            if ($classId !== null) {
                $query->orWhere(fn (Builder $query) => $query->where('audience', AnnouncementAudience::ClassBatch->value)->where('class_batch_id', $classId));
            }
        });
    }
}
