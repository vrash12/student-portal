<?php

namespace App\Models;

use App\Enums\MedicalDownloadStatus;
use App\Policies\MedicalDownloadRequestPolicy;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An instructor's request to download one uploaded medical document. An
 * approval lets the requester download it until `expires_at`. Requests are
 * never deleted; change them through MedicalDownloadService.
 */
#[UsePolicy(MedicalDownloadRequestPolicy::class)]
class MedicalDownloadRequest extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => MedicalDownloadStatus::class,
            'decided_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_downloaded_at' => 'datetime',
            'download_count' => 'integer',
        ];
    }

    public function isPending(): bool
    {
        return $this->status === MedicalDownloadStatus::Pending;
    }

    /** Approved, not withdrawn, and not yet past its end. */
    public function isActive(): bool
    {
        return $this->status === MedicalDownloadStatus::Approved && $this->expires_at !== null && $this->expires_at->isFuture();
    }

    /**
     * The state people see: stored status, with ended approvals as "ended".
     *
     * @return array{value: string, label: string, tone: string}
     */
    public function displayStatus(): array
    {
        if ($this->status === MedicalDownloadStatus::Approved && ! $this->isActive()) {
            return ['value' => 'expired', 'label' => 'Download Ended', 'tone' => 'neutral'];
        }

        return ['value' => $this->status->value, 'label' => $this->status->label(), 'tone' => $this->status->tone()];
    }

    /**
     * Approvals that have not ended yet.
     *
     * @param  Builder<MedicalDownloadRequest>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('status', MedicalDownloadStatus::Approved->value)->where('expires_at', '>', now());
    }

    /**
     * @return BelongsTo<CandidateMedicalDocument, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(CandidateMedicalDocument::class, 'candidate_medical_document_id');
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
