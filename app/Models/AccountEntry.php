<?php

namespace App\Models;

use App\Enums\AccountEntryType;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a candidate's Statement of Account. Entries are never edited
 * or deleted: a mistaken entry is voided with a reason (and excluded from
 * balances) and the correct entry recorded. Change through AccountService.
 */
class AccountEntry extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'entry_type' => AccountEntryType::class,
            'amount' => 'decimal:2',
            'posted_on' => 'date',
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
     * @return BelongsTo<AccountCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(AccountCategory::class, 'account_category_id');
    }

    /**
     * The assigned expense this charge comes from, if any.
     *
     * @return BelongsTo<AccountExpense, $this>
     */
    public function expense(): BelongsTo
    {
        return $this->belongsTo(AccountExpense::class, 'account_expense_id');
    }

    /** The line's description: an assigned expense shows the expense's current name. */
    public function label(): string
    {
        return $this->account_expense_id !== null && $this->expense !== null ? $this->expense->name : $this->description;
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
     * Entries that count toward balances.
     *
     * @param  Builder<AccountEntry>  $query
     */
    #[Scope]
    protected function standing(Builder $query): void
    {
        $query->whereNull('voided_at');
    }
}
