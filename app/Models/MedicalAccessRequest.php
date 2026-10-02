<?php

namespace App\Models;

use App\Enums\MedicalAccessStatus;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An instructor's request to see one candidate's full medical record, from
 * the time such access had to be approved. Retired by the owner on
 * 2026-10-02: instructors of the candidate's class now view the record
 * without asking (view only). Kept read-only for the history and the audit
 * entries of earlier requests; nothing creates or changes them any more.
 */
class MedicalAccessRequest extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => MedicalAccessStatus::class,
            'decided_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function isPending(): bool
    {
        return $this->status === MedicalAccessStatus::Pending;
    }

    /** Approved, not withdrawn, and not yet past its end. */
    public function isActive(): bool
    {
        return $this->status === MedicalAccessStatus::Approved && $this->expires_at !== null && $this->expires_at->isFuture();
    }

    /**
     * The state people see: stored status, with ended approvals as "expired".
     *
     * @return array{value: string, label: string, tone: string}
     */
    public function displayStatus(): array
    {
        if ($this->status === MedicalAccessStatus::Approved && ! $this->isActive()) {
            return ['value' => 'expired', 'label' => 'Access Ended', 'tone' => 'neutral'];
        }

        return ['value' => $this->status->value, 'label' => $this->status->label(), 'tone' => $this->status->tone()];
    }

    /**
     * Approved access that has not ended yet.
     *
     * @param  Builder<MedicalAccessRequest>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('status', MedicalAccessStatus::Approved->value)->where('expires_at', '>', now());
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

    /**
     * @return BelongsTo<User, $this>
     */
    public function revoker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }
}
