<?php

namespace Database\Factories;

use App\Enums\QuestionType;
use App\Enums\SystemRole;
use App\Models\Question;
use App\Models\QuestionTopic;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Valid questions for tests. The default is an essay (no choices); use
 * multipleChoice() or trueFalse() for objective questions, which also
 * creates their choices. Generic placeholder text only.
 *
 * @extends Factory<Question>
 */
class QuestionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'subject_id' => Subject::factory(),
            'question_topic_id' => null,
            'type' => QuestionType::Essay,
            'prompt' => 'Sample question '.fake()->unique()->numberBetween(1000, 99999).'.',
            'points' => '1.00',
            'explanation' => null,
            'is_active' => true,
            'locked_at' => null,
            'created_by' => User::factory()->withRole(SystemRole::Instructor),
            'updated_by' => null,
        ];
    }

    public function forSubject(Subject $subject): static
    {
        return $this->state(fn (): array => ['subject_id' => $subject->id]);
    }

    /**
     * The topic also decides the subject.
     */
    public function withTopic(QuestionTopic $topic): static
    {
        return $this->state(fn (): array => ['subject_id' => $topic->subject_id, 'question_topic_id' => $topic->id]);
    }

    public function createdBy(User $user): static
    {
        return $this->state(fn (): array => ['created_by' => $user->id]);
    }

    /**
     * Choices "Option A", "Option B", ...; $correctPosition is 1-based.
     */
    public function multipleChoice(int $choiceCount = 4, int $correctPosition = 1): static
    {
        return $this
            ->state(fn (): array => ['type' => QuestionType::MultipleChoice])
            ->afterCreating(function (Question $question) use ($choiceCount, $correctPosition): void {
                for ($position = 1; $position <= $choiceCount; $position++) {
                    $question->choices()->create([
                        'position' => $position,
                        'text' => 'Option '.chr(ord('A') + $position - 1),
                        'is_correct' => $position === $correctPosition,
                    ]);
                }
            });
    }

    public function trueFalse(bool $answer = true): static
    {
        return $this
            ->state(fn (): array => ['type' => QuestionType::TrueFalse])
            ->afterCreating(function (Question $question) use ($answer): void {
                foreach (QuestionType::TRUE_FALSE_CHOICES as $index => $text) {
                    $question->choices()->create([
                        'position' => $index + 1,
                        'text' => $text,
                        'is_correct' => ($index === 0) === $answer,
                    ]);
                }
            });
    }

    public function essay(): static
    {
        return $this->state(fn (): array => ['type' => QuestionType::Essay]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    /**
     * As if the question had been part of a published examination.
     */
    public function locked(): static
    {
        return $this->state(fn (): array => ['locked_at' => now()]);
    }
}
