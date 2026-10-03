<?php

namespace Tests\Feature\Examinations\Builder;

use App\Enums\ExaminationKind;
use App\Models\AuditLog;
use App\Models\Examination;
use App\Models\ExaminationQuestion;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ExaminationDraftTest extends TestCase
{
    use BuildsExaminationBuilderFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildExaminationBuilderFixtures();
    }

    public function test_instructor_creates_a_draft_with_all_settings(): void
    {
        config(['institution.timezone' => 'Asia/Manila']);
        $response = $this->actingAs($this->alpha)->post('/examinations', $this->examPayload([
            'kind' => 'examination',
            'title' => 'Synthetic Midterm',
            'duration_minutes' => 90,
            'passing_score' => '60.5',
            'opens_at' => '2026-10-05T08:00',
            'closes_at' => '2026-10-05T12:00',
            'access_code' => 'SYNTH-7711',
            'randomize_questions' => true,
            'randomize_choices' => true,
            'one_question_at_a_time' => true,
            'allow_back_navigation' => false,
            'auto_submit' => false,
        ]));

        $exam = Examination::sole();
        // A new draft continues to step 2: choosing questions.
        $response->assertRedirect('/examinations/'.$exam->id.'/questions')->assertSessionHasNoErrors();
        $this->assertSame('draft', $exam->status->value);
        $this->assertSame(ExaminationKind::Examination, $exam->kind);
        $this->assertSame($this->alphaOffering->id, $exam->class_subject_id);
        $this->assertSame($this->alpha->id, $exam->created_by);
        $this->assertSame(90, $exam->duration_minutes);
        $this->assertSame('60.50', $exam->passing_score);
        // Entered in institution time (UTC+8), stored in UTC.
        $this->assertSame('2026-10-05 00:00:00', $exam->opens_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-05 04:00:00', $exam->closes_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('SYNTH-7711', $exam->access_code);
        $this->assertTrue($exam->randomize_questions);
        $this->assertTrue($exam->randomize_choices);
        $this->assertTrue($exam->one_question_at_a_time);
        $this->assertFalse($exam->allow_back_navigation);
        $this->assertFalse($exam->auto_submit);
    }

    public function test_quiz_kind_is_stored(): void
    {
        $this->actingAs($this->alpha)->post('/examinations', $this->examPayload(['kind' => 'quiz']))->assertSessionHasNoErrors();
        $this->assertSame(ExaminationKind::Quiz, Examination::sole()->kind);
    }

    public function test_status_owner_and_timestamps_cannot_be_forged_on_create(): void
    {
        $this->actingAs($this->alpha)->post('/examinations', $this->examPayload([
            'status' => 'published',
            'created_by' => $this->bravo->id,
            'id' => 999999,
        ]))->assertSessionHasNoErrors();

        $exam = Examination::sole();
        $this->assertSame('draft', $exam->status->value);
        $this->assertSame($this->alpha->id, $exam->created_by);
        $this->assertNotSame(999999, $exam->id);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidSettings(): array
    {
        return [
            'missing title' => [['title' => ''], 'title'],
            'title too long' => [['title' => str_repeat('T', 201)], 'title'],
            'unknown kind' => [['kind' => 'midterm'], 'kind'],
            'missing kind' => [['kind' => null], 'kind'],
            'zero duration' => [['duration_minutes' => 0], 'duration_minutes'],
            'duration over a day' => [['duration_minutes' => 1441], 'duration_minutes'],
            'fractional duration' => [['duration_minutes' => '10.5'], 'duration_minutes'],
            'negative passing score' => [['passing_score' => '-1'], 'passing_score'],
            'passing score over 100' => [['passing_score' => '100.01'], 'passing_score'],
            'passing score 3 decimals' => [['passing_score' => '75.125'], 'passing_score'],
            'close before open' => [['opens_at' => '2026-10-05T10:00', 'closes_at' => '2026-10-05T09:00'], 'closes_at'],
            'close equals open' => [['opens_at' => '2026-10-05T10:00', 'closes_at' => '2026-10-05T10:00'], 'closes_at'],
            'invalid open date' => [['opens_at' => 'not-a-date'], 'opens_at'],
            'access code too long' => [['access_code' => str_repeat('c', 101)], 'access_code'],
            'randomize not boolean' => [['randomize_questions' => 'maybe'], 'randomize_questions'],
            'navigation missing' => [['allow_back_navigation' => null], 'allow_back_navigation'],
            'one at a time not boolean' => [['one_question_at_a_time' => 'sometimes'], 'one_question_at_a_time'],
            'auto submit missing' => [['auto_submit' => null], 'auto_submit'],
            'release results missing' => [['release_results' => null], 'release_results'],
            'missing offering' => [['class_subject_id' => null], 'class_subject_id'],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('invalidSettings')]
    public function test_invalid_draft_settings_are_rejected(array $overrides, string $field): void
    {
        $this->actingAs($this->alpha)->post('/examinations', $this->examPayload($overrides))->assertSessionHasErrors($field);
        $this->assertSame(0, Examination::count());
    }

    public function test_optional_schedule_fields_may_be_left_open(): void
    {
        $this->actingAs($this->alpha)->post('/examinations', $this->examPayload(['duration_minutes' => null, 'passing_score' => null, 'opens_at' => null, 'closes_at' => '2026-10-20T17:00']))
            ->assertSessionHasNoErrors();
        $exam = Examination::sole();
        $this->assertNull($exam->duration_minutes);
        $this->assertNull($exam->opens_at);
        $this->assertNotNull($exam->closes_at);
    }

    public function test_nonexistent_offering_does_not_create_a_draft(): void
    {
        $response = $this->actingAs($this->alpha)->post('/examinations', $this->examPayload(['class_subject_id' => 999999]));
        $this->assertContains($response->getStatusCode(), [403, 404]);
        $this->assertSame(0, Examination::count());
    }

    public function test_instructor_updates_draft_settings(): void
    {
        $exam = $this->draft();
        $this->actingAs($this->alpha)->put('/examinations/'.$exam->id, $this->updatePayload([
            'title' => 'Renamed synthetic quiz',
            'kind' => 'examination',
            'duration_minutes' => 45,
            'access_code' => 'NEW-SYNTH-CODE',
        ]))->assertRedirect('/examinations/'.$exam->id)->assertSessionHasNoErrors();

        $exam->refresh();
        $this->assertSame('Renamed synthetic quiz', $exam->title);
        $this->assertSame(ExaminationKind::Examination, $exam->kind);
        $this->assertSame(45, $exam->duration_minutes);
        $this->assertSame('NEW-SYNTH-CODE', $exam->access_code);
    }

    public function test_offering_cannot_be_changed_on_update(): void
    {
        $exam = $this->draft();
        $this->actingAs($this->alpha)->put('/examinations/'.$exam->id, [
            ...$this->updatePayload(['title' => 'Moved']),
            'class_subject_id' => $this->bravoOffering->id,
        ])->assertSessionHasErrors('class_subject_id');
        $this->assertSame($this->alphaOffering->id, $exam->fresh()->class_subject_id);
        $this->assertSame('Synthetic Quiz 01', $exam->fresh()->title);
    }

    public function test_update_validation_rejects_bad_schedule(): void
    {
        $exam = $this->draft();
        $this->actingAs($this->alpha)->put('/examinations/'.$exam->id, $this->updatePayload(['opens_at' => '2026-10-10T10:00', 'closes_at' => '2026-10-09T10:00']))
            ->assertSessionHasErrors('closes_at');
        $this->assertNull($exam->fresh()->opens_at);
    }

    public function test_questions_are_synced_in_order_with_point_overrides(): void
    {
        $exam = $this->draft();
        [$first, $second, $third] = [$this->mcq(), $this->mcq(), $this->mcq()];

        $this->actingAs($this->alpha)->put("/examinations/{$exam->id}/questions", ['questions' => [
            ['question_id' => $third->id, 'points' => '5'],
            ['question_id' => $first->id, 'points' => '0.5'],
            ['question_id' => $second->id, 'points' => '100'],
        ]])->assertRedirect('/examinations/'.$exam->id)->assertSessionHasNoErrors();

        $rows = ExaminationQuestion::where('examination_id', $exam->id)->orderBy('position')->get();
        $this->assertSame([$third->id, $first->id, $second->id], $rows->pluck('question_id')->all());
        $this->assertSame([1, 2, 3], $rows->pluck('position')->all());
        $this->assertSame(['5.00', '0.50', '100.00'], $rows->pluck('points')->all());

        // Reordering and removing replaces the list.
        $this->put("/examinations/{$exam->id}/questions", ['questions' => [
            ['question_id' => $second->id, 'points' => '2'],
            ['question_id' => $third->id, 'points' => '1'],
        ]])->assertSessionHasNoErrors();
        $rows = ExaminationQuestion::where('examination_id', $exam->id)->orderBy('position')->get();
        $this->assertSame([$second->id, $third->id], $rows->pluck('question_id')->all());
        $this->assertSame([1, 2], $rows->pluck('position')->all());
    }

    public function test_question_list_can_be_cleared(): void
    {
        $exam = $this->readyDraft();
        $this->actingAs($this->alpha)->put("/examinations/{$exam->id}/questions", ['questions' => []])->assertSessionHasNoErrors();
        $this->assertSame(0, ExaminationQuestion::where('examination_id', $exam->id)->count());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidPoints(): array
    {
        return [
            'zero' => ['0'],
            'negative' => ['-1'],
            'over 100' => ['100.01'],
            'three decimals' => ['1.005'],
            'text' => ['many'],
        ];
    }

    #[DataProvider('invalidPoints')]
    public function test_point_overrides_are_bounded(string $points): void
    {
        $exam = $this->readyDraft();
        $before = ExaminationQuestion::where('examination_id', $exam->id)->orderBy('position')->get(['question_id', 'points'])->toArray();
        $question = $this->mcq();

        $this->actingAs($this->alpha)->put("/examinations/{$exam->id}/questions", ['questions' => [['question_id' => $question->id, 'points' => $points]]])
            ->assertSessionHasErrors('questions.0.points');
        $this->assertSame($before, ExaminationQuestion::where('examination_id', $exam->id)->orderBy('position')->get(['question_id', 'points'])->toArray());
    }

    public function test_duplicate_questions_are_rejected(): void
    {
        $exam = $this->draft();
        $question = $this->mcq();
        $this->actingAs($this->alpha)->put("/examinations/{$exam->id}/questions", ['questions' => [
            ['question_id' => $question->id, 'points' => '1'],
            ['question_id' => $question->id, 'points' => '2'],
        ]])->assertSessionHasErrors();
        $this->assertSame(0, ExaminationQuestion::where('examination_id', $exam->id)->count());
    }

    public function test_questions_of_another_subject_inactive_or_unknown_are_rejected_atomically(): void
    {
        $exam = $this->readyDraft();
        $before = ExaminationQuestion::where('examination_id', $exam->id)->orderBy('position')->pluck('question_id')->all();
        $valid = $this->mcq();
        $otherSubject = $this->mcq($this->subject2);
        $unrelatedSubject = $this->mcq($this->subject3);
        $inactive = $this->mcq();
        $inactive->forceFill(['is_active' => false])->save();

        $this->actingAs($this->alpha);
        foreach ([$otherSubject->id, $unrelatedSubject->id, $inactive->id, 999999] as $tamperedId) {
            $this->put("/examinations/{$exam->id}/questions", ['questions' => [
                ['question_id' => $valid->id, 'points' => '1'],
                ['question_id' => $tamperedId, 'points' => '1'],
            ]])->assertSessionHasErrors('questions');
            $this->assertSame($before, ExaminationQuestion::where('examination_id', $exam->id)->orderBy('position')->pluck('question_id')->all());
        }
    }

    public function test_sync_payload_is_validated(): void
    {
        $exam = $this->draft();
        $this->actingAs($this->alpha);
        $this->put("/examinations/{$exam->id}/questions", [])->assertSessionHasErrors('questions');
        $this->put("/examinations/{$exam->id}/questions", ['questions' => [['question_id' => 'abc', 'points' => '1']]])->assertSessionHasErrors('questions.0.question_id');
        $this->put("/examinations/{$exam->id}/questions", ['questions' => [['question_id' => $this->mcq()->id]]])->assertSessionHasErrors('questions.0.points');
    }

    public function test_questions_page_and_sync_are_refused_for_non_drafts(): void
    {
        $exam = $this->publishedExam();
        // An old link or the Back button leads back to the examination with an explanation.
        $this->actingAs($this->alpha)->get("/examinations/{$exam->id}/questions")
            ->assertRedirect("/examinations/{$exam->id}")
            ->assertInertiaFlash('toast.message', 'This examination is no longer a draft, so its questions cannot be changed.');
        $this->get("/examinations/{$exam->id}/edit")
            ->assertRedirect("/examinations/{$exam->id}")
            ->assertInertiaFlash('toast.message', 'This examination is no longer a draft, so its settings cannot be changed.');
    }

    public function test_audit_entries_for_drafts_contain_no_confidential_content(): void
    {
        $this->actingAs($this->alpha)->post('/examinations', $this->examPayload([
            'description' => 'SECRET-INSTRUCTIONS-TEXT',
            'access_code' => 'SECRET-ACCESS-9931',
        ]))->assertSessionHasNoErrors();
        $exam = Examination::sole();
        $question = $this->mcq(null, 'SECRET-PROMPT-TEXT');
        $this->put("/examinations/{$exam->id}/questions", ['questions' => [['question_id' => $question->id, 'points' => '3']]])->assertSessionHasNoErrors();
        $this->put('/examinations/'.$exam->id, $this->updatePayload([
            'title' => 'Renamed',
            'description' => 'SECRET-INSTRUCTIONS-CHANGED',
            'access_code' => 'SECRET-ACCESS-CHANGED',
        ]))->assertSessionHasNoErrors();

        $created = AuditLog::where('action', 'examination.created')->where('auditable_id', $exam->id)->sole();
        $this->assertSame($this->alpha->id, $created->actor_id);
        $this->assertTrue($created->new_values['has_access_code']);

        $questionsEntry = AuditLog::where('action', 'examination.questions_updated')->where('auditable_id', $exam->id)->sole();
        $this->assertSame($question->id, $questionsEntry->new_values['items'][0]['question_id']);

        $settings = AuditLog::where('action', 'examination.settings_updated')->where('auditable_id', $exam->id)->sole();
        $this->assertSame('Renamed', $settings->new_values['title']);
        $this->assertSame('changed', $settings->new_values['instructions_change']);
        $this->assertSame('changed', $settings->new_values['access_code_change']);

        $this->assertDoesNotReveal($this->auditTextFor($exam), [
            'SECRET-INSTRUCTIONS-TEXT', 'SECRET-INSTRUCTIONS-CHANGED', 'SECRET-ACCESS-9931', 'SECRET-ACCESS-CHANGED',
            'SECRET-PROMPT-TEXT', 'Option A', 'Option B', 'is_correct', 'isCorrect',
        ]);
    }

    public function test_database_rejects_duplicate_question_or_position_in_one_examination(): void
    {
        $exam = $this->readyDraft();
        $existing = ExaminationQuestion::where('examination_id', $exam->id)->orderBy('position')->first();

        $this->expectException(QueryException::class);
        DB::table('examination_questions')->insert([
            'examination_id' => $exam->id, 'question_id' => $existing->question_id, 'position' => 99, 'points' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_database_rejects_unknown_status(): void
    {
        $exam = $this->draft();
        $this->expectException(QueryException::class);
        DB::table('examinations')->where('id', $exam->id)->update(['status' => 'active']);
    }

    public function test_schedule_round_trips_through_the_settings_form_in_institution_time(): void
    {
        config(['institution.timezone' => 'Asia/Manila']);
        $this->actingAs($this->alpha)->post('/examinations', $this->examPayload(['opens_at' => '2026-10-05T08:00', 'closes_at' => '2026-10-05T23:30']))->assertSessionHasNoErrors();
        $exam = Examination::sole();

        $this->get('/examinations/'.$exam->id.'/edit')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('examination.opens_at', '2026-10-05T08:00')
            ->where('examination.closes_at', '2026-10-05T23:30'));
    }
}
