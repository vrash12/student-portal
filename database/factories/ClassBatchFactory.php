<?php

namespace Database\Factories;

use App\Models\AcademicPeriod;
use App\Models\Campus;
use App\Models\ClassBatch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClassBatch>
 */
class ClassBatchFactory extends Factory
{
    /**
     * Classes go to the shared default campus unless a test chooses one.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'academic_period_id' => AcademicPeriod::factory(),
            'campus_id' => fn (): int => CampusFactory::defaultCampusId(),
            'name' => 'Sample Batch '.fake()->unique()->numberBetween(100, 999),
        ];
    }

    public function onCampus(Campus $campus): static
    {
        return $this->state(fn (): array => ['campus_id' => $campus->id]);
    }
}
