<?php

namespace Tests\Feature\QuestionBank;

use App\Enums\AuditAction;
use App\Enums\QuestionType;
use App\Models\AuditLog;
use App\Models\Question;
use App\Models\QuestionChoice;
use App\Services\QuestionBank\QuestionContent;
use App\Services\QuestionBank\QuestionLocking;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Question lifecycle: active / inactive (questions are never deleted) and
 * duplication, which is how a locked question gets a changed version.
 */
class QuestionLifecycleTest extends TestCase
{
    use BuildsQuestionBankFixtures;

    private Question $question;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildQuestionBankFixtures();

        $this->question = $this->createQuestion($this->subject1, $this->multipleChoice(), topic: 'Topic 1', points: '2.5', explanation: 'Staff notes.');
    }

    public function test_deactivating_keeps_the_question_out_of_new_examinations_and_is_audited_once(): void
    {
        $this->travel(10)->minutes();
        $updatedAt = $this->question->fresh()->updated_at;

        $this->actingAs($this->alpha)
            ->post("/question-bank/{$this->question->id}/deactivate")
            ->assertRedirect("/question-bank/{$this->question->id}")
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Question deactivated. It can no longer be added to examinations.']);

        $question = $this->question->fresh();
        $this->assertFalse($question->is_active);
        // updated_at and updated_by record content edits only.
        $this->assertEquals($updatedAt, $question->updated_at);
        $this->assertNull($question->updated_by);
        $this->assertSame(0, Question::query()->active()->count());

        // Deactivating again changes nothing and is not audited again.
        $this->actingAs($this->alpha)
            ->post("/question-bank/{$this->question->id}/deactivate")
            ->assertRedirect("/question-bank/{$this->question->id}")
            ->assertInertiaFlash('toast', ['type' => 'info', 'message' => 'This question is already inactive.']);

        $entry = AuditLog::query()->where('action', AuditAction::QuestionDeactivated->value)->sole();
        $this->assertSame($this->alpha->id, $entry->actor_id);
        $this->assertSame('question', $entry->auditable_type);
        $this->assertSame($this->question->id, (int) $entry->auditable_id);
        $this->assertSame(['is_active' => true], $entry->old_values);
        $this->assertSame(['is_active' => false], $entry->new_values);
    }

    public function test_activating_makes_the_question_available_again_and_is_audited_once(): void
    {
        $this->service()->deactivate($this->question, $this->alpha);

        foreach ([['success', 'Question activated. It can be added to examinations again.'], ['info', 'This question is already active.']] as [$type, $message]) {
            $this->actingAs($this->bravo)
                ->post("/question-bank/{$this->question->id}/activate")
                ->assertRedirect("/question-bank/{$this->question->id}")
                ->assertInertiaFlash('toast', ['type' => $type, 'message' => $message]);
        }

        $this->assertTrue($this->question->fresh()->is_active);
        $entry = AuditLog::query()->where('action', AuditAction::QuestionActivated->value)->sole();
        $this->assertSame($this->bravo->id, $entry->actor_id);
        $this->assertSame(['is_active' => false], $entry->old_values);
        $this->assertSame(['is_active' => true], $entry->new_values);
    }

    public function test_locked_questions_can_be_deactivated_and_activated(): void
    {
        $this->lock($this->question);

        $this->actingAs($this->alpha)->post("/question-bank/{$this->question->id}/deactivate")->assertRedirect();
        $this->assertFalse($this->question->fresh()->is_active);
        $this->assertNotNull($this->question->fresh()->locked_at);

        $this->actingAs($this->alpha)->post("/question-bank/{$this->question->id}/activate")->assertRedirect();
        $this->assertTrue($this->question->fresh()->is_active);
    }

    public function test_status_changes_lock_the_question_row_first(): void
    {
        foreach (['deactivate', 'activate'] as $action) {
            DB::flushQueryLog();
            DB::enableQueryLog();

            $this->actingAs($this->alpha)->post("/question-bank/{$this->question->id}/{$action}")->assertRedirect();

            $queries = array_column(DB::getQueryLog(), 'query');
            DB::disableQueryLog();

            $lock = array_key_first(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'from `questions`') && str_ends_with($sql, 'for update')));
            $update = array_key_first(array_filter($queries, fn (string $sql): bool => str_starts_with($sql, 'update `questions`')));

            $this->assertNotNull($lock, "{$action}: the question row must be locked.");
            $this->assertNotNull($update);
            $this->assertLessThan($update, $lock);
        }
    }

    public function test_inactive_questions_stay_in_the_question_bank(): void
    {
        $this->service()->deactivate($this->question, $this->alpha);

        $this->actingAs($this->alpha)
            ->get('/question-bank')
            ->assertInertia(fn (Assert $page) => $page->where('questions.total', 1)->where('questions.data.0.isActive', false));

        $this->actingAs($this->alpha)
            ->get("/question-bank/{$this->question->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('question.isActive', false));
    }

    public function test_duplicating_creates_an_active_unlocked_copy_and_opens_it_for_editing(): void
    {
        $this->lock($this->question);
        $this->service()->deactivate($this->question, $this->alpha);
        $original = $this->question->fresh(['choices']);

        $response = $this->actingAs($this->bravo)->post("/question-bank/{$this->question->id}/duplicate");

        $copy = Question::query()->with(['choices', 'topic'])->whereKeyNot($this->question->id)->sole();
        $response->assertRedirect("/question-bank/{$copy->id}/edit")
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Copy created. You are editing the copy; the original question is unchanged.']);

        $this->assertSame($this->subject1->id, $copy->subject_id);
        $this->assertSame($original->question_topic_id, $copy->question_topic_id);
        $this->assertSame(QuestionType::MultipleChoice, $copy->type);
        $this->assertSame($original->prompt, $copy->prompt);
        $this->assertSame('2.50', $copy->points);
        $this->assertSame('Staff notes.', $copy->explanation);
        $this->assertTrue($copy->is_active);
        $this->assertNull($copy->locked_at);
        $this->assertSame($this->bravo->id, $copy->created_by);
        $this->assertNull($copy->updated_by);
        $this->assertSame(
            $original->choices->map(fn (QuestionChoice $choice): array => [$choice->position, $choice->text, $choice->is_correct])->all(),
            $copy->choices->map(fn (QuestionChoice $choice): array => [$choice->position, $choice->text, $choice->is_correct])->all(),
        );
        $this->assertSame([], array_intersect($original->choices->pluck('id')->all(), $copy->choices->pluck('id')->all()));

        // The original is untouched.
        $this->assertEquals($original->toArray(), $this->question->fresh(['choices'])->toArray());
        $this->assertNotNull($this->question->fresh()->locked_at);

        $entry = AuditLog::query()->where('action', AuditAction::QuestionCreated->value)->where('auditable_id', $copy->id)->sole();
        $this->assertSame($this->bravo->id, $entry->actor_id);
        $this->assertSame([
            'subject_id' => $this->subject1->id,
            'topic' => 'Topic 1',
            'type' => 'multiple_choice',
            'points' => '2.50',
            'is_active' => true,
            'choice_count' => 4,
            'duplicated_from' => $this->question->id,
        ], $entry->new_values);

        // The copy can be changed freely.
        $this->actingAs($this->bravo)
            ->get("/question-bank/{$copy->id}/edit")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('question.isLocked', false));
    }

    public function test_duplicating_true_false_and_essay_questions_keeps_their_answers(): void
    {
        $trueFalse = $this->createQuestion($this->subject1, QuestionContent::trueFalse('The sample statement is false.', false));
        $essay = $this->createQuestion($this->subject1, QuestionContent::essay('Explain the sample.'), points: '10');

        $copyOf = function (Question $question): Question {
            $this->actingAs($this->alpha)->post("/question-bank/{$question->id}/duplicate")->assertRedirect();

            return Question::query()->with('choices')->latest('id')->firstOrFail();
        };

        $trueFalseCopy = $copyOf($trueFalse);
        $this->assertSame(QuestionType::TrueFalse, $trueFalseCopy->type);
        $this->assertSame([['True', false], ['False', true]], $trueFalseCopy->choices->map(fn (QuestionChoice $choice): array => [$choice->text, $choice->is_correct])->all());

        $essayCopy = $copyOf($essay);
        $this->assertSame(QuestionType::Essay, $essayCopy->type);
        $this->assertSame('10.00', $essayCopy->points);
        $this->assertCount(0, $essayCopy->choices);
    }

    private function lock(Question $question): void
    {
        DB::transaction(function () use ($question): void {
            $locking = $this->app->make(QuestionLocking::class);
            $locking->lockRowsForPublication([$question->id]);
            $locking->lock([$question->id]);
        });
    }
}
