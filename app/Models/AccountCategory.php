<?php

namespace App\Models;

use App\Enums\AccountEntryType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A configurable Statement of Account category (e.g. Uniforms, Meal
 * Allowance) with the side its entries usually take. Categories in use are
 * deactivated, never deleted. Change through AccountService.
 */
#[Fillable(['name', 'entry_type', 'description', 'sort_order'])]
class AccountCategory extends Model
{
    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
        'sort_order' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'entry_type' => AccountEntryType::class,
            'sort_order' => 'integer',
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
        $query->orderBy('sort_order')->orderBy('name');
    }
}
