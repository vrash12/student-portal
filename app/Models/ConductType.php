<?php

namespace App\Models;

use App\Enums\ConductKind;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A configurable merit or demerit type (e.g. "Late for formation") with the
 * points it usually carries. Types in use are deactivated, never deleted.
 * Change through ConductService.
 */
#[Fillable(['name', 'kind', 'default_points', 'description', 'sort_order'])]
class ConductType extends Model
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
            'kind' => ConductKind::class,
            'default_points' => 'integer',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<ConductEntry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(ConductEntry::class);
    }

    /**
     * @param  Builder<ConductType>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Merits first, then demerits, each by position and name.
     *
     * @param  Builder<ConductType>  $query
     */
    #[Scope]
    protected function ordered(Builder $query): void
    {
        $query->orderByRaw("case when kind = 'merit' then 0 else 1 end")->orderBy('sort_order')->orderBy('name');
    }
}
