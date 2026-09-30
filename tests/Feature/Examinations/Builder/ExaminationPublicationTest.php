<?php

namespace Tests\Feature\Examinations\Builder;

use App\Models\AuditLog;
use App\Models\ClassSubject;
use App\Models\Examination;
use App\Models\ExaminationQuestion;
use App\Models\Question;
use App\Models\QuestionChoice;
use App\Services\ClassBatchService;
use App\Services\Examinations\ExaminationService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ExaminationPublicationTest extends TestCase
{
    use BuildsExaminationBuilderFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-01 08:00:00', 'UTC'));
        $this->buildExaminationBuilderFixtures();
    }

    /**
     * @return list<array{question_id: int, position: int, points: string}>
     */
    private function questionRows(Examination $exam): array
    {
        return ExaminationQuestion::where('examination_id', $exam->id)->orderBy('position')->get(['question_id', 'position', 'points'])->toArray();
    }

    public function test_publishing_marks_the_exam_published_and_locks_its_questions(): void
    {
        $exam = $this->readyDraft();
        $questionIds = ExaminationQuestion::where('examination_id', $exam->id)->pluck('question_id');
        $unused = $this->mcq();

        $this->actingAs($this->alpha)->post("/examinations/{$exam->id}/publish")->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('published', $exam->fresh()->status->value);
        foreach (Question::whereIn('id', $questionIds)->get() as $question) {
            $this->assertEquals(now(), $question->locked_at);
        }
        $this->assertNull($unused->fresh()->locked_at);
    }

    public function test_existing_lock_time_is_kept_when_a_question_is_published_again(): void
    {
        $first = $this->publishedExam();
        $sharedId = ExaminationQuestion::where('examination_id', $first->id)->value('question_id');
        $lockedAt = Question::find($sharedId)->locked_at;

        $this->travel(2)->days();
        $second = $this->draft(['title' => 'Reuse']);
        $this->app->make(ExaminationService::class)->syncQuestions($this->alpha, $second, [['question_id' => $sharedId, 'points' => '1']]);
        $this->actingAs($this->alpha)->post("/examinations/{$second->id}/publish")->assertSessionHasNoErrors();

        $this->assertEquals($lockedAt, Question::find($sharedId)->locked_at);
    }

    public function test_publishing_requires_a_duration(): void
    {
        $exam = $this->readyDraft(['duration_minutes' => null]);
        $this->actingAs($this->alpha)->post("/examinations/{$exam->id}/publish")->assertSessionHasErrors('examination');
        $this->assertSame('draft', $exam->fresh()->status->value);
    }

    public function test_publishing_requires_at_least_one_question(): void
    {
        $exam = $this->draft();
        $this->actingAs($this->alpha)->post("/examinations/{$exam->id}/publish")->assertSessionHasErrors('examination');
        $this->assertSame('draft', $exam->fresh()->status->value);
    }

    public function test_publishing_rejects_a_closing_time_in_the_past(): void
    {
        $exam = $this->readyDraft(['closes_at' => '2026-09-30 08:00:00']);
        $this->actingAs($this->alpha)->post("/examinations/{$exam->id}/publish")->assertSessionHasErrors('examination');
        $this->assertSame('draft', $exam->fresh()->status->value);
    }

    public function test_publishing_rejects_a_question_deactivated_after_selection_and_locks_nothing(): void
    {
        $exam = $this->readyDraft();
        $ids = ExaminationQuestion::where('examination_id', $exam->id)->pluck('question_id');
        Question::whereKey($ids->first())->update(['is_active' => false]);

        $this->actingAs($this->alpha)->post("/examinations/{$exam->id}/publish")->assertSessionHasErrors('questions');

        $this->assertSame('draft', $exam->fresh()->status->value);
        $this->assertSame(0, Question::whereIn('id', $ids)->whereNotNull('locked_at')->count());
    }

    public function test_publishing_rejects_an_objective_question_without_a_valid_correct_answer(): void
    {
        $exam = $this->readyDraft();
        $questionId = ExaminationQuestion::where('examination_id', $exam->id)->value('question_id');
        QuestionChoice::where('question_id', $questionId)->update(['is_correct' => false]);

        $this->actingAs($this->alpha)->post("/examinations/{$exam->id}/publish")->assertSessionHasErrors('questions');
        $this->assertSame('draft', $exam->fresh()->status->value);
        $this->assertNull(Question::find($questionId)->locked_at);
    }

    public function test_essay_and_true_false_questions_can_be_published(): void
    {
        $exam = $this->draft();
        $essay = Question::factory()->essay()->create(['subject_id' => $this->subject1->id]);
        $trueFalse = Question::factory()->trueFalse()->create(['subject_id' => $this->subject1->id]);
        $this->addQuestions($exam, [$essay, $trueFalse]);

        $this->actingAs($this->alpha)->post("/examinations/{$exam->id}/publish")->assertSessionHasNoErrors();
        $this->assertSame('published', $exam->fresh()->status->value);
    }

    public function test_publishing_twice_is_harmless(): void
    {
        $exam = $this->publishedExam();
        $lockTimes = Question::whereIn('id', ExaminationQuestion::where('examination_id', $exam->id)->pluck('question_id'))->pluck('locked_at', 'id');
        $this->travel(1)->hours();

        $this->actingAs($this->alpha)->post("/examinations/{$exam->id}/publish")->assertRedirect();

        $this->assertSame('published', $exam->fresh()->status->value);
        $this->assertEquals($lockTimes, Question::whereIn('id', $lockTimes->keys())->pluck('locked_at', 'id'));
        $this->assertSame(1, AuditLog::where('action', 'examination.published')->where('auditable_id', $exam->id)->count());
    }

    public function test_published_examination_settings_and_questions_are_fixed(): void
    {
        $exam = $this->publishedExam();
        $before = $exam->only(['title', 'duration_minutes', 'attempt_limit', 'access_code', 'randomize_questions']);
        $rows = $this->questionRows($exam);

        $this->actingAs($this->alpha)->put('/examinations/'.$exam->id, $this->updatePayload(['title' => 'Changed after publication', 'duration_minutes' => 5, 'randomize_questions' => true]))
            ->assertSessionHasErrors('examination');
        $this->put("/examinations/{$exam->id}/questions", ['questions' => [['question_id' => $this->mcq()->id, 'points' => '1']]])
            ->assertSessionHasErrors('examination');
        $this->put("/examinations/{$exam->id}/questions", ['questions' => []])->assertSessionHasErrors('examination');

        $this->assertSame($before, $exam->fresh()->only(['title', 'duration_minutes', 'attempt_limit', 'access_code', 'randomize_questions']));
        $this->assertSame($rows, $this->questionRows($exam));
    }

    public function test_archived_examination_cannot_be_edited_or_published(): void
    {
        $exam = $this->readyDraft();
        $this->actingAs($this->alpha)->post("/examinations/{$exam->id}/archive", ['reason' => 'Created by mistake'])->assertSessionHasNoErrors();

        $this->put('/examinations/'.$exam->id, $this->updatePayload(['title' => 'Edited archived']))->assertSessionHasErrors('examination');
        $this->post("/examinations/{$exam->id}/publish")->assertSessionHasErrors('examination');
        $this->assertSame('archived', $exam->fresh()->status->value);
        $this->assertSame('Synthetic Quiz 01', $exam->fresh()->title);
    }

    public function test_lifecycle_is_derived_from_the_availability_window(): void
    {
        $exam = $this->publishedExam(['opens_at' => '2026-10-02 08:00:00', 'closes_at' => '2026-10-02 10:00:00']);
        $draft = $this->draft(['opens_at' => '2026-10-02 08:00:00']);
        $this->actingAs($this->alpha);

        $lifecycle = fn (Examination $examination): string => $examination->fresh()->lifecycle()['value'];
        $this->assertSame('published', $lifecycle($exam));
        $this->assertSame('draft', $lifecycle($draft));

        $this->travelTo(Carbon::parse('2026-10-02 08:00:00', 'UTC'));
        $this->assertSame('active', $lifecycle($exam));
        $this->get("/examinations/{$exam->id}")->assertInertia(fn (Assert $page) => $page->where('examination.lifecycle.value', 'active')->where('examination.status', 'published'));
        $this->assertSame('draft', $lifecycle($draft));

        $this->travelTo(Carbon::parse('2026-10-02 09:59:59', 'UTC'));
        $this->assertSame('active', $lifecycle($exam));

        $this->travelTo(Carbon::parse('2026-10-02 10:00:00', 'UTC'));
        $this->assertSame('ended', $lifecycle($exam));
        $this->get('/examinations')->assertInertia(fn (Assert $page) => $page->where('examinations.data', fn ($rows) => collect($rows)->firstWhere('id', $exam->id)['lifecycle']['value'] === 'ended'));
        $this->assertSame('published', $exam->fresh()->status->value);

        $this->post("/examinations/{$exam->id}/archive", ['reason' => 'Term closed'])->assertSessionHasNoErrors();
        $this->assertSame('archived', $lifecycle($exam));
    }

    public function test_published_exam_without_window_is_active_immediately(): void
    {
        $exam = $this->publishedExam();
        $this->assertSame('active', $exam->lifecycle()['value']);
    }

    public function test_archive_requires_a_reason_and_no_in_progress_attempts(): void
    {
        $exam = $this->publishedExam();
        $attempt = $this->inProgressAttempt($exam);
        $this->actingAs($this->alpha);

        $this->post("/examinations/{$exam->id}/archive", [])->assertSessionHasErrors('reason');
        $this->post("/examinations/{$exam->id}/archive", ['reason' => str_repeat('r', 501)])->assertSessionHasErrors('reason');
        $this->post("/examinations/{$exam->id}/archive", ['reason' => 'End of term'])->assertSessionHasErrors('examination');
        $this->assertSame('published', $exam->fresh()->status->value);

        $attempt->forceFill(['status' => 'submitted', 'submitted_at' => now()])->save();
        $this->post("/examinations/{$exam->id}/archive", ['reason' => 'End of term'])->assertSessionHasNoErrors();
        $this->assertSame('archived', $exam->fresh()->status->value);

        $entry = AuditLog::where('action', 'examination.archived')->where('auditable_id', $exam->id)->sole();
        $this->assertSame('End of term', $entry->reason);
        $this->assertSame('published', $entry->old_values['status']);
        $this->assertSame($this->alpha->id, $entry->actor_id);

        // Archiving again changes nothing and records nothing new.
        $this->post("/examinations/{$exam->id}/archive", ['reason' => 'Again'])->assertSessionHasNoErrors();
        $this->assertSame(1, AuditLog::where('action', 'examination.archived')->where('auditable_id', $exam->id)->count());
    }

    public function test_archiving_keeps_question_links_and_locks(): void
    {
        $exam = $this->publishedExam();
        $rows = $this->questionRows($exam);
        $this->actingAs($this->alpha)->post("/examinations/{$exam->id}/archive", ['reason' => 'Archive'])->assertSessionHasNoErrors();
        $this->assertSame($rows, $this->questionRows($exam));
        $this->assertSame(0, Question::whereIn('id', array_column($rows, 'question_id'))->whereNull('locked_at')->count());
    }

    public function test_result_release_toggle_requires_reason_and_is_audited(): void
    {
        $exam = $this->publishedExam();
        $this->actingAs($this->alpha);

        $this->put("/examinations/{$exam->id}/results", ['release_results' => true])->assertSessionHasErrors('reason');
        $this->put("/examinations/{$exam->id}/results", ['reason' => 'Missing flag'])->assertSessionHasErrors('release_results');
        $this->put("/examinations/{$exam->id}/results", ['release_results' => 'soon', 'reason' => 'Bad flag'])->assertSessionHasErrors('release_results');
        $this->assertFalse($exam->fresh()->release_results);

        $this->put("/examinations/{$exam->id}/results", ['release_results' => true, 'reason' => 'Grading complete'])->assertSessionHasNoErrors();
        $this->assertTrue($exam->fresh()->release_results);
        $entry = AuditLog::where('action', 'examination.settings_updated')->where('auditable_id', $exam->id)->latest('id')->first();
        $this->assertSame('Grading complete', $entry->reason);
        $this->assertSame(['release_results' => false], $entry->old_values);
        $this->assertSame(['release_results' => true], $entry->new_values);
        $this->assertSame($this->alpha->id, $entry->actor_id);

        $this->put("/examinations/{$exam->id}/results", ['release_results' => false, 'reason' => 'Correction under review'])->assertSessionHasNoErrors();
        $this->assertFalse($exam->fresh()->release_results);
        $this->assertSame(2, AuditLog::where('action', 'examination.settings_updated')->where('auditable_id', $exam->id)->count());
    }

    public function test_publication_audit_contains_no_question_content_or_access_code(): void
    {
        $exam = $this->draft(['access_code' => 'SECRET-PUBLISH-CODE', 'description' => 'SECRET-PUBLISH-INSTRUCTIONS']);
        $question = $this->mcq(null, 'SECRET-PUBLISH-PROMPT');
        $this->addQuestions($exam, [$question]);

        $this->actingAs($this->alpha)->post("/examinations/{$exam->id}/publish")->assertSessionHasNoErrors();
        $this->put("/examinations/{$exam->id}/results", ['release_results' => true, 'reason' => 'Release'])->assertSessionHasNoErrors();
        $this->post("/examinations/{$exam->id}/archive", ['reason' => 'Archive'])->assertSessionHasNoErrors();

        $entry = AuditLog::where('action', 'examination.published')->where('auditable_id', $exam->id)->sole();
        $this->assertSame(['status' => 'published', 'question_count' => 1, 'duration_minutes' => 30], $entry->new_values);
        $this->assertDoesNotReveal($this->auditTextFor($exam), [
            'SECRET-PUBLISH-CODE', 'SECRET-PUBLISH-INSTRUCTIONS', 'SECRET-PUBLISH-PROMPT', 'Option A', 'Option B', 'is_correct',
        ]);
    }

    public function test_other_instructor_cannot_publish_archive_or_release(): void
    {
        $exam = $this->readyDraft();
        $this->actingAs($this->bravo);
        $this->post("/examinations/{$exam->id}/publish")->assertForbidden();
        $this->post("/examinations/{$exam->id}/archive", ['reason' => 'x'])->assertForbidden();
        $this->put("/examinations/{$exam->id}/results", ['release_results' => true, 'reason' => 'x'])->assertForbidden();
        $this->assertSame('draft', $exam->fresh()->status->value);
        $this->assertFalse($exam->fresh()->release_results);
        $this->assertSame(0, Question::whereNotNull('locked_at')->count());
    }

    public function test_class_subject_with_examinations_cannot_be_removed(): void
    {
        $exam = $this->draft();

        try {
            $this->app->make(ClassBatchService::class)->removeSubject($this->alphaOffering);
            $this->fail('Removal should have been refused.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('offering', $exception->errors());
        }

        $this->actingAs($this->academicAdmin)
            ->delete("/classes/{$this->batchA->id}/subjects/{$this->alphaOffering->id}")
            ->assertRedirect(route('classes.show', $this->batchA));
        $this->assertNotNull(ClassSubject::find($this->alphaOffering->id));
        $this->assertNotNull($exam->fresh());
        $this->assertDatabaseHas('instructor_assignments', ['class_subject_id' => $this->alphaOffering->id, 'instructor_id' => $this->alpha->id]);
    }

    public function test_database_refuses_to_delete_a_class_subject_with_examinations(): void
    {
        $this->draft();
        $this->expectException(QueryException::class);
        DB::table('instructor_assignments')->where('class_subject_id', $this->alphaOffering->id)->delete();
        DB::table('assessment_categories')->where('class_subject_id', $this->alphaOffering->id)->delete();
        DB::table('class_subjects')->where('id', $this->alphaOffering->id)->delete();
    }
}
