<?php

namespace App\Models;

use Database\Factories\AcademicPeriodFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * At most one period is active; this is guaranteed by a unique index on the
 * generated `active_marker` column. Change it through AcademicPeriodService.
 *
 * The passing and warning grades decide academic standing in the period's
 * classes. They are not mass assignable; change them through
 * App\Services\Grading\GradingThresholdService.
 */
#[Fillable(['name', 'starts_on', 'ends_on'])]
class AcademicPeriod extends Model
{
    /** @use HasFactory<AcademicPeriodFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'is_active' => 'boolean',
            'passing_grade' => 'decimal:2',
            'warning_grade' => 'decimal:2',
        ];
    }

    /**
     * @return HasMany<ClassBatch, $this>
     */
    public function classBatches(): HasMany
    {
        return $this->hasMany(ClassBatch::class);
    }

    /**
     * @param  Builder<AcademicPeriod>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
