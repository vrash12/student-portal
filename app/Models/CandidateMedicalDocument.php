<?php

namespace App\Models;

use App\Enums\MedicalDocumentCategory;
use App\Enums\MedicalDocumentStatus;
use App\Policies\CandidateMedicalDocumentPolicy;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A medical document a candidate uploaded (certificate, check-up findings,
 * laboratory result...). The stored `path` is never sent to the browser.
 * Change it through MedicalDocumentService.
 */
#[UsePolicy(CandidateMedicalDocumentPolicy::class)]
class CandidateMedicalDocument extends Model
{
    protected $hidden = ['path'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => MedicalDocumentCategory::class,
            'status' => MedicalDocumentStatus::class,
            'document_date' => 'date',
            'reviewed_at' => 'datetime',
            'size_bytes' => 'integer',
        ];
    }

    public function isWaiting(): bool
    {
        return $this->status === MedicalDocumentStatus::Submitted;
    }

    public function isPdf(): bool
    {
        return $this->mime_type === 'application/pdf';
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
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * Documents waiting for review.
     *
     * @param  Builder<CandidateMedicalDocument>  $query
     */
    #[Scope]
    protected function waiting(Builder $query): void
    {
        $query->where('status', MedicalDocumentStatus::Submitted->value);
    }

    /**
     * Documents instructors with approved access may see: returned uploads
     * (unreadable or wrong files) are left out.
     *
     * @param  Builder<CandidateMedicalDocument>  $query
     */
    #[Scope]
    protected function notReturned(Builder $query): void
    {
        $query->where('status', '!=', MedicalDocumentStatus::Returned->value);
    }
}
