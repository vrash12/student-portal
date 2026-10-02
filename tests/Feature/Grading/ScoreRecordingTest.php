<?php

namespace Tests\Feature\Grading;

use App\Enums\CandidateStatus;
use App\Enums\Permission as PermissionCode;
use App\Enums\ScoreRevisionKind;
use App\Enums\SystemRole;
use App\Models\Assessment;
use App\Models\AssessmentScore;
use App\Models\AssessmentScoreRevision;
use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\GradeCorrectionRequest;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Score recording on draft assessments (PUT /assessments/{a}/scores),
 * corrections of finalized scores (a request filed with
 * POST /assessments/{a}/correction-requests and approved by an
 * administrator; GradeCorrectionRequestTest covers the approval rules), the
 * change history on the assessment page, and the database constraints that
 * back them.
 */
class ScoreRecordingTest extends TestCase
{
    use BuildsGradingFixtures;

    /** Second instructor assigned to Batch A, Subject 1 (co-teaches with Alpha). */
    private User $charlie;

    /** Draft quiz of Batch A, Subject 1 (max score 50). */
    private Assessment $quiz;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildGradingFixtures();

        $this->charlie = $this->userWithRole(SystemRole::Instructor, ['name' => 'Instructor Charlie']);
        $this->teach($this->charlie, $this->offeringA1);

