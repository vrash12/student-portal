<?php

namespace Tests\Feature\Examinations\Builder;

use App\Models\AuditLog;
use App\Models\Examination;
use App\Services\Examinations\ExaminationService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * The "Questions per attempt" setting (random question subsets).
 */
class QuestionSubsetSettingsTest extends TestCase
{
    use BuildsExaminationBuilderFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildExaminationBuilderFixtures();
    }

    private function publish(Examination $exam): void
    {
        $this->app->make(ExaminationService::class)->publish($this->alpha, $exam);
    }

    public function test_the_setting_is_saved_validated_and_cleared(): void
    {
        $this->actingAs($this->alpha)->post('/examinations', $this->examPayload(['question_draw_count' => '20']))->assertSessionHasNoErrors();
        $exam = Examination::query()->latest('id')->firstOrFail();
        $this->assertSame(20, $exam->question_draw_count);

        foreach (['0', '501', '2.5', 'many'] as $invalid) {
            $this->actingAs($this->alpha)->put('/examinations/'.$exam->id, $this->updatePayload(['question_draw_count' => $invalid]))->assertSessionHasErrors('question_draw_count');
        }

        $this->actingAs($this->alpha)->put('/examinations/'.$exam->id, $this->updatePayload(['question_draw_count' => '']))->assertSessionHasNoErrors();
        $this->assertNull($exam->fresh()->question_draw_count);

        $audit = AuditLog::query()->where('auditable_id', $exam->id)->where('action', 'examination.settings_updated')->latest('id')->firstOrFail();
        $this->assertSame(20, $audit->old_values['question_draw_count']);
        $this->assertNull($audit->new_values['question_draw_count']);
    }

    public function test_publishing_rejects_more_questions_per_attempt_than_selected(): void
    {
        $exam = $this->readyDraft(['question_draw_count' => 3], 2);

        try {
            $this->publish($exam);
            $this->fail('Publication should be rejected.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('more than the 2 selected questions', $exception->validator->errors()->first('question_draw_count'));
        }
        $this->assertSame('draft', $exam->fresh()->status->value);
    }

    public function test_a_subset_needs_equal_points_but_delivering_every_question_does_not(): void
    {
        $exam = $this->draft(['question_draw_count' => 2]);
        $questions = [$this->mcq(), $this->mcq(), $this->mcq()];
        $this->app->make(ExaminationService::class)->syncQuestions($this->alpha, $exam, [
            ['question_id' => $questions[0]->id, 'points' => '1'],
            ['question_id' => $questions[1]->id, 'points' => '1'],
            ['question_id' => $questions[2]->id, 'points' => '2'],
        ]);

        try {
            $this->publish($exam->fresh());
            $this->fail('Publication should be rejected.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('same points', $exception->validator->errors()->first('question_draw_count'));
        }

        // Drawing all three: unequal points are fine.
        $this->app->make(ExaminationService::class)->update($this->alpha, $exam, ['question_draw_count' => 3]);
        $this->publish($exam->fresh());
        $this->assertSame('published', $exam->fresh()->status->value);
    }

    public function test_publishing_a_subset_records_questions_per_attempt(): void
    {
        $exam = $this->readyDraft(['question_draw_count' => 2], 4);
        $this->publish($exam);

        $audit = AuditLog::query()->where('auditable_id', $exam->id)->where('action', 'examination.published')->sole();
        $this->assertSame(4, $audit->new_values['question_count']);
        $this->assertSame(2, $audit->new_values['questions_per_attempt']);
        $this->assertSame('2.00', $exam->fresh()->attemptMaximumPoints());
    }

    public function test_the_review_page_describes_the_subset(): void
    {
        $exam = $this->readyDraft(['question_draw_count' => 2], 4);

        $this->actingAs($this->alpha)->get('/examinations/'.$exam->id)->assertOk()
            ->assertInertia(fn ($page) => $page->where('examination.question_draw_count', 2)->has('examination.examination_questions', 4));
        $this->actingAs($this->alpha)->get('/examinations/'.$exam->id.'/edit')->assertOk()
            ->assertInertia(fn ($page) => $page->where('examination.question_draw_count', 2));
    }

    public function test_the_database_rejects_invalid_counts(): void
    {
        $exam = $this->draft();

        foreach ([0, 501] as $invalid) {
            try {
                DB::table('examinations')->where('id', $exam->id)->update(['question_draw_count' => $invalid]);
                $this->fail("{$invalid} should be rejected by the CHECK constraint.");
            } catch (QueryException) {
            }
        }
        $this->assertNull($exam->fresh()->question_draw_count);
    }
}
