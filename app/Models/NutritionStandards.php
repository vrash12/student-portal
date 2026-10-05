<?php

namespace App\Models;

use App\Enums\NutritionStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The cut-offs that classify a nutrition assessment (one row, set by
 * administrators with nutrition.configure). Shared by every campus.
 * Defaults: WHO Asia-Pacific BMI cut-offs adopted by the Department of
 * Health, and a waist-to-height ratio of 0.50.
 *
 * @property string $underweight_below
 * @property string $overweight_from
 * @property string $obese_from
 * @property string $waist_to_height_risk
 * @property int $review_interval_days
 */
#[Fillable(['underweight_below', 'overweight_from', 'obese_from', 'waist_to_height_risk', 'review_interval_days'])]
class NutritionStandards extends Model
{
    protected $table = 'nutrition_standards';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'underweight_below' => 'decimal:1',
            'overweight_from' => 'decimal:1',
            'obese_from' => 'decimal:1',
            'waist_to_height_risk' => 'decimal:2',
            'review_interval_days' => 'integer',
        ];
    }

    /** The standards in force (the migration inserts the single row). */
    public static function current(): self
    {
        return self::query()->orderBy('id')->firstOrFail();
    }

    /** Body mass index: weight (kg) ÷ height (m)², to one decimal. */
    public static function bmi(float $heightCm, float $weightKg): float
    {
        $heightM = $heightCm / 100;

        return round($weightKg / ($heightM * $heightM), 1);
    }

    /** Waist ÷ height, to two decimals. */
    public static function waistToHeight(float $waistCm, float $heightCm): float
    {
        return round($waistCm / $heightCm, 2);
    }

    public function classify(float $bmi): NutritionStatus
    {
        return match (true) {
            $bmi < (float) $this->underweight_below => NutritionStatus::Underweight,
            $bmi < (float) $this->overweight_from => NutritionStatus::Normal,
            $bmi < (float) $this->obese_from => NutritionStatus::Overweight,
            default => NutritionStatus::Obese,
        };
    }

    public function waistAtRisk(float $waistToHeight): bool
    {
        return $waistToHeight >= (float) $this->waist_to_height_risk;
    }

    /**
     * @return array{underweightBelow: float, overweightFrom: float, obeseFrom: float, waistToHeightRisk: float, reviewIntervalDays: int}
     */
    public function toSummary(): array
    {
        return [
            'underweightBelow' => (float) $this->underweight_below,
            'overweightFrom' => (float) $this->overweight_from,
            'obeseFrom' => (float) $this->obese_from,
            'waistToHeightRisk' => (float) $this->waist_to_height_risk,
            'reviewIntervalDays' => $this->review_interval_days,
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
