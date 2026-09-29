<?php

namespace Database\Factories;

use App\Models\AcademicPeriod;
use App\Models\ClassBatch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClassBatch>
 */
class ClassBatchFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'academic_period_id' => AcademicPeriod::factory(),
            'name' => 'Sample Batch '.fake()->unique()->numberBetween(100, 999),
        ];
    }
}
