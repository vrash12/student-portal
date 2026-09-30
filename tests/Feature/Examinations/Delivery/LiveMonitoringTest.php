<?php

namespace Tests\Feature\Examinations\Delivery;

use App\Enums\SystemRole;
use App\Models\Candidate;
use App\Models\Examination;
use App\Models\ExaminationAttempt;
use App\Models\ExaminationQuestion;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Live monitoring on the staff examination page (Milestone 13): progress
 * and presence only, never answer content, for assigned instructors only.
 */
class LiveMonitoringTest extends TestCase
{
    use BuildsDeliveryFixtures;

    private Examination $exam;

    private ExaminationQuestion $essayItem;

    private Candidate $secondInA;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildDeliveryFixtures();
        $this->exam = $this->makeExamination(['attempt_limit' => 2]);
        $this->addItem($this->exam, $this->mcq());
        $this->essayItem = $this->addItem($this->exam, $this->essay());
        $this->secondInA = Candidate::query()->where('class_batch_id', $this->batchA->id)->where('last_name', 'A2')->firstOrFail();
    }

    private function classmate(string $lastName): Candidate
    {
        return Candidate::factory()->create(['class_batch_id' => $this->batchA->id, 'last_name' => $lastName]);
    }

    /**
     * @return array<string, mixed>
     */
    private function monitoring(): array
    {
        return $this->inertiaProps($this->actingAs($this->alpha)->get('/examinations/'.$this->exam->id)->assertOk())['monitoring'];
    }

    private function saveEssay(Candidate $candidate, ExaminationAttempt $attempt, string $text): void
    {
        [$position] = $this->deliveryFor($attempt, $this->essayItem);
        $this->actingAs($candidate->user)->putJson('/portal/attempts/'.$attempt->id.'/answers', ['revision' => $attempt->fresh()->revision, 'position' => $position, 'answer' => $text])->assertOk();
    }

    public function test_totals_and_rows_reflect_each_candidates_latest_attempt(): void
    {
        $inactive = $this->classmate('C3');
        $submitted = $this->classmate('C4');
        $expired = $this->classmate('C5');

        $inactiveAttempt = $this->startFor($inactive, $this->exam);
        $this->saveEssay($inactive, $inactiveAttempt, 'SENTINEL-ANSWER-INACTIVE');
        $submittedAttempt = $this->startFor($submitted, $this->exam);
        $this->saveEssay($submitted, $submittedAttempt, 'SENTINEL-ANSWER-SUBMITTED');
        $this->actingAs($submitted->user)->post('/portal/attempts/'.$submittedAttempt->id.'/submit', ['revision' => 1, 'position' => 0])->assertRedirect();
        $expiredAttempt = $this->startFor($expired, $this->exam);
        $expiredAttempt->forceFill(['status' => 'expired'])->save();

        // First attempt submitted, the retake in progress: the retake counts.
        $firstAttempt = $this->startFor($this->candidateInA, $this->exam);
        $this->actingAs($this->candidateInA->user)->post('/portal/attempts/'.$firstAttempt->id.'/submit', ['revision' => 0, 'position' => 0])->assertRedirect();

        $this->travel(3)->minutes();
        $retake = $this->startFor($this->candidateInA, $this->exam);
        $this->saveEssay($this->candidateInA, $retake, 'SENTINEL-ANSWER-ACTIVE');

        $monitoring = $this->monitoring();
        $this->assertSame(['candidates' => 5, 'active' => 1, 'inactive' => 1, 'submitted' => 1, 'expired' => 1, 'notStarted' => 1, 'leftScreen' => 0], $monitoring['totals']);
        $rows = collect($monitoring['candidates'])->keyBy('candidate.id');
        $this->assertSame(
            collect([$this->candidateInA, $this->secondInA, $inactive, $submitted, $expired])->pluck('id')->sort()->values()->all(),
            $rows->keys()->sort()->values()->all(),
        );
        $this->assertSame('active', $rows[$this->candidateInA->id]['status']);
        $this->assertSame(2, $rows[$this->candidateInA->id]['attemptNumber']);
        $this->assertSame(1, $rows[$this->candidateInA->id]['answered']);
        $this->assertSame(2, $rows[$this->candidateInA->id]['questionCount']);
        $this->assertSame('not_started', $rows[$this->secondInA->id]['status']);
        $this->assertSame(0, $rows[$this->secondInA->id]['answered']);
        $this->assertSame(2, $rows[$this->secondInA->id]['questionCount']);
        $this->assertNull($rows[$this->secondInA->id]['lastActivityAt']);
        $this->assertSame('inactive', $rows[$inactive->id]['status']);
        $this->assertSame(1, $rows[$inactive->id]['answered']);
        $this->assertSame('submitted', $rows[$submitted->id]['status']);
        $this->assertSame('expired', $rows[$expired->id]['status']);
    }

    public function test_monitoring_never_contains_answer_content_or_scoring_data(): void
    {
        $attempt = $this->startFor($this->candidateInA, $this->exam);
        $this->saveEssay($this->candidateInA, $attempt, 'SENTINEL-ANSWER-TEXT');
        $monitoring = $this->monitoring();
        foreach ($monitoring['candidates'] as $row) {
            $this->assertSame(['candidate', 'status', 'attemptNumber', 'answered', 'questionCount', 'lastActivityAt', 'focus'], array_keys($row));
            if ($row['focus'] !== null) {
                $this->assertSame(['count', 'awaySeconds', 'awaySince'], array_keys($row['focus']));
            }
            $this->assertSame(['id', 'number', 'name'], array_keys($row['candidate']));
        }
        $encoded = json_encode($monitoring);
        foreach (['SENTINEL-ANSWER-TEXT', 'answers', 'delivery', 'scoring_key', 'correct_choice_id', 'item_scores', 'value'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $encoded);
        }
    }

    public function test_heartbeat_keeps_a_quiet_candidate_active(): void
    {
        $attempt = $this->startFor($this->candidateInA, $this->exam);
        $this->travel(3)->minutes();
        $this->assertSame(1, $this->monitoring()['totals']['inactive']);
        $this->actingAs($this->candidateInA->user)->postJson('/portal/attempts/'.$attempt->id.'/activity')->assertOk();
        $totals = $this->monitoring()['totals'];
        $this->assertSame(1, $totals['active']);
        $this->assertSame(0, $totals['inactive']);
    }

    public function test_viewing_monitoring_reconciles_overdue_attempts(): void
    {
        $attempt = $this->startFor($this->candidateInA, $this->exam);
        $this->travel(31)->minutes();
        $this->exam->closes_at = now()->addHour();
        $this->exam->save();
        $monitoring = $this->monitoring();
        $this->assertSame(1, $monitoring['totals']['submitted']);
        $this->assertSame(0, $monitoring['totals']['active'] + $monitoring['totals']['inactive']);
        $this->assertSame('submitted', $attempt->fresh()->status);
        $this->assertSame('automatic', $attempt->fresh()->submission_kind);
    }

    public function test_withdrawn_and_other_class_candidates_are_excluded(): void
    {
        $totals = $this->monitoring()['totals'];
        // Batch A has two enrolled candidates and one withdrawn; Batch B is another class.
        $this->assertSame(2, $totals['candidates']);
        $this->assertSame(2, $totals['notStarted']);
    }

    public function test_only_the_assigned_instructor_can_see_monitoring(): void
    {
        $this->startFor($this->candidateInA, $this->exam);
        $url = '/examinations/'.$this->exam->id;
        $this->actingAs($this->alpha)->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('staff/examinations/show')
            ->has('monitoring.totals')
            ->has('monitoring.candidates', 2));
        $this->actingAs($this->bravo)->get($url)->assertForbidden();
        $this->actingAs($this->userWithRole(SystemRole::AcademicAdministrator))->get($url)->assertForbidden();
        $this->actingAs($this->userWithRole(SystemRole::SuperAdministrator))->get($url)->assertForbidden();
        $this->actingAs($this->candidateInA->user)->get($url)->assertForbidden();
        auth()->logout();
        $this->get($url)->assertRedirect('/login');
    }

    public function test_staff_examination_page_does_not_expose_the_access_code(): void
    {
        $this->exam->access_code = 'SENTINEL-CODE-551';
        $this->exam->save();
        $props = $this->inertiaProps($this->actingAs($this->alpha)->get('/examinations/'.$this->exam->id)->assertOk());
        $this->assertTrue($props['examination']['has_access_code']);
        $this->assertStringNotContainsString('SENTINEL-CODE-551', json_encode($props));
    }
}
