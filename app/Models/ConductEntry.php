<?php

namespace App\Models;

use App\Enums\ConductKind;
use App\Policies\ConductPolicy;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One merit or demerit of a candidate. Entries are never edited or deleted:
 * a mistaken entry is voided with a reason (and excluded from the totals)
 * and the correct entry recorded. The kind is copied from the type when the
 * entry is recorded. Change through ConductService.
 */
#[UsePolicy(ConductPolicy::class)]
class ConductEntry extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => ConductKind::class,
            'points' => 'integer',
            'occurred_on' => 'date',
            'voided_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Candidate, $this>
     */
    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class);
    }

    /**
     * @return BelongsTo<ConductType, $this>
     */
    public function type(): BelongsTo
    {
        return $this->belongsTo(ConductType::class, 'conduct_type_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }

    /**
     * Entries that count toward the totals.
     *
     * @param  Builder<ConductEntry>  $query
     */
    #[Scope]
    protected function standing(Builder $query): void
    {
        $query->whereNull('voided_at');
    }
}
