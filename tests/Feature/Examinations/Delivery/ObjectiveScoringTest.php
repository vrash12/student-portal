<?php

namespace Tests\Feature\Examinations\Delivery;

use App\Models\Examination;
use App\Models\ExaminationAttempt;
use App\Models\ExaminationQuestion;
use App\Models\QuestionChoice;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Objective scoring on submission, essay-pending results, result release
 * and confidentiality of candidate payloads (Milestones 11 and 13).
 */
class ObjectiveScoringTest extends TestCase
{
    use BuildsDeliveryFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildDeliveryFixtures();
    }

    /**
     * Submit resends the current question's answer, as the tablet does.
     */
    private function submit(ExaminationAttempt $attempt): void
    {
        $current = $attempt->fresh();
        $itemId = $current->delivery[$current->current_position]['id'];
        $this->post('/portal/attempts/'.$attempt->id.'/submit', [
            'revision' => $current->revision,
            'position' => $current->current_position,
            'answer' => $current->answers[$itemId]['value'] ?? null,
        ])
            ->assertRedirect('/portal/attempts/'.$attempt->id.'/success');
    }

    public function test_mixed_objective_items_use_examination_points_and_score_unanswered_as_zero(): void
    {
        $exam = $this->makeExamination(['passing_score' => '75.00']);
        $correctMcq = $this->addItem($exam, $this->mcq(2), '2.50');
        $trueFalse = $this->addItem($exam, $this->trueFalse(false), '1.25');
        $unanswered = $this->addItem($exam, $this->mcq(3), '0.10');
        $wrong = $this->addItem($exam, $this->mcq(1), '0.20');
        $attempt = $this->startFor($this->candidateInA, $exam);
        $this->actingAs($this->candidateInA->user);
        foreach ([[$correctMcq, true], [$trueFalse, true], [$wrong, false]] as [$item, $correct]) {
            [$position, $delivered] = $this->deliveryFor($attempt, $item);
            $this->saveAnswer($attempt, $position, $correct ? $this->correctChoiceId($delivered) : $this->wrongChoiceId($delivered))->assertOk();
        }
        $this->submit($attempt);
        $attempt->refresh();
        $this->assertSame('graded', $attempt->result_status);
        $this->assertSame('3.75', $attempt->objective_points);
        $this->assertSame('4.05', $attempt->objective_max_points);
        $this->assertSame('4.05', $attempt->total_points);
        $this->assertSame('3.75', $attempt->earned_points);
        $this->assertSame('92.59', $attempt->percentage);
        $this->assertTrue($attempt->passed);
        $this->assertEquals(['points' => 2.5, 'max_points' => 2.5, 'status' => 'graded'], $attempt->item_scores[$correctMcq->id]);
        $this->assertEquals(['points' => 1.25, 'max_points' => 1.25, 'status' => 'graded'], $attempt->item_scores[$trueFalse->id]);
        $this->assertEquals(['points' => 0, 'max_points' => 0.1, 'status' => 'graded'], $attempt->item_scores[$unanswered->id]);
        $this->assertEquals(['points' => 0, 'max_points' => 0.2, 'status' => 'graded'], $attempt->item_scores[$wrong->id]);
    }

    public function test_true_false_answers_are_scored_against_the_stored_answer(): void
    {
        $exam = $this->makeExamination();
        $true = $this->addItem($exam, $this->trueFalse(true));
        $false = $this->addItem($exam, $this->trueFalse(false));
        $attempt = $this->startFor($this->candidateInA, $exam);
        $this->actingAs($this->candidateInA->user);
        foreach ([$true, $false] as $item) {
            [$position, $delivered] = $this->deliveryFor($attempt, $item);
            // Choose "True" for both: correct for the first, wrong for the second.
            $this->saveAnswer($attempt, $position, $delivered['question']['choices'][0]['id'])->assertOk();
        }
        $this->submit($attempt);
        $attempt->refresh();
        $this->assertSame('1.00', $attempt->objective_points);
        $this->assertSame('50.00', $attempt->percentage);
    }

    public function test_point_sums_use_integer_hundredths(): void
    {
        $exam = $this->makeExamination();
        $items = [];
        for ($i = 0; $i < 10; $i++) {
            $items[] = $this->addItem($exam, $this->mcq(), '0.10');
        }
        $attempt = $this->startFor($this->candidateInA, $exam);
        $this->actingAs($this->candidateInA->user);
        foreach ($items as $item) {
            [$position, $delivered] = $this->deliveryFor($attempt, $item);
            $this->saveAnswer($attempt, $position, $this->correctChoiceId($delivered))->assertOk();
        }
        $this->submit($attempt);
        $attempt->refresh();
        $this->assertSame('1.00', $attempt->objective_points);
        $this->assertSame('1.00', $attempt->earned_points);
        $this->assertSame('100.00', $attempt->percentage);
    }

    public function test_percentage_is_rounded_to_two_decimals_and_compared_with_the_passing_score(): void
    {
        $exam = $this->makeExamination(['passing_score' => '33.34']);
        $items = [$this->addItem($exam, $this->mcq()), $this->addItem($exam, $this->mcq()), $this->addItem($exam, $this->mcq())];
        $attempt = $this->startFor($this->candidateInA, $exam);
        $this->actingAs($this->candidateInA->user);
        [$position, $delivered] = $this->deliveryFor($attempt, $items[0]);
        $this->saveAnswer($attempt, $position, $this->correctChoiceId($delivered))->assertOk();
        $this->submit($attempt);
        $attempt->refresh();
        $this->assertSame('33.33', $attempt->percentage);
        $this->assertFalse($attempt->passed);
    }

    public function test_blank_submission_scores_zero_and_fails(): void
    {
        $exam = $this->makeExamination(['passing_score' => '50.00']);
        $this->addItem($exam, $this->mcq());
        $attempt = $this->startFor($this->candidateInA, $exam);
        $this->actingAs($this->candidateInA->user);
        $this->submit($attempt);
        $attempt->refresh();
        $this->assertSame('0.00', $attempt->objective_points);
        $this->assertSame('0.00', $attempt->percentage);
        $this->assertFalse($attempt->passed);
    }

    public function test_scoring_uses_the_key_and_passing_score_snapshotted_at_start(): void
    {
        $exam = $this->makeExamination(['passing_score' => '50.00']);
        $item = $this->addItem($exam, $this->mcq(1));
        $attempt = $this->startFor($this->candidateInA, $exam);
        [$position, $delivered] = $this->deliveryFor($attempt, $item);
        $originalCorrect = $this->correctChoiceId($delivered);
        // Later edits to the bank or settings must not change this attempt's result.
        QuestionChoice::query()->where('question_id', $delivered['question']['id'])->update(['is_correct' => false]);
        QuestionChoice::query()->where('question_id', $delivered['question']['id'])->where('id', '!=', $originalCorrect)->limit(1)->update(['is_correct' => true]);
        $exam->passing_score = '100.00';
        $exam->save();
        $this->actingAs($this->candidateInA->user);
        $this->saveAnswer($attempt, $position, $originalCorrect)->assertOk();
        $this->submit($attempt);
        $attempt->refresh();
        $this->assertSame('100.00', $attempt->percentage);
        $this->assertSame('50.00', $attempt->passing_score);
        $this->assertTrue($attempt->passed);
    }

    public function test_objective_question_without_a_single_correct_answer_cannot_be_started(): void
    {
        $exam = $this->makeExamination();
        $question = $this->mcq();
        $question->choices()->update(['is_correct' => false]);
        $this->addItem($exam, $question);
        $this->actingAs($this->candidateInA->user)->post('/portal/examinations/'.$exam->id.'/start')->assertSessionHasErrors('examination');
        $this->assertSame(0, ExaminationAttempt::count());
    }

    public function test_essays_leave_the_result_pending_while_objective_points_are_recorded(): void
    {
        $exam = $this->makeExamination(['passing_score' => '50.00', 'release_results' => true]);
        $mcq = $this->addItem($exam, $this->mcq(), '1.00');
        $essay = $this->addItem($exam, $this->essay(), '3.00');
        $attempt = $this->startFor($this->candidateInA, $exam);
        $this->actingAs($this->candidateInA->user);
        [$position, $delivered] = $this->deliveryFor($attempt, $mcq);
        $this->saveAnswer($attempt, $position, $this->correctChoiceId($delivered))->assertOk();
        [$essayPosition] = $this->deliveryFor($attempt, $essay);
        $this->saveAnswer($attempt, $essayPosition, 'Synthetic essay answer')->assertOk();
        $this->submit($attempt);
        $attempt->refresh();
        $this->assertSame('pending_review', $attempt->result_status);
        $this->assertSame('1.00', $attempt->objective_points);
        $this->assertSame('1.00', $attempt->objective_max_points);
        $this->assertSame('4.00', $attempt->total_points);
        $this->assertNull($attempt->earned_points);
        $this->assertNull($attempt->percentage);
        $this->assertNull($attempt->passed);
        $this->assertEquals(['points' => null, 'max_points' => 3, 'status' => 'pending_review'], $attempt->item_scores[$essay->id]);
        $this->get('/portal/attempts/'.$attempt->id.'/success')->assertInertia(fn (Assert $page) => $page
            ->where('resultStatus', 'pending_review')
            ->where('objectivePoints', '1.00')
            ->where('percentage', null)
            ->where('passed', null));
    }

    public function test_unreleased_results_are_hidden_from_the_candidate_until_release(): void
    {
        $exam = $this->makeExamination(['passing_score' => '50.00']);
        $item = $this->addItem($exam, $this->mcq());
        $attempt = $this->startFor($this->candidateInA, $exam);
        $this->actingAs($this->candidateInA->user);
        [$position, $delivered] = $this->deliveryFor($attempt, $item);
        $this->saveAnswer($attempt, $position, $this->correctChoiceId($delivered))->assertOk();
        $this->submit($attempt);
        $this->assertSame('100.00', $attempt->fresh()->percentage);

        $this->get('/portal/attempts/'.$attempt->id.'/success')->assertInertia(fn (Assert $page) => $page
            ->where('releaseResults', false)
            ->where('objectivePoints', null)
            ->where('objectiveMaxPoints', null)
            ->where('percentage', null)
            ->where('passed', null));
        $this->get('/portal/examinations/'.$exam->id)->assertInertia(fn (Assert $page) => $page
            ->where('examination.attempts.0.status', 'submitted')
            ->where('examination.attempts.0.percentage', null));

        $exam->release_results = true;
        $exam->save();
        $this->get('/portal/attempts/'.$attempt->id.'/success')->assertInertia(fn (Assert $page) => $page
            ->where('objectivePoints', '1.00')
            ->where('objectiveMaxPoints', '1.00')
            ->where('percentage', '100.00')
            ->where('passed', true));
        $this->get('/portal/examinations/'.$exam->id)->assertInertia(fn (Assert $page) => $page
            ->where('examination.attempts.0.percentage', '100.00'));
    }

    public function test_candidate_payloads_never_contain_keys_explanations_or_access_codes(): void
    {
        $exam = $this->makeExamination(['access_code' => 'SENTINEL-CODE-913', 'release_results' => true]);
        $question = $this->mcq();
        $question->explanation = 'SENTINEL-EXPLANATION-913';
        $question->save();
        $item = $this->addItem($exam, $question);
        $trueFalse = $this->trueFalse();
        $trueFalse->explanation = 'SENTINEL-EXPLANATION-913';
        $trueFalse->save();
        $this->addItem($exam, $trueFalse);
        $this->actingAs($this->candidateInA->user);
        $payloads = [];
        $payloads[] = $this->inertiaProps($this->get('/portal/examinations/'.$exam->id)->assertOk());
        $this->post('/portal/examinations/'.$exam->id.'/start', ['access_code' => 'SENTINEL-CODE-913'])->assertRedirect();
        $attempt = ExaminationAttempt::sole();
        $payloads[] = $this->inertiaProps($this->get('/portal/attempts/'.$attempt->id)->assertOk());
        [$position, $delivered] = $this->deliveryFor($attempt, $item);
        $payloads[] = $this->saveAnswer($attempt, $position, $this->correctChoiceId($delivered))->assertOk()->json();
        $payloads[] = $this->postJson('/portal/attempts/'.$attempt->id.'/activity')->assertOk()->json();
        $this->submit($attempt);
        $payloads[] = $this->inertiaProps($this->get('/portal/attempts/'.$attempt->id.'/success')->assertOk());
        $payloads[] = $this->inertiaProps($this->get('/portal/examinations/'.$exam->id)->assertOk());
        foreach ($payloads as $payload) {
            $encoded = json_encode($payload);
            foreach (['isCorrect', 'is_correct', 'correct_choice_id', 'scoring_key', 'item_scores', 'explanation', 'access_code', 'SENTINEL-CODE-913', 'SENTINEL-EXPLANATION-913'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $encoded);
            }
        }
    }

    public function test_one_candidates_results_are_not_visible_to_another(): void
    {
        $exam = $this->makeExamination(['release_results' => true]);
        $this->addItem($exam, $this->mcq());
        $attempt = $this->startFor($this->candidateInA, $exam);
        $this->actingAs($this->candidateInA->user);
        $this->submit($attempt);
        $classmate = $this->batchA->candidates()->where('candidates.id', '!=', $this->candidateInA->id)->where('status', 'enrolled')->firstOrFail();
        $this->actingAs($classmate->user);
        $this->get('/portal/attempts/'.$attempt->id.'/success')->assertForbidden();
        $this->get('/portal/examinations/'.$exam->id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('examination.attemptsUsed', 0)
            ->has('examination.attempts', 0));
    }

    /**
     * Guard against helper misuse: every examination question in this file
     * belongs to the examination that was started.
     */
    public function test_delivery_contains_every_examination_question_once(): void
    {
        $exam = $this->makeExamination(['randomize_questions' => true]);
        $ids = collect([$this->mcq(), $this->trueFalse(), $this->essay()])->map(fn ($question) => $this->addItem($exam, $question)->id)->all();
        $attempt = $this->startFor($this->candidateInA, $exam);
        $this->assertEqualsCanonicalizing($ids, array_column($attempt->delivery, 'id'));
        $this->assertEqualsCanonicalizing($ids, array_map('intval', array_keys($attempt->scoring_key)));
        $this->assertSame(3, ExaminationQuestion::where('examination_id', $exam->id)->count());
        $this->assertInstanceOf(Examination::class, $attempt->examination);
    }
}
