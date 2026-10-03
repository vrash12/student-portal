<?php

namespace Database\Factories;

use App\Models\Campus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Campus>
 */
class CampusFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $number = fake()->unique()->numberBetween(100, 999);

        return [
            'name' => "Campus {$number}",
            'code' => "C{$number}",
            'address' => null,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    /**
     * The campus records are attached to when a test does not choose one:
     * the first campus, created as "Main Campus" when there is none. Using
     * one shared campus keeps instructors and the classes they teach on the
     * same campus (a database rule).
     */
    public static function defaultCampusId(): int
    {
        $id = Campus::query()->orderBy('id')->value('id');

        return $id !== null ? (int) $id : Campus::factory()->create(['name' => 'Main Campus', 'code' => 'MAIN'])->id;
    }
}
