<?php

namespace Tests\Feature\Examinations;

use App\Models\Assessment;
use App\Models\Examination;
use App\Models\ExaminationAttempt;
use App\Models\ExaminationQuestion;
use App\Models\Question;
use App\Services\CandidateHomeService;
use App\Services\Examinations\ExaminationGradebookService;
use App\Services\Grading\GradeCalculationService;
use App\Services\Grading\GradingSchemeService;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Teaching\BuildsTeachingFixtures;
use Tests\TestCase;

class ExaminationGradebookTest extends TestCase
{
    use BuildsTeachingFixtures;

    private Examination $exam;

    private int $categoryId;

    private ExaminationAttempt $first;

    private ExaminationAttempt $latest;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildTeachingFixtures();
        $offering = $this->batchA->classSubjects()->whereHas('instructorAssignments', fn ($q) => $q->where('instructor_id', $this->alpha->id))->firstOrFail();
        app(GradingSchemeService::class)->save($offering, [['id' => null, 'name' => 'Examinations', 'weight' => '100']], null);
        $this->categoryId = $offering->assessmentCategories()->sole()->id;
        $exam = new Examination;
        $exam->class_subject_id = $offering->id;
        $exam->created_by = $this->alpha->id;
        $exam->title = 'Synthetic grade posting';
        $exam->status = 'published';
        $exam->duration_minutes = 10;
        $exam->closes_at = now()->subMinute();
        $exam->release_results = true;
        $exam->save();
        $this->exam = $exam->fresh();
        $item = new ExaminationQuestion;
        $item->examination_id = $exam->id;
        $item->question_id = Question::factory()->create(['subject_id' => $offering->subject_id])->id;
        $item->position = 1;
        $item->points = 10;
        $item->save();
        $this->first = $this->attempt(1, 8);
        $this->latest = $this->attempt(2, 6);
    }

    private function attempt(int $number, int $points): ExaminationAttempt
    {
        $attempt = new ExaminationAttempt;
        $attempt->candidate_id = $this->candidateInA->id;
        $attempt->examination_id = $this->exam->id;
        $attempt->attempt_number = $number;
        $attempt->status = 'submitted';
        $attempt->started_at = now()->subMinutes(15);
        $attempt->expires_at = now()->subMinutes(5);
        $attempt->submitted_at = now()->subMinutes(6);
        $attempt->delivery = [];
        $attempt->answers = ['private-answer'];
        $attempt->scoring_key = ['private-key'];
        $attempt->result_status = 'graded';
        $attempt->earned_points = $points;
        $attempt->total_points = 10;
        $attempt->percentage = $points * 10;
        $attempt->save();

        return $attempt;
    }

    private function payload(): array
    {
        $preview = app(ExaminationGradebookService::class)->preview($this->alpha, $this->exam->fresh(), 'highest');

        return ['title' => 'Posted examination', 'assessment_category_id' => $this->categoryId, 'attempt_rule' => 'highest', 'review_token' => $preview['reviewToken'], 'confirmed' => true];
    }

    public function test_posting_uses_reviewed_scores_updates_grades_and_is_idempotent(): void
    {
        $latest = app(ExaminationGradebookService::class)->preview($this->alpha, $this->exam, 'latest');
        $this->assertSame('6.00', collect($latest['rows'])->firstWhere('candidateId', $this->candidateInA->id)['score']);
        $payload = $this->payload();
        $this->actingAs($this->alpha)->get('/examinations/'.$this->exam->id.'/gradebook')->assertOk();
        $this->post('/examinations/'.$this->exam->id.'/gradebook', $payload)->assertRedirect();
        $assessment = Assessment::where('source_examination_id', $this->exam->id)->sole();
        $this->assertTrue($assessment->isFinalized());
        $score = $assessment->scores()->sole();
        $this->assertSame('8.00', $score->score);
        $this->assertEquals($this->first->id, $score->source_examination_attempt_id);
        $this->assertSame(1, $score->revisions()->count());
        $grade = app(GradeCalculationService::class)->forCandidate($this->candidateInA, [$this->exam->class_subject_id]);
        $this->assertEquals(80, $grade[$this->exam->class_subject_id]->grade);
        $this->post('/examinations/'.$this->exam->id.'/gradebook', $payload)->assertRedirect();
        $this->assertSame(1, Assessment::where('source_examination_id', $this->exam->id)->count());
        $this->get('/assessments/'.$assessment->id)->assertOk();
    }

    public function test_stale_results_unreleased_results_and_pending_essays_block_posting(): void
    {
        $payload = $this->payload();
        $this->first->earned_points = 9;
        $this->first->percentage = 90;
        $this->first->save();
        $this->actingAs($this->alpha)->post('/examinations/'.$this->exam->id.'/gradebook', $payload)->assertSessionHasErrors('posting');
        $this->exam->release_results = false;
        $this->exam->save();
        $this->post('/examinations/'.$this->exam->id.'/gradebook', $this->payload())->assertSessionHasErrors('posting');
        $this->exam->release_results = true;
        $this->exam->save();
        $this->latest->result_status = 'pending_review';
        $this->latest->save();
        $this->post('/examinations/'.$this->exam->id.'/gradebook', $this->payload())->assertSessionHasErrors('posting');
        $this->latest->result_status = 'graded';
        $this->latest->save();
        $this->post('/examinations/'.$this->exam->id.'/gradebook', [...$this->payload(), 'assessment_category_id' => 999999])->assertSessionHasErrors('assessment_category_id');
        $this->assertDatabaseMissing('assessments', ['source_examination_id' => $this->exam->id]);
    }

    public function test_unauthorized_users_cannot_preview_or_post(): void
    {
        foreach ([$this->candidateInA->user, $this->bravo] as $actor) {
            $this->actingAs($actor)->get('/examinations/'.$this->exam->id.'/gradebook')->assertForbidden();
            $this->post('/examinations/'.$this->exam->id.'/gradebook', $this->payload())->assertForbidden();
        }
    }

    public function test_home_scopes_schedules_and_released_results_to_the_candidate(): void
    {
        $this->exam->closes_at = now()->addDays(2);
        $this->exam->opens_at = now()->addDay();
        $this->exam->save();
        $this->actingAs($this->candidateInA->user)->get('/portal')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('portal/home')->has('upcoming.data', 1)->has('available.data', 0)->where('sections.examinations.releasedCount', 2)
            ->missing('upcoming.data.0.access_code'));
        $this->get('/portal/examinations')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('portal/examinations/index')->has('scoreTrend', 2)->missing('scoreTrend.0.answers'));
        $this->exam->release_results = false;
        $this->exam->save();
        $exams = app(CandidateHomeService::class)->examinations($this->candidateInA);
        $this->assertCount(0, $exams['scoreTrend']);
        $this->actingAs($this->candidateInB->user)->get('/portal')->assertInertia(fn (Assert $page) => $page
            ->has('upcoming.data', 0)->has('available.data', 0)->where('sections.examinations.releasedCount', 0)->where('sections.grades.outstandingCount', 0));
    }
}
