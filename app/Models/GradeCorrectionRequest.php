<?php

namespace App\Models;

use App\Enums\CorrectionIncidentType;
use App\Enums\GradeCorrectionStatus;
use App\Policies\GradeCorrectionRequestPolicy;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An instructor's request to change one finalized score, with the incident
 * report that explains it. The score changes only when an administrator
 * approves the request. Requests are never deleted; change them through
 * GradeCorrectionService.
 */
#[UsePolicy(GradeCorrectionRequestPolicy::class)]
class GradeCorrectionRequest extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'current_score' => 'decimal:2',
            'proposed_score' => 'decimal:2',
            'incident_type' => CorrectionIncidentType::class,
            'status' => GradeCorrectionStatus::class,
            'decided_at' => 'datetime',
        ];
    }

    public function isPending(): bool
    {
        return $this->status === GradeCorrectionStatus::Pending;
    }

    /**
     * @return BelongsTo<Assessment, $this>
     */
    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    /**
     * @return BelongsTo<Candidate, $this>
     */
    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
