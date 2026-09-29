<?php

namespace Database\Factories;

use App\Models\AcademicPeriod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AcademicPeriod>
 */
class AcademicPeriodFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Academic Period '.fake()->unique()->numberBetween(1000, 9999),
            'starts_on' => '2026-08-03',
            'ends_on' => '2026-12-18',
            'is_active' => false,
        ];
    }

    /**
     * Only one period may be active at a time (enforced by the database).
     */
    public function active(): static
    {
        return $this->state(fn (): array => ['is_active' => true]);
    }
}
