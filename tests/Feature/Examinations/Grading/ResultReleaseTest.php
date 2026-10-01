<?php

namespace Tests\Feature\Examinations\Grading;

use App\Enums\SystemRole;
use App\Models\AuditLog;
use App\Models\ExaminationAttempt;
use App\Services\CandidatePdfService;
use App\Services\Examinations\ManualEssayGradingService;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ResultReleaseTest extends TestCase
{
    use BuildsEssayGradingFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildEssayGradingFixtures();
    }

    private function gradeEssays(ExaminationAttempt $attempt, string $short, string $long, ?string $comment = null): ExaminationAttempt
    {
        $service = app(ManualEssayGradingService::class);
        $service->grade($this->alpha, $attempt, $this->shortEssay->id, $short, $comment, 0, null);

        return $service->grade($this->alpha, $attempt, $this->longEssay->id, $long, $comment, 0, null);
    }

    private function release(bool $release, ?string $reason = 'Results reviewed'): TestResponse
    {
        return $this->put('/examinations/'.$this->exam->id.'/results', array_filter(['release_results' => $release, 'reason' => $reason], fn ($value) => $value !== null));
    }

    // Final result calculation ---------------------------------------------------

    public function test_submission_scores_objective_items_and_waits_for_essays(): void
    {
        $attempt = $this->submitAttempt($this->candidateInA);

        $this->assertSame('pending_review', $attempt->result_status);
        $this->assertSame('4.00', $attempt->objective_points);
        $this->assertSame('4.00', $attempt->objective_max_points);
        $this->assertSame('12.00', $attempt->total_points);
        $this->assertNull($attempt->earned_points);
        $this->assertNull($attempt->percentage);
        $this->assertNull($attempt->passed);
        $this->assertSame('pending_review', $attempt->item_scores[$this->shortEssay->id]['status']);
        $this->assertSame('graded', $attempt->item_scores[$this->objectiveItem->id]['status']);
    }

    public function test_result_remains_pending_until_every_essay_is_graded(): void
    {
        $attempt = $this->submitAttempt($this->candidateInA);
        $attempt = app(ManualEssayGradingService::class)->grade($this->alpha, $attempt, $this->shortEssay->id, '3', null, 0, null);

        $this->assertSame('pending_review', $attempt->result_status);
        $this->assertNull($attempt->earned_points);
        $this->assertNull($attempt->percentage);
        $this->assertNull($attempt->passed);
        $this->assertEquals(3, $attempt->item_scores[$this->shortEssay->id]['points']);
        $this->assertNull($attempt->item_scores[$this->longEssay->id]['points']);
    }

    public function test_final_result_combines_objective_and_essay_points(): void
    {
        $attempt = $this->gradeEssays($this->submitAttempt($this->candidateInA), '2.5', '4');

        $this->assertSame('graded', $attempt->result_status);
        $this->assertSame('10.50', $attempt->earned_points);
        $this->assertSame('87.50', $attempt->percentage);
        $this->assertTrue($attempt->passed);
    }

    public function test_failing_result_against_passing_score(): void
    {
        $attempt = $this->gradeEssays($this->submitAttempt($this->candidateInA, objectiveCorrect: false), '1', '2');

        $this->assertSame('0.00', $attempt->objective_points);
        $this->assertSame('3.00', $attempt->earned_points);
        $this->assertSame('25.00', $attempt->percentage);
        $this->assertFalse($attempt->passed);
    }

    public function test_exactly_the_passing_score_passes(): void
    {
        // 4 + 1.2 + 2 = 7.2 of 12 = 60%.
        $attempt = $this->gradeEssays($this->submitAttempt($this->candidateInA), '1.2', '2');

        $this->assertSame('60.00', $attempt->percentage);
        $this->assertTrue($attempt->passed);
    }

    public function test_percentage_is_rounded_to_two_decimals(): void
    {
        // 0 + 1 + 0 = 1 of 12 = 8.333...%.
        $attempt = $this->gradeEssays($this->submitAttempt($this->candidateInA, objectiveCorrect: false), '1', '0');

        $this->assertSame('8.33', $attempt->percentage);
        $this->assertFalse($attempt->passed);
    }

    public function test_correction_recalculates_the_final_result(): void
    {
        $attempt = $this->gradeEssays($this->submitAttempt($this->candidateInA), '3', '5');
        $this->assertSame('100.00', $attempt->percentage);

        $attempt = app(ManualEssayGradingService::class)->grade($this->alpha, $attempt, $this->longEssay->id, '0', null, 1, 'Answer addressed a different question');

        $this->assertSame('graded', $attempt->result_status);
        $this->assertSame('7.00', $attempt->earned_points);
        $this->assertSame('58.33', $attempt->percentage);
        $this->assertFalse($attempt->passed);
    }

    public function test_passing_score_is_snapshotted_at_submission(): void
    {
        $attempt = $this->submitAttempt($this->candidateInA);
        $this->exam->passing_score = 95;
        $this->exam->save();
        $attempt = $this->gradeEssays($attempt, '2.5', '4');

        $this->assertSame('60.00', $attempt->passing_score);
        $this->assertTrue($attempt->passed);
    }

    public function test_without_a_passing_score_pass_state_is_unknown(): void
    {
        $this->exam->passing_score = null;
        $this->exam->save();
        $attempt = $this->gradeEssays($this->submitAttempt($this->candidateInA), '2.5', '4');

        $this->assertSame('87.50', $attempt->percentage);
        $this->assertNull($attempt->passed);
    }

    // Release endpoint -----------------------------------------------------------

    public function test_assigned_instructor_releases_and_withdraws_results_with_audited_reason(): void
    {
        $this->actingAs($this->alpha)->release(true, 'Essay review complete')->assertSessionHasNoErrors()->assertRedirect();
        $this->assertTrue($this->exam->fresh()->release_results);
        $this->release(false, 'Correction under review')->assertSessionHasNoErrors();
        $this->assertFalse($this->exam->fresh()->release_results);

        $logs = AuditLog::where('action', 'examination.settings_updated')->where('auditable_id', $this->exam->id)->orderBy('id')->get();
        $this->assertCount(2, $logs);
        $this->assertSame('Essay review complete', $logs[0]->reason);
        $this->assertEquals(['release_results' => true], $logs[0]->new_values);
        $this->assertEquals(['release_results' => false], $logs[0]->old_values);
        $this->assertSame('Correction under review', $logs[1]->reason);
    }

    public function test_release_requires_a_reason_and_a_boolean(): void
    {
        $this->actingAs($this->alpha)->release(true, null)->assertSessionHasErrors('reason');
        $this->put('/examinations/'.$this->exam->id.'/results', ['release_results' => 'maybe', 'reason' => 'x'])->assertSessionHasErrors('release_results');
        $this->put('/examinations/'.$this->exam->id.'/results', ['reason' => 'x'])->assertSessionHasErrors('release_results');
        $this->assertFalse($this->exam->fresh()->release_results);
    }

    public function test_release_is_forbidden_to_unassigned_users(): void
    {
        foreach ([$this->bravo, $this->userWithRole(SystemRole::SuperAdministrator), $this->userWithRole(SystemRole::AcademicAdministrator), $this->candidateInA->user] as $actor) {
            $this->actingAs($actor)->release(true)->assertForbidden();
        }
        $this->assertFalse($this->exam->fresh()->release_results);
        $this->assertSame(0, AuditLog::where('action', 'examination.settings_updated')->count());
    }

    public function test_guest_cannot_release_results(): void
    {
        $this->release(true)->assertRedirect(route('login'));
        $this->assertFalse($this->exam->fresh()->release_results);
    }

    // Candidate visibility -------------------------------------------------------

    public function test_unreleased_graded_result_is_hidden_on_every_candidate_page(): void
    {
        $attempt = $this->gradeEssays($this->submitAttempt($this->candidateInA), '2.5', '4', 'Private instructor feedback');
        $this->actingAs($this->candidateInA->user);

        $success = $this->get('/portal/attempts/'.$attempt->id.'/success')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('portal/examinations/success')
            ->where('releaseResults', false)
            ->where('percentage', null)->where('passed', null)
            ->where('objectivePoints', null)->where('objectiveMaxPoints', null));
        $this->assertStringNotContainsString('87.5', json_encode($success->viewData('page')['props']));

        $this->get('/portal/examinations')->assertInertia(fn (Assert $page) => $page->has('scoreTrend', 0));

        $this->get('/portal/examinations')->assertInertia(fn (Assert $page) => $page
            ->has('results.data', 1)
            ->where('results.data.0.resultLabel', 'Not released')
            ->where('results.data.0.score', null)
            ->where('results.data.0.maxScore', null)
            ->where('results.data.0.percentage', null)
            ->where('results.data.0.passed', null));

        $this->get('/portal/examinations/'.$this->exam->id)->assertInertia(fn (Assert $page) => $page
            ->where('examination.attempts.0.percentage', null));

        $pdf = app(CandidatePdfService::class)->data($this->candidateInA->user, $this->candidateInA, 'academic');
        $this->assertNull($pdf['examinations'][0]['score']);
        $this->assertNull($pdf['examinations'][0]['percentage']);
        $this->assertNull($pdf['examinations'][0]['passed']);
        // Only the document content matters; the stylesheet has unrelated numbers such as font sizes.
        $html = preg_replace('~<style>.*?</style>~s', '', view('pdf.candidate-record', [...$pdf, 'logo' => null])->render());
        $this->assertStringNotContainsString('10.5', $html);
    }

    public function test_released_graded_result_is_visible_to_its_candidate(): void
    {
        $attempt = $this->gradeEssays($this->submitAttempt($this->candidateInA), '2.5', '4');
        $this->actingAs($this->alpha)->release(true)->assertSessionHasNoErrors();
        $this->actingAs($this->candidateInA->user);

        $this->get('/portal/attempts/'.$attempt->id.'/success')->assertInertia(fn (Assert $page) => $page
            ->where('releaseResults', true)->where('resultStatus', 'graded')
            ->where('percentage', '87.50')->where('passed', true)
            ->where('objectivePoints', '4.00')->where('objectiveMaxPoints', '4.00'));

        $this->get('/portal/examinations')->assertInertia(fn (Assert $page) => $page
            ->has('scoreTrend', 1)->where('scoreTrend.0.id', $attempt->id)
            ->where('scoreTrend.0.percentage', 87.5)->where('scoreTrend.0.passed', true));

        $this->get('/portal/examinations')->assertInertia(fn (Assert $page) => $page
            ->where('results.data.0.resultLabel', 'Graded')
            ->where('results.data.0.score', '10.50')
            ->where('results.data.0.maxScore', '12.00')
            ->where('results.data.0.percentage', 87.5)
            ->where('results.data.0.passed', true));

        $pdf = app(CandidatePdfService::class)->data($this->candidateInA->user, $this->candidateInA, 'academic');
        $this->assertSame('10.50', $pdf['examinations'][0]['score']);
        $this->assertTrue($pdf['examinations'][0]['passed']);
    }

    public function test_released_but_pending_result_shows_no_final_score(): void
    {
        $attempt = $this->submitAttempt($this->candidateInA);
        app(ManualEssayGradingService::class)->grade($this->alpha, $attempt, $this->shortEssay->id, '3', null, 0, null);
        $this->releaseResults();
        $this->actingAs($this->candidateInA->user);

        $this->get('/portal/attempts/'.$attempt->id.'/success')->assertInertia(fn (Assert $page) => $page
            ->where('resultStatus', 'pending_review')->where('percentage', null)->where('passed', null)
            ->where('objectivePoints', '4.00'));
        $this->get('/portal/examinations')->assertInertia(fn (Assert $page) => $page->has('scoreTrend', 0));
        $this->get('/portal/examinations')->assertInertia(fn (Assert $page) => $page
            ->where('results.data.0.resultLabel', 'Awaiting review')
            ->where('results.data.0.score', null)
            ->where('results.data.0.percentage', null));
    }

    public function test_withdrawing_release_hides_results_again(): void
    {
        $attempt = $this->gradeEssays($this->submitAttempt($this->candidateInA), '2.5', '4');
        $this->actingAs($this->alpha)->release(true)->assertSessionHasNoErrors();
        $this->release(false, 'Correction under review')->assertSessionHasNoErrors();
        $this->actingAs($this->candidateInA->user);

        $this->get('/portal/attempts/'.$attempt->id.'/success')->assertInertia(fn (Assert $page) => $page->where('percentage', null)->where('passed', null));
        $this->get('/portal/examinations')->assertInertia(fn (Assert $page) => $page->has('scoreTrend', 0));
        $this->get('/portal/examinations')->assertInertia(fn (Assert $page) => $page->where('results.data.0.score', null));
    }

    public function test_instructor_feedback_never_reaches_candidate_pages(): void
    {
        $attempt = $this->gradeEssays($this->submitAttempt($this->candidateInA), '2.5', '4', 'Private instructor feedback');
        $this->releaseResults();
        $this->actingAs($this->candidateInA->user);

        foreach (['/portal/attempts/'.$attempt->id.'/success', '/portal', '/portal/examinations', '/portal/grades', '/portal/performance', '/portal/profile', '/portal/examinations/'.$this->exam->id] as $url) {
            $props = json_encode($this->get($url)->assertOk()->viewData('page')['props']);
            $this->assertStringNotContainsString('Private instructor feedback', $props, $url);
            $this->assertStringNotContainsString('correct_choice_id', $props, $url);
        }
    }

    public function test_released_results_of_one_candidate_are_not_visible_to_another(): void
    {
        $attempt = $this->gradeEssays($this->submitAttempt($this->candidateInA), '2.5', '4');
        $this->releaseResults();

        $this->actingAs($this->secondCandidateInA->user);
        $this->get('/portal/attempts/'.$attempt->id.'/success')->assertForbidden();
        $this->get('/portal/examinations')->assertInertia(fn (Assert $page) => $page->has('scoreTrend', 0));
        $this->get('/portal/examinations')->assertInertia(fn (Assert $page) => $page->has('results.data', 0));
    }
}
