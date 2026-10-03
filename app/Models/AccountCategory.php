<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A configurable expense category (e.g. Uniforms, Meals). Categories are
 * always for charges and are listed by name (owner request, 2026-10-03).
 * Categories in use are deactivated, never deleted. Change through AccountService.
 */
#[Fillable(['name', 'description'])]
class AccountCategory extends Model
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
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<AccountEntry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(AccountEntry::class);
    }

    /**
     * @return HasMany<AccountExpense, $this>
     */
    public function expenses(): HasMany
    {
        return $this->hasMany(AccountExpense::class);
    }

    /**
     * @param  Builder<AccountCategory>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * @param  Builder<AccountCategory>  $query
     */
    #[Scope]
    protected function ordered(Builder $query): void
    {
        $query->orderBy('name');
    }
}
