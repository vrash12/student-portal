<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An expense defined once by finance staff (e.g. "Uniform set", 3,500.00,
 * due 15 Sep) and assigned to candidates. Each assignment is a charge entry
 * linked to the expense. Once assigned, its amount and category are fixed;
 * expenses are deactivated, never deleted. Change through AccountService.
 */
class AccountExpense extends Model
{
    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'due_on' => 'date',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<AccountCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(AccountCategory::class, 'account_category_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The charges of this expense, voided ones included.
     *
     * @return HasMany<AccountEntry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(AccountEntry::class);
    }

    /** Whether any candidate is charged this expense (voided charges do not count). */
    public function isAssigned(): bool
    {
        return $this->entries()->standing()->exists();
    }

    /**
     * @param  Builder<AccountExpense>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
