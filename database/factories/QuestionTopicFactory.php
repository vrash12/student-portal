<?php

namespace Database\Factories;

use App\Models\QuestionTopic;
use App\Models\Subject;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Generic placeholder topics only.
 *
 * @extends Factory<QuestionTopic>
 */
class QuestionTopicFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'subject_id' => Subject::factory(),
            'name' => 'Topic '.fake()->unique()->numberBetween(100, 99999),
        ];
    }

    public function forSubject(Subject $subject): static
    {
        return $this->state(fn (): array => ['subject_id' => $subject->id]);
    }
}