        $this->quiz = $this->createAssessment($this->quizzes, 'Quiz 1', '50');
    }

    // ---------------------------------------------------------------
    // Draft score sheet
    // ---------------------------------------------------------------

    public function test_recording_draft_scores_saves_them_with_recorded_revisions_and_one_audit_entry(): void
    {
        $this->putScores($this->quiz, [
            $this->candidateInA->id => $this->entry('42.5'),
            $this->secondInA->id => $this->entry('38', 'Late submission'),
        ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('assessments.show', $this->quiz))
            ->assertInertiaFlash('toast.type', 'success');

        $this->assertStoredScore($this->quiz, $this->candidateInA, '42.50', null);
        $this->assertStoredScore($this->quiz, $this->secondInA, '38.00', 'Late submission');

        $first = $this->revisionsFor($this->quiz, $this->candidateInA);
        $this->assertCount(1, $first);
        $this->assertSame(ScoreRevisionKind::Recorded, $first[0]->kind);
        $this->assertNull($first[0]->previous_score);
        $this->assertSame('42.50', $first[0]->new_score);
        $this->assertNull($first[0]->reason);
        $this->assertSame($this->alpha->id, (int) $first[0]->changed_by);

        $second = $this->revisionsFor($this->quiz, $this->secondInA);
        $this->assertCount(1, $second);
        $this->assertSame('Late submission', $second[0]->comment);

        $audits = AuditLog::query()->where('action', 'assessment.scores_recorded')->get();
        $this->assertCount(1, $audits);
        $this->assertSame('assessment', $audits[0]->auditable_type);
        $this->assertSame($this->quiz->id, (int) $audits[0]->auditable_id);
        $this->assertSame($this->alpha->id, (int) $audits[0]->actor_id);
        $this->assertSame(2, $audits[0]->new_values['scores_changed']);
    }

    public function test_changing_a_recorded_score_writes_an_updated_revision_with_previous_and_new_values(): void
    {
        $this->putScores($this->quiz, [$this->candidateInA->id => $this->entry('40')])->assertSessionHasNoErrors();

        $this->putScores($this->quiz, [
            $this->candidateInA->id => $this->entry('45', 'Re-marked item 2', expectedScore: '40'),
        ], $this->charlie)->assertSessionHasNoErrors();

        $this->assertStoredScore($this->quiz, $this->candidateInA, '45.00', 'Re-marked item 2');

        $revisions = $this->revisionsFor($this->quiz, $this->candidateInA);
        $this->assertCount(2, $revisions);
        $this->assertSame(ScoreRevisionKind::Recorded, $revisions[0]->kind);
        $this->assertSame(ScoreRevisionKind::Updated, $revisions[1]->kind);
        $this->assertSame('40.00', $revisions[1]->previous_score);
        $this->assertSame('45.00', $revisions[1]->new_score);
        $this->assertSame('Re-marked item 2', $revisions[1]->comment);
        $this->assertSame($this->charlie->id, (int) $revisions[1]->changed_by);

        $latestAudit = AuditLog::query()->where('action', 'assessment.scores_recorded')->latest('id')->firstOrFail();
        $this->assertSame(1, $latestAudit->new_values['scores_changed']);
        $this->assertSame($this->charlie->id, (int) $latestAudit->actor_id);
    }

    public function test_resubmitting_the_same_values_changes_nothing(): void
    {
        $entries = [
            $this->candidateInA->id => $this->entry('42'),
            $this->secondInA->id => $this->entry('30', 'Late'),
        ];

        $this->putScores($this->quiz, $entries)->assertSessionHasNoErrors();

        // Same request again (for example a double tap on Save): the stored
        // values already match, so this is not a conflict and not a change.
        $this->putScores($this->quiz, $entries)
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('assessments.show', $this->quiz));

        // And with the fresh expected values, formatted differently.
        $this->putScores($this->quiz, [
            $this->candidateInA->id => $this->entry('42.00', expectedScore: '42'),
        ])->assertSessionHasNoErrors();

        $this->assertSame(2, $this->revisionCount($this->quiz));
        $this->assertSame(1, AuditLog::query()->where('action', 'assessment.scores_recorded')->count());
        $this->assertStoredScore($this->quiz, $this->candidateInA, '42.00', null);
    }

    public function test_a_comment_can_be_recorded_without_a_score(): void
    {
        $this->putScores($this->quiz, [$this->candidateInA->id => $this->entry(null, 'Absent')])
            ->assertSessionHasNoErrors();

        $this->assertStoredScore($this->quiz, $this->candidateInA, null, 'Absent');

        $revisions = $this->revisionsFor($this->quiz, $this->candidateInA);
        $this->assertCount(1, $revisions);
        $this->assertSame(ScoreRevisionKind::Recorded, $revisions[0]->kind);
        $this->assertNull($revisions[0]->new_score);
        $this->assertSame('Absent', $revisions[0]->comment);
    }

    public function test_a_recorded_score_can_be_cleared(): void
    {
        $this->putScores($this->quiz, [$this->candidateInA->id => $this->entry('40')])->assertSessionHasNoErrors();

        $this->putScores($this->quiz, [$this->candidateInA->id => $this->entry(null, expectedScore: '40')])
            ->assertSessionHasNoErrors();

        $this->assertNull($this->quiz->scores()->where('candidate_id', $this->candidateInA->id)->value('score'));
        $this->assertSame(0, $this->quiz->scores()->whereNotNull('score')->count());

        $revisions = $this->revisionsFor($this->quiz, $this->candidateInA);
        $this->assertCount(2, $revisions);
        $this->assertSame(ScoreRevisionKind::Updated, $revisions[1]->kind);
        $this->assertSame('40.00', $revisions[1]->previous_score);
        $this->assertNull($revisions[1]->new_score);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidScores(): array
    {
        return [
            'negative' => ['-1'],
            'above the maximum' => ['50.01'],
            'three decimals' => ['12.345'],
            'not a number' => ['abc'],
        ];
    }

    #[DataProvider('invalidScores')]
    public function test_invalid_scores_are_rejected_and_nothing_is_saved(string $score): void
    {
        $this->putScores($this->quiz, [
            $this->secondInA->id => $this->entry('30'),
            $this->candidateInA->id => $this->entry($score),
        ])->assertSessionHasErrors("entries.{$this->candidateInA->id}.score");

        $this->assertNothingRecorded($this->quiz);
    }

    public function test_the_maximum_score_itself_and_zero_are_accepted(): void
    {
        $this->putScores($this->quiz, [
            $this->candidateInA->id => $this->entry('50'),
            $this->secondInA->id => $this->entry('0'),
        ])->assertSessionHasNoErrors();

        $this->assertStoredScore($this->quiz, $this->candidateInA, '50.00', null);
        $this->assertStoredScore($this->quiz, $this->secondInA, '0.00', null);
    }

    public function test_entries_must_be_keyed_by_candidate_id(): void
    {
        $this->putScores($this->quiz, ['abc' => $this->entry('30')])->assertSessionHasErrors('entries');
        $this->putScores($this->quiz, [0 => $this->entry('30')])->assertSessionHasErrors('entries');
        $this->putScores($this->quiz, [])->assertSessionHasErrors('entries');

        $this->assertNothingRecorded($this->quiz);
    }

    public function test_candidates_of_another_class_cannot_be_graded(): void
    {
        $this->putScores($this->quiz, [$this->candidateInB->id => $this->entry('30')])
            ->assertSessionHasErrors("entries.{$this->candidateInB->id}.score");

        $unknownId = (int) Candidate::query()->max('id') + 100;
        $this->putScores($this->quiz, [$unknownId => $this->entry('30')])
            ->assertSessionHasErrors("entries.{$unknownId}.score");

        $this->assertNothingRecorded($this->quiz);
    }

    public function test_withdrawn_candidates_cannot_be_graded(): void
    {
        $this->putScores($this->quiz, [$this->withdrawnInA->id => $this->entry('30')])
            ->assertSessionHasErrors("entries.{$this->withdrawnInA->id}.score");

        $this->assertNothingRecorded($this->quiz);
    }

    public function test_a_candidate_withdrawn_after_being_scored_can_no_longer_be_changed(): void
    {
        $this->recordScores($this->quiz, [$this->secondInA->id => '30']);
        $this->secondInA->forceFill(['status' => CandidateStatus::Withdrawn->value])->save();

        $this->putScores($this->quiz, [$this->secondInA->id => $this->entry('35', expectedScore: '30')])
            ->assertSessionHasErrors("entries.{$this->secondInA->id}.score");

        $this->assertStoredScore($this->quiz, $this->secondInA, '30.00', null);
        $this->assertSame(1, $this->revisionCount($this->quiz));
    }

    public function test_comments_are_limited_to_500_characters(): void
    {
        $this->putScores($this->quiz, [$this->candidateInA->id => $this->entry('30', str_repeat('a', 501))])
            ->assertSessionHasErrors("entries.{$this->candidateInA->id}.comment");

        $this->assertNothingRecorded($this->quiz);

        $this->putScores($this->quiz, [$this->candidateInA->id => $this->entry('30', str_repeat('a', 500))])
            ->assertSessionHasNoErrors();

        $this->assertSame(500, mb_strlen((string) $this->scoreRow($this->quiz, $this->candidateInA)->comment));
    }

    public function test_one_rejected_row_saves_nothing_from_the_whole_sheet(): void
    {
        $this->putScores($this->quiz, [
            $this->candidateInA->id => $this->entry('40'),
            $this->secondInA->id => $this->entry('35', 'Good work'),
            $this->candidateInB->id => $this->entry('30'),
        ])
            ->assertSessionHasErrors("entries.{$this->candidateInB->id}.score")
            ->assertSessionDoesntHaveErrors(["entries.{$this->candidateInA->id}.score", "entries.{$this->secondInA->id}.score"]);

        $this->assertNothingRecorded($this->quiz);
    }

    public function test_a_score_changed_by_another_user_is_reported_as_a_conflict(): void
    {
        $this->putScores($this->quiz, [$this->candidateInA->id => $this->entry('40')])->assertSessionHasNoErrors();

        // Charlie changes the score while Alpha still has the sheet open with 40.
        $this->putScores($this->quiz, [$this->candidateInA->id => $this->entry('45', expectedScore: '40')], $this->charlie)
            ->assertSessionHasNoErrors();

        // Alpha's save, still based on 40, is rejected along with every other row.
        $this->putScores($this->quiz, [
            $this->candidateInA->id => $this->entry('42', expectedScore: '40'),
            $this->secondInA->id => $this->entry('33'),
        ])->assertSessionHasErrors("entries.{$this->candidateInA->id}.score");

        $this->assertStoredScore($this->quiz, $this->candidateInA, '45.00', null);
        $this->assertSame(0, $this->quiz->scores()->where('candidate_id', $this->secondInA->id)->count());
        $this->assertSame(2, $this->revisionCount($this->quiz));

        // After reviewing the new value, Alpha saves again with it as the expected value.
        $this->putScores($this->quiz, [$this->candidateInA->id => $this->entry('42', expectedScore: '45')])
            ->assertSessionHasNoErrors();

        $this->assertStoredScore($this->quiz, $this->candidateInA, '42.00', null);
        $latest = $this->revisionsFor($this->quiz, $this->candidateInA)->last();
        $this->assertSame(ScoreRevisionKind::Updated, $latest->kind);
        $this->assertSame('45.00', $latest->previous_score);
        $this->assertSame('42.00', $latest->new_score);
        $this->assertSame($this->alpha->id, (int) $latest->changed_by);
    }

    public function test_a_score_first_recorded_by_another_user_is_a_conflict_for_a_sheet_that_saw_no_score(): void
    {
        $this->putScores($this->quiz, [$this->candidateInA->id => $this->entry('45')], $this->charlie)
            ->assertSessionHasNoErrors();

        // Alpha opened the sheet before Charlie's save and still expects no score.
        $this->putScores($this->quiz, [$this->candidateInA->id => $this->entry('40')])
            ->assertSessionHasErrors("entries.{$this->candidateInA->id}.score");

        $this->assertStoredScore($this->quiz, $this->candidateInA, '45.00', null);
    }

    public function test_a_comment_changed_by_another_user_is_also_a_conflict(): void
    {
        $this->putScores($this->quiz, [$this->candidateInA->id => $this->entry('40')])->assertSessionHasNoErrors();
        $this->putScores($this->quiz, [$this->candidateInA->id => $this->entry('40', 'Late', expectedScore: '40')], $this->charlie)
            ->assertSessionHasNoErrors();

        $this->putScores($this->quiz, [$this->candidateInA->id => $this->entry('41', expectedScore: '40')])
            ->assertSessionHasErrors("entries.{$this->candidateInA->id}.score");

        $this->assertStoredScore($this->quiz, $this->candidateInA, '40.00', 'Late');
    }

    public function test_draft_scores_cannot_be_recorded_on_a_finalized_assessment(): void
    {
        $this->recordScores($this->quiz, [$this->candidateInA->id => '40']);
        $this->finalize($this->quiz);

        $this->putScores($this->quiz, [
            $this->candidateInA->id => $this->entry('45', expectedScore: '40'),
            $this->secondInA->id => $this->entry('30'),
        ])->assertSessionHasErrors('entries');

        $this->assertStoredScore($this->quiz, $this->candidateInA, '40.00', null);
        $this->assertSame(0, $this->quiz->scores()->where('candidate_id', $this->secondInA->id)->count());
        $this->assertSame(1, $this->revisionCount($this->quiz));
    }

    public function test_users_who_may_not_record_grades_for_the_subject_are_forbidden(): void
    {
        foreach ($this->usersWithoutGradeAccess() as $label => $user) {
            $this->putScores($this->quiz, [$this->candidateInA->id => $this->entry('40')], $user)
                ->assertForbidden();

            $this->assertNothingRecorded($this->quiz, "Scores were saved by: {$label}");
        }
    }

    // ---------------------------------------------------------------
    // Corrections of finalized scores
    // ---------------------------------------------------------------

    public function test_a_finalized_score_is_corrected_with_a_reason(): void
    {
        $this->recordScores($this->quiz, [$this->candidateInA->id => '40']);
        $this->finalize($this->quiz);

        $this->postCorrection($this->quiz, [
            'candidate_id' => $this->candidateInA->id,
            'score' => '44',
            'comment' => 'Re-marked',
            'reason' => 'Item 3 was marked against the wrong key.',
            'expected_score' => '40',
            'expected_comment' => null,
        ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('assessments.show', $this->quiz))
            ->assertInertiaFlash('toast.type', 'success');

        $this->assertStoredScore($this->quiz, $this->candidateInA, '44.00', 'Re-marked');

        $revision = $this->revisionsFor($this->quiz, $this->candidateInA)->last();
        $this->assertSame(ScoreRevisionKind::Corrected, $revision->kind);
        $this->assertSame('40.00', $revision->previous_score);
        $this->assertSame('44.00', $revision->new_score);
        $this->assertSame('Re-marked', $revision->comment);
        // The incident report and both people are named in the reason; the approver made the change.
        $request = GradeCorrectionRequest::query()->sole();
        $this->assertSame($request->id, (int) $revision->grade_correction_request_id);
        $this->assertStringContainsString("Correction request #{$request->id}", $revision->reason);
        $this->assertStringContainsString('Item 3 was marked against the wrong key.', $revision->reason);
        $this->assertSame($this->academicAdmin->id, (int) $revision->changed_by);

        $scoreRow = $this->scoreRow($this->quiz, $this->candidateInA);
        $audit = AuditLog::query()->where('action', 'assessment_score.corrected')->sole();
        $this->assertSame('assessment_score', $audit->auditable_type);
        $this->assertSame($scoreRow->id, (int) $audit->auditable_id);
        $this->assertSame($this->academicAdmin->id, (int) $audit->actor_id);
        $this->assertSame($revision->reason, $audit->reason);
        $this->assertEquals(44.0, (float) $audit->new_values['score']);
        // Staff comments stay in the restricted score history, not the general audit log.
        $this->assertArrayNotHasKey('comment', $audit->new_values);
        $this->assertSame($this->candidateInA->candidate_number, $audit->new_values['candidate']);
        $this->assertSame('Quiz 1', $audit->new_values['assessment']);

        // The assessment stays finalized.
        $this->assertTrue($this->quiz->fresh()->isFinalized());
    }

    public function test_the_correction_audit_entry_keeps_the_previous_score_and_leaves_comments_to_the_score_history(): void
    {
        $this->putScores($this->quiz, [$this->candidateInA->id => $this->entry('40', 'Late')])->assertSessionHasNoErrors();
        $this->finalize($this->quiz);

        $this->postCorrection($this->quiz, [
            'candidate_id' => $this->candidateInA->id,
            'score' => '44',
            'comment' => 'Re-marked',
            'reason' => 'Item 3 was marked against the wrong key.',
            'expected_score' => '40',
            'expected_comment' => 'Late',
        ])->assertSessionHasNoErrors();

        $audit = AuditLog::query()->where('action', 'assessment_score.corrected')->sole();

        // Old values must be the values before the correction, new values the corrected ones.
        $this->assertEquals(40.0, (float) $audit->old_values['score'], 'Audit old_values.score must be the score before the correction.');
        $this->assertEquals(44.0, (float) $audit->new_values['score']);
        $this->assertStringContainsString('Item 3 was marked against the wrong key.', (string) $audit->reason);
        $this->assertArrayNotHasKey('comment', $audit->old_values);
        $this->assertArrayNotHasKey('comment', $audit->new_values);

        // The comments before and after the correction are traceable in the score history.
        $revision = $this->scoreRow($this->quiz, $this->candidateInA)->revisions()->latest('id')->firstOrFail();
        $this->assertSame('Re-marked', $revision->comment);
        $this->assertEquals(40.0, (float) $revision->previous_score);
    }

    public function test_a_correction_requires_an_incident_report_of_20_to_2000_characters(): void
    {
        $this->recordScores($this->quiz, [$this->candidateInA->id => '40']);
        $this->finalize($this->quiz);

        foreach ([null, '', '     ', 'Typo in the score.', str_repeat('r', 2001)] as $reason) {
            $this->postCorrection($this->quiz, $this->correction($this->candidateInA, '44', '40', reason: $reason))
                ->assertSessionHasErrors('incident_details');
        }
        $this->assertSame(0, GradeCorrectionRequest::query()->count());

        $this->assertStoredScore($this->quiz, $this->candidateInA, '40.00', null);
        $this->assertNoCorrections();
    }

    public function test_draft_scores_cannot_be_changed_through_a_correction(): void
    {
        $this->recordScores($this->quiz, [$this->candidateInA->id => '40']);

        $this->postCorrection($this->quiz, $this->correction($this->candidateInA, '44', '40'))
            ->assertSessionHasErrors('score');

        $this->assertStoredScore($this->quiz, $this->candidateInA, '40.00', null);
        $this->assertNoCorrections();
    }

    public function test_only_gradable_candidates_of_the_class_can_be_corrected(): void
    {
        $this->recordScores($this->quiz, [$this->candidateInA->id => '40']);
        $this->finalize($this->quiz);

        foreach ([$this->candidateInB, $this->withdrawnInA] as $candidate) {
            $this->postCorrection($this->quiz, $this->correction($candidate, '30', null))
                ->assertSessionHasErrors('candidate_id');
        }

        $this->assertSame(0, $this->quiz->scores()->whereIn('candidate_id', [$this->candidateInB->id, $this->withdrawnInA->id])->count());
        $this->assertNoCorrections();
    }

    public function test_a_correction_based_on_a_stale_value_is_reported_as_a_conflict(): void
    {
        $this->recordScores($this->quiz, [$this->candidateInA->id => '40']);
        $this->finalize($this->quiz);

        $this->postCorrection($this->quiz, $this->correction($this->candidateInA, '44', '40'), $this->charlie)
            ->assertSessionHasNoErrors();

        // Alpha still sees 40.
        $this->postCorrection($this->quiz, $this->correction($this->candidateInA, '46', '40'))
            ->assertSessionHasErrors('score');

        $this->assertStoredScore($this->quiz, $this->candidateInA, '44.00', null);
        $this->assertSame(1, $this->correctionCount());

        // With the fresh value the correction goes through.
        $this->postCorrection($this->quiz, $this->correction($this->candidateInA, '46', '44'))
            ->assertSessionHasNoErrors();

        $this->assertStoredScore($this->quiz, $this->candidateInA, '46.00', null);
        $latest = $this->revisionsFor($this->quiz, $this->candidateInA)->last();
        $this->assertSame(ScoreRevisionKind::Corrected, $latest->kind);
        $this->assertSame('44.00', $latest->previous_score);
        $this->assertSame('46.00', $latest->new_score);
        $this->assertSame($this->academicAdmin->id, (int) $latest->changed_by);
    }

    public function test_a_correction_must_change_the_score_or_comment(): void
    {
        $this->recordScores($this->quiz, [$this->candidateInA->id => '40']);
        $this->finalize($this->quiz);

        $this->postCorrection($this->quiz, $this->correction($this->candidateInA, '40.00', '40'))
            ->assertSessionHasErrors('score');

        $this->assertNoCorrections();
        $this->assertSame(0, AuditLog::query()->where('action', 'assessment_score.corrected')->count());
    }

    public function test_a_corrected_score_cannot_exceed_the_maximum_or_be_invalid(): void
    {
        $this->recordScores($this->quiz, [$this->candidateInA->id => '40']);
        $this->finalize($this->quiz);

        foreach (['50.5', '-2', '41.125', 'abc'] as $score) {
            $this->postCorrection($this->quiz, $this->correction($this->candidateInA, $score, '40'))
                ->assertSessionHasErrors('score');
        }

        $this->assertStoredScore($this->quiz, $this->candidateInA, '40.00', null);
        $this->assertNoCorrections();
    }

    public function test_a_missing_score_can_be_added_through_a_correction(): void
    {
        $this->recordScores($this->quiz, [$this->candidateInA->id => '40']);
        $this->finalize($this->quiz);

        $this->postCorrection($this->quiz, $this->correction($this->secondInA, '35', null, reason: 'Paper found after finalization.'))
            ->assertSessionHasNoErrors();

        $this->assertStoredScore($this->quiz, $this->secondInA, '35.00', null);

        $revisions = $this->revisionsFor($this->quiz, $this->secondInA);
        $this->assertCount(1, $revisions);
        $this->assertSame(ScoreRevisionKind::Corrected, $revisions[0]->kind);
        $this->assertNull($revisions[0]->previous_score);
        $this->assertSame('35.00', $revisions[0]->new_score);
        $this->assertStringContainsString('Paper found after finalization.', (string) $revisions[0]->reason);

        $audit = AuditLog::query()->where('action', 'assessment_score.corrected')->sole();
        $this->assertNull($audit->old_values['score'] ?? null);
        $this->assertEquals(35.0, (float) $audit->new_values['score']);
    }

    public function test_users_who_may_not_record_grades_for_the_subject_cannot_correct_scores(): void
    {
        $this->recordScores($this->quiz, [$this->candidateInA->id => '40']);
        $this->finalize($this->quiz);

        foreach ($this->usersWithoutGradeAccess() as $label => $user) {
            $this->postCorrection($this->quiz, $this->correction($this->candidateInA, '44', '40'), $user)
                ->assertForbidden();

            $this->assertStoredScore($this->quiz, $this->candidateInA, '40.00', null);
            $this->assertSame(0, $this->correctionCount(), "A correction was saved by: {$label}");
        }
    }

    // ---------------------------------------------------------------
    // Change history on the assessment page
    // ---------------------------------------------------------------

    public function test_the_assessment_page_lists_score_changes_newest_first(): void
    {
        $this->putScores($this->quiz, [
            $this->candidateInA->id => $this->entry('40'),
            $this->secondInA->id => $this->entry('30'),
        ])->assertSessionHasNoErrors();
        $this->putScores($this->quiz, [$this->candidateInA->id => $this->entry('42.5', 'Re-marked', expectedScore: '40')], $this->charlie)
            ->assertSessionHasNoErrors();
        $this->finalize($this->quiz);
        $this->postCorrection($this->quiz, $this->correction($this->secondInA, '33', '30', reason: 'Addition error on page 2.'))
            ->assertSessionHasNoErrors();

        // Changes on another assessment are not part of this history.
        $other = $this->createAssessment($this->quizzes, 'Quiz 2', '20');
        $this->recordScores($other, [$this->candidateInA->id => '10']);
        $this->recordScores($other, [$this->candidateInA->id => '12']);

        $this->actingAs($this->alpha)
            ->get("/assessments/{$this->quiz->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('staff/teaching/assessments/show')
                ->where('history.total', 2)
                ->has('history.entries', 2)
                ->has('history.entries.0', fn (Assert $entry) => $entry
                    ->where('kind.value', 'corrected')
                    ->where('candidate.candidateNumber', $this->secondInA->candidate_number)
                    ->where('candidate.name', 'Candidate A2')
                    ->where('previousScore', '30')
                    ->where('newScore', '33')
                    ->where('comment', null)
                    ->where('reason', fn (string $reason): bool => str_ends_with($reason, 'approved by Academic Admin: Addition error on page 2.'))
                    ->where('changedBy', 'Academic Admin')
                    ->whereType('changedAt', 'string')
                    ->etc())
                ->has('history.entries.1', fn (Assert $entry) => $entry
                    ->where('kind.value', 'updated')
                    ->where('candidate.candidateNumber', $this->candidateInA->candidate_number)
                    ->where('candidate.name', 'Candidate A1')
                    ->where('previousScore', '40')
                    ->where('newScore', '42.5')
                    ->where('comment', 'Re-marked')
                    ->where('reason', null)
                    ->where('changedBy', 'Instructor Charlie')
                    ->whereType('changedAt', 'string')
                    ->etc()));
    }

    public function test_history_total_counts_changes_beyond_the_listed_entries(): void
    {
        $this->recordScores($this->quiz, [$this->candidateInA->id => '10']);
        $scoreRow = $this->scoreRow($this->quiz, $this->candidateInA);

        $rows = [];
        for ($step = 1; $step <= 55; $step++) {
            $rows[] = [
                'assessment_score_id' => $scoreRow->id,
                'kind' => ScoreRevisionKind::Updated->value,
                'previous_score' => 10,
                'new_score' => 11,
                'comment' => "Change {$step}",
                'reason' => null,
                'changed_by' => $this->alpha->id,
                'created_at' => now(),
            ];
        }
        DB::table('assessment_score_revisions')->insert($rows);

        $this->actingAs($this->alpha)
            ->get("/assessments/{$this->quiz->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('history.total', 55)
                ->has('history.entries', 50)
                ->where('history.entries.0.comment', 'Change 55')
                ->where('history.entries.49.comment', 'Change 6'));
    }

    // ---------------------------------------------------------------
    // Database constraints and append-only history
    // ---------------------------------------------------------------

    public function test_the_database_rejects_negative_scores(): void
    {
        $this->assertRejectedByDatabase(fn () => DB::table('assessment_scores')->insert(
            $this->rawScoreRow($this->quiz, $this->candidateInA, -1),
        ));

        // Null scores and zero are allowed.
        DB::table('assessment_scores')->insert($this->rawScoreRow($this->quiz, $this->candidateInA, null));
        DB::table('assessment_scores')->insert($this->rawScoreRow($this->quiz, $this->secondInA, 0));
        $this->assertSame(2, $this->quiz->scores()->count());
    }

    public function test_the_database_allows_only_one_score_per_candidate_and_assessment(): void
    {
        DB::table('assessment_scores')->insert($this->rawScoreRow($this->quiz, $this->candidateInA, 10));

        $this->assertRejectedByDatabase(fn () => DB::table('assessment_scores')->insert(
            $this->rawScoreRow($this->quiz, $this->candidateInA, 12),
        ));

        $this->assertSame(1, $this->quiz->scores()->count());
    }

    public function test_the_database_rejects_unknown_revision_kinds(): void
    {
        $scoreId = DB::table('assessment_scores')->insertGetId($this->rawScoreRow($this->quiz, $this->candidateInA, 10));

        $this->assertRejectedByDatabase(fn () => DB::table('assessment_score_revisions')->insert(
            $this->rawRevisionRow($scoreId, 'deleted', null),
        ));

        foreach (['recorded', 'updated'] as $kind) {
            DB::table('assessment_score_revisions')->insert($this->rawRevisionRow($scoreId, $kind, null));
        }
        $this->assertSame(2, DB::table('assessment_score_revisions')->where('assessment_score_id', $scoreId)->count());
    }

    public function test_the_database_requires_a_reason_for_corrections(): void
    {
        $scoreId = DB::table('assessment_scores')->insertGetId($this->rawScoreRow($this->quiz, $this->candidateInA, 10));

        $this->assertRejectedByDatabase(fn () => DB::table('assessment_score_revisions')->insert(
            $this->rawRevisionRow($scoreId, 'corrected', null),
        ));

        DB::table('assessment_score_revisions')->insert($this->rawRevisionRow($scoreId, 'corrected', 'Marking error.'));
        $this->assertSame(1, DB::table('assessment_score_revisions')->where('assessment_score_id', $scoreId)->count());
    }

    public function test_score_revisions_cannot_be_modified(): void
    {
        $this->recordScores($this->quiz, [$this->candidateInA->id => '40']);
        $revision = $this->revisionsFor($this->quiz, $this->candidateInA)->sole();

        $revision->new_score = '45';

        $this->expectException(LogicException::class);
        $revision->save();
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    /**
     * @param  array<int|string, array<string, string|null>>  $entries
     */
    private function putScores(Assessment $assessment, array $entries, ?User $actor = null): TestResponse
    {
        return $this->actingAs($actor ?? $this->alpha)->put("/assessments/{$assessment->id}/scores", ['entries' => $entries]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function postCorrection(Assessment $assessment, array $data, ?User $actor = null): TestResponse
    {
        // Files the request (the reason is its incident report) and, when it is accepted, has an administrator approve it.
        $before = (int) GradeCorrectionRequest::query()->max('id');
        $response = $this->actingAs($actor ?? $this->alpha)->post("/assessments/{$assessment->id}/correction-requests", [
            'candidate_id' => $data['candidate_id'],
            'score' => $data['score'],
            'comment' => $data['comment'],
            'incident_type' => 'encoding_error',
            'incident_details' => $data['reason'],
            'expected_score' => $data['expected_score'],
            'expected_comment' => $data['expected_comment'],
        ]);

        $filed = GradeCorrectionRequest::query()->where('id', '>', $before)->first();
        if ($filed !== null) {
            $this->actingAs($this->academicAdmin)->post("/grade-corrections/{$filed->id}/approve")->assertSessionHasNoErrors();
        }

        return $response;
    }

    /**
     * @return array{score: string|null, comment: string|null, expected_score: string|null, expected_comment: string|null}
     */
    private function entry(
        ?string $score,
        ?string $comment = null,
        ?string $expectedScore = null,
        ?string $expectedComment = null,
    ): array {
        return [
            'score' => $score,
            'comment' => $comment,
            'expected_score' => $expectedScore,
            'expected_comment' => $expectedComment,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function correction(
        Candidate $candidate,
        ?string $score,
        ?string $expectedScore,
        ?string $reason = 'Marking error found on review.',
    ): array {
        return [
            'candidate_id' => $candidate->id,
            'score' => $score,
            'comment' => null,
            'reason' => $reason,
            'expected_score' => $expectedScore,
            'expected_comment' => null,
        ];
    }

    /**
     * Staff who must not change Batch A, Subject 1 scores: another subject's
     * instructor, administrators (no teaching permission), a teaching-only
     * role without grades.record, and a candidate account.
     *
     * @return array<string, User>
     */
    private function usersWithoutGradeAccess(): array
    {
        return [
            'instructor of another subject' => $this->bravo,
            'academic administrator' => $this->academicAdmin,
            'super administrator' => $this->userWithRole(SystemRole::SuperAdministrator),
            'teaching role without grades.record' => $this->teacherWithoutRecordGrades(),
            'candidate' => $this->candidateInA->user,
        ];
    }

    /**
     * A custom role that may teach (and so view the gradebook) but not record
     * grades, assigned to Batch A, Subject 1.
     */
    private function teacherWithoutRecordGrades(): User
    {
        $role = Role::query()->create(['code' => 'teaching_observer', 'name' => 'Teaching Observer']);
        $role->permissions()->sync(Permission::query()->whereIn('code', [
            PermissionCode::AccessStaffArea->value,
            PermissionCode::TeachClasses->value,
        ])->pluck('id'));

        $observer = User::factory()->create(['name' => 'Instructor Observer']);
        $observer->role()->associate($role)->save();
        $observer = $observer->fresh();

        $this->teach($observer, $this->offeringA1);

        return $observer;
    }

    private function scoreRow(Assessment $assessment, Candidate $candidate): AssessmentScore
    {
        return $assessment->scores()->where('candidate_id', $candidate->id)->sole();
    }

    private function assertStoredScore(Assessment $assessment, Candidate $candidate, ?string $score, ?string $comment): void
    {
        $row = $this->scoreRow($assessment, $candidate);

        $this->assertSame($score, $row->score, "Stored score of {$candidate->full_name}");
        $this->assertSame($comment, $row->comment, "Stored comment of {$candidate->full_name}");
    }

    /**
     * @return Collection<int, AssessmentScoreRevision>
     */
    private function revisionsFor(Assessment $assessment, Candidate $candidate): Collection
    {
        return AssessmentScoreRevision::query()
            ->whereHas('assessmentScore', fn ($scores) => $scores
                ->where('assessment_id', $assessment->id)
                ->where('candidate_id', $candidate->id))
            ->orderBy('id')
            ->get()
            ->toBase();
    }

    private function revisionCount(Assessment $assessment): int
    {
        return AssessmentScoreRevision::query()
            ->whereHas('assessmentScore', fn ($scores) => $scores->where('assessment_id', $assessment->id))
            ->count();
    }

    private function correctionCount(): int
    {
        return AssessmentScoreRevision::query()->where('kind', ScoreRevisionKind::Corrected->value)->count();
    }

    private function assertNoCorrections(): void
    {
        $this->assertSame(0, $this->correctionCount());
    }

    private function assertNothingRecorded(Assessment $assessment, string $message = ''): void
    {
        $this->assertSame(0, $assessment->scores()->count(), $message);
        $this->assertSame(0, $this->revisionCount($assessment), $message);
        $this->assertSame(0, AuditLog::query()->where('action', 'assessment.scores_recorded')->count(), $message);
    }

    private function assertRejectedByDatabase(callable $statement): void
    {
        try {
            $statement();
        } catch (QueryException) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail('The database accepted a row that violates a constraint.');
    }

    /**
     * @return array<string, mixed>
     */
    private function rawScoreRow(Assessment $assessment, Candidate $candidate, int|float|null $score): array
    {
        return [
            'assessment_id' => $assessment->id,
            'candidate_id' => $candidate->id,
            'score' => $score,
            'comment' => null,
            'recorded_by' => $this->alpha->id,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function rawRevisionRow(int $scoreId, string $kind, ?string $reason): array
    {
        return [
            'assessment_score_id' => $scoreId,
            'kind' => $kind,
            'previous_score' => null,
            'new_score' => 10,
            'comment' => null,
            'reason' => $reason,
            'changed_by' => $this->alpha->id,
            'created_at' => now(),
        ];
    }
}
