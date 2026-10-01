<?php

namespace App\Models;

use App\Enums\FitnessUnit;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A configurable fitness event (e.g. push-ups, a timed run) with its passing
 * and maximum standards. Events that have been used in a test are
 * deactivated, never deleted. Change through FitnessStandardService.
 */
#[Fillable(['name', 'description', 'unit', 'higher_is_better', 'passing_value', 'maximum_value', 'sort_order'])]
class FitnessEvent extends Model
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
            'unit' => FitnessUnit::class,
            'higher_is_better' => 'boolean',
            'passing_value' => 'decimal:2',
            'maximum_value' => 'decimal:2',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<FitnessTestEvent, $this>
     */
    public function testEvents(): HasMany
    {
        return $this->hasMany(FitnessTestEvent::class);
    }

    /**
     * @param  Builder<FitnessEvent>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * @param  Builder<FitnessEvent>  $query
     */
    #[Scope]
    protected function ordered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('name');
    }
}
