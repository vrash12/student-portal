<?php

namespace Tests\Feature\Examinations\Delivery;

use App\Models\Candidate;
use App\Models\ClassSubject;
use App\Models\Examination;
use App\Models\ExaminationAttempt;
use App\Models\ExaminationQuestion;
use App\Models\Question;
use App\Models\QuestionChoice;
use App\Services\Examinations\CandidateAttemptService;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Teaching\BuildsTeachingFixtures;

/**
 * Candidate delivery fixtures on top of the teaching fixtures:
 * examinations belong to Batch A / Subject 1, taught by Instructor Alpha.
 * Time is frozen so deadlines are deterministic.
 */
trait BuildsDeliveryFixtures
{
    use BuildsTeachingFixtures;

    protected ClassSubject $offering;

    protected function buildDeliveryFixtures(): void
    {
        $this->travelTo(Carbon::parse('2026-10-05 09:00:00', config('institution.timezone')));
        $this->buildTeachingFixtures();
        $this->offering = ClassSubject::query()
            ->where('class_batch_id', $this->batchA->id)
            ->whereHas('subject', fn ($query) => $query->where('code', 'SUBJ-1'))
            ->firstOrFail();
    }

    /**
     * A published examination open now for 30 minutes of a one-hour window.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function makeExamination(array $attributes = []): Examination
    {
        $exam = new Examination;
        $exam->class_subject_id = $this->offering->id;
        $exam->created_by = $this->alpha->id;
        $exam->title = 'Synthetic delivery exam';
        $exam->status = 'published';
        $exam->duration_minutes = 30;
        $exam->opens_at = now()->subMinute();
        $exam->closes_at = now()->addHour();
        foreach ($attributes as $key => $value) {
            $exam->{$key} = $value;
        }
        $exam->save();

        return $exam->fresh();
    }

    protected function addItem(Examination $exam, Question $question, string $points = '1.00'): ExaminationQuestion
    {
        $item = new ExaminationQuestion;
        $item->examination_id = $exam->id;
        $item->question_id = $question->id;
        $item->position = (int) $exam->examinationQuestions()->max('position') + 1;
        $item->points = $points;
        $item->save();

        return $item;
    }

    protected function mcq(int $correctPosition = 1, int $choiceCount = 4): Question
    {
        return Question::factory()->multipleChoice($choiceCount, $correctPosition)->create(['subject_id' => $this->offering->subject_id]);
    }

    protected function trueFalse(bool $answer = true): Question
    {
        return Question::factory()->trueFalse($answer)->create(['subject_id' => $this->offering->subject_id]);
    }

    protected function essay(): Question
    {
        return Question::factory()->essay()->create(['subject_id' => $this->offering->subject_id]);
    }

    protected function startFor(Candidate $candidate, Examination $exam, ?string $code = null): ExaminationAttempt
    {
        return app(CandidateAttemptService::class)->start($candidate->user, $exam, $code);
    }

    /**
     * Save through HTTP with the attempt's current revision unless one is given.
     *
     * @param  array<string, mixed>  $extra
     */
    protected function saveAnswer(ExaminationAttempt $attempt, int $position, mixed $answer, array $extra = []): TestResponse
    {
        return $this->putJson('/portal/attempts/'.$attempt->id.'/answers', array_merge([
            'revision' => $attempt->fresh()->revision,
            'position' => $position,
            'answer' => $answer,
        ], $extra));
    }

    /**
     * @param  array<string, mixed>  $item  a delivery item
     */
    protected function correctChoiceId(array $item): int
    {
        return (int) QuestionChoice::query()->where('question_id', $item['question']['id'])->where('is_correct', true)->value('id');
    }

    /**
     * @param  array<string, mixed>  $item  a delivery item
     */
    protected function wrongChoiceId(array $item): int
    {
        return (int) QuestionChoice::query()->where('question_id', $item['question']['id'])->where('is_correct', false)->value('id');
    }

    /**
     * Delivery item for a given examination question, whatever its shuffled position.
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    protected function deliveryFor(ExaminationAttempt $attempt, ExaminationQuestion $item): array
    {
        foreach ($attempt->delivery as $position => $delivered) {
            if ($delivered['id'] === $item->id) {
                return [$position, $delivered];
            }
        }
        $this->fail('Examination question is not part of the attempt delivery.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function inertiaProps(TestResponse $response): array
    {
        $props = [];
        $response->assertInertia(function (Assert $page) use (&$props): void {
            $props = $page->toArray()['props'];
        });

        return $props;
    }
}
