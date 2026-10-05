<?php

namespace Tests\Unit;

use App\Enums\NutritionStatus;
use App\Models\NutritionStandards;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NutritionStandardsTest extends TestCase
{
    private function standards(): NutritionStandards
    {
        return (new NutritionStandards)->forceFill([
            'underweight_below' => 18.5,
            'overweight_from' => 23.0,
            'obese_from' => 27.5,
            'waist_to_height_risk' => 0.50,
            'review_interval_days' => 30,
        ]);
    }

    public function test_bmi_is_weight_over_height_squared_to_one_decimal(): void
    {
        $this->assertSame(22.9, NutritionStandards::bmi(170, 66.2));
        $this->assertSame(24.2, NutritionStandards::bmi(161.0, 62.8));
        $this->assertSame(0.47, NutritionStandards::waistToHeight(80, 170));
    }

    /**
     * @return array<string, array{float, NutritionStatus}>
     */
    public static function boundaries(): array
    {
        return [
            '18.4 underweight' => [18.4, NutritionStatus::Underweight],
            '18.5 normal' => [18.5, NutritionStatus::Normal],
            '22.9 normal' => [22.9, NutritionStatus::Normal],
            '23.0 overweight' => [23.0, NutritionStatus::Overweight],
            '27.4 overweight' => [27.4, NutritionStatus::Overweight],
            '27.5 obese' => [27.5, NutritionStatus::Obese],
        ];
    }

    #[DataProvider('boundaries')]
    public function test_the_asian_cut_offs_classify_each_boundary(float $bmi, NutritionStatus $expected): void
    {
        $this->assertSame($expected, $this->standards()->classify($bmi));
    }

    public function test_the_waist_is_at_risk_from_the_configured_ratio(): void
    {
        $this->assertFalse($this->standards()->waistAtRisk(0.49));
        $this->assertTrue($this->standards()->waistAtRisk(0.50));
    }
}
