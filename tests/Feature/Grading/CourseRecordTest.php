<?php

namespace Tests\Feature\Grading;

use App\Models\Subject;
use App\Models\TrainingPhase;
use App\Services\CandidatePdfService;
use App\Services\ClassBatchService;
use App\Services\Grading\CourseRecordService;
use App\Services\Performance\PerformanceAreaService;
use App\Services\Performance\QualificationEngine;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Training phases, phase averages and the CGPA (owner request, 2026-10-03).
 *
 * Batch A on top of BuildsGradingFixtures, for candidate A1:
 *   Subject 1, Phase 1, 3 units: Quizzes 40% (45/50 = 90), Examinations 60% (40/50 = 80) → 84.00
 *   Subject 2, Phase 2, 1 unit:  Practical 100% (30/50 = 60) → 60.00
 *   CGPA = (84 × 3 + 60 × 1) ÷ 4 = 78.00
 */
class CourseRecordTest extends TestCase
{
    use BuildsGradingFixtures;

    private TrainingPhase $phase1;

    private TrainingPhase $phase2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildGradingFixtures();

        // The three placeholder phases come from their migration.
        $this->phase1 = TrainingPhase::query()->where('number', 1)->sole();
        $this->phase2 = TrainingPhase::query()->where('number', 2)->sole();

        $classes = $this->app->make(ClassBatchService::class);
        $classes->updateSubject($this->offeringA1, $this->phase1, '3');
        $classes->updateSubject($this->offeringA2, $this->phase2, '1');

        $quiz = $this->createAssessment($this->quizzes, 'Quiz 1');
        $this->recordScores($quiz, [$this->candidateInA->id => '45', $this->secondInA->id => '40']);
        $this->finalize($quiz);
        $exam = $this->createAssessment($this->examinations, 'Examination 1');
        $this->recordScores($exam, [$this->candidateInA->id => '40', $this->secondInA->id => '35']);
        $this->finalize($exam);

        $this->setScheme($this->offeringA2, ['Practical' => '100']);
        $practical = $this->createAssessment($this->offeringA2->assessmentCategories()->sole(), 'Practical 1', actor: $this->bravo);
        $this->recordScores($practical, [$this->candidateInA->id => '30', $this->secondInA->id => '45'], $this->bravo);
        $this->finalize($practical, $this->bravo);
    }

    private function courses(): CourseRecordService
    {
        return $this->app->make(CourseRecordService::class);
    }

    public function test_phase_averages_and_the_cgpa_are_weighted_by_units(): void
    {
        $record = $this->courses()->forCandidate($this->candidateInA)->toArray();

        $this->assertSame([1, 2], array_map(fn (array $group): int => $group['phase']['number'], $record['phases']));
        $this->assertSame([84.0, 60.0], array_column($record['phases'], 'average'));
        $this->assertSame([true, true], array_column($record['phases'], 'complete'));
        $this->assertSame(['3', '1'], array_column($record['phases'], 'units'));
        $this->assertSame(['grade' => 78.0, 'complete' => true, 'gradedSubjects' => 2, 'totalSubjects' => 2], $record['cgpa']);
    }

    public function test_units_and_phases_change_the_record_at_once(): void
    {
        $classes = $this->app->make(ClassBatchService::class);
        // Equal units: the plain mean.
        $classes->updateSubject($this->offeringA1, $this->phase1, '1');
        $this->assertSame(72.0, $this->courses()->forCandidate($this->candidateInA)->cgpa);

        // A subject not in a phase is listed last and still counts in the CGPA.
        $classes->updateSubject($this->offeringA1, null, '3');
        $record = $this->courses()->forCandidate($this->candidateInA->fresh())->toArray();
        $this->assertSame([2, null], array_map(fn (array $group): ?int => $group['phase']['number'] ?? null, $record['phases']));
        $this->assertSame(78.0, $record['cgpa']['grade']);
    }

    public function test_a_subject_without_a_grade_is_left_out_and_keeps_the_record_in_progress(): void
    {
        $subject3 = Subject::factory()->create(['code' => 'SUBJ-3', 'name' => 'Subject 3']);
        $this->app->make(ClassBatchService::class)->addSubject($this->batchA, $subject3, $this->phase2, '2');

        $record = $this->courses()->forCandidate($this->candidateInA)->toArray();

        $phase2 = $record['phases'][1];
        $this->assertSame(60.0, $phase2['average']);
        $this->assertFalse($phase2['complete']);
        $this->assertSame([1, 2], [$phase2['gradedSubjects'], $phase2['totalSubjects']]);
        $this->assertSame(['grade' => 78.0, 'complete' => false, 'gradedSubjects' => 2, 'totalSubjects' => 3], $record['cgpa']);
    }

    public function test_the_candidate_sees_their_own_phase_averages_and_cgpa(): void
    {
        $this->actingAs($this->candidateInA->user)->get('/portal/grades')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('portal/grades')
                ->where('academics.course.cgpa.grade', 78)
                ->has('academics.course.phases', 2)
                ->where('academics.subjects.0.phase.name', 'Phase 1')
                ->where('academics.subjects.0.units', '3')
                ->where('academics.subjects.1.phase.name', 'Phase 2'));
    }

    public function test_the_profile_shows_the_course_record_only_to_staff_who_see_every_subject(): void
    {
        $this->actingAs($this->academicAdmin)->get("/candidates/{$this->candidateInA->id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('course.cgpa.grade', 78)
                ->where('performance.0.phase.name', 'Phase 1'));

        // Alpha teaches Subject 1 only: a CGPA would reveal Subject 2.
        $this->actingAs($this->alpha)->get("/candidates/{$this->candidateInA->id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('course', null)->has('performance', 1));
    }

    public function test_the_qualification_page_lists_each_candidates_cgpa(): void
    {
        $this->actingAs($this->academicAdmin)->get("/qualification?class={$this->batchA->id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('rows', function ($rows): bool {
                $byCandidate = collect($rows)->keyBy('candidate.id');

                // A2: (0.4 × 80 + 0.6 × 70) = 74.00 in Subject 1, 90.00 in Subject 2 → (74 × 3 + 90) ÷ 4 = 78.00.
                // Whole numbers arrive as integers after JSON.
                return (float) $byCandidate[$this->candidateInA->id]['cgpa']['grade'] === 78.0
                    && (float) $byCandidate[$this->secondInA->id]['cgpa']['grade'] === 78.0;
            }));
    }

    public function test_subject_areas_weight_their_subjects_by_units(): void
    {
        $this->actingAs($this->academicAdmin);
        $this->app->make(PerformanceAreaService::class)->create([
            'name' => 'Academic', 'description' => null, 'source' => 'subjects', 'weight' => '100', 'passing_grade' => '75',
            'must_pass' => true, 'base_rating' => null, 'merit_value' => null, 'demerit_value' => null, 'sort_order' => 1, 'is_active' => true,
        ], [$this->offeringA1->subject_id, $this->offeringA2->subject_id]);

        $qualification = $this->app->make(QualificationEngine::class)->forCandidate($this->candidateInA, withRank: false);

        $this->assertSame(78.0, $qualification->areas[0]->grade);
        $this->assertSame(78.0, $qualification->overall);
    }

    public function test_the_academic_record_pdf_lists_phases_and_the_cgpa(): void
    {
        $pdf = $this->app->make(CandidatePdfService::class);
        $data = $pdf->data($this->academicAdmin, $this->candidateInA, 'academic');

        $this->assertSame(78.0, $data['academics']['course']['cgpa']['grade']);
        $html = view('pdf.candidate-record', $data)->render();
        $this->assertStringContainsString('Cumulative General Point Average (CGPA)', $html);
        $this->assertStringContainsString('Phase 1 average', $html);
        $this->assertStringStartsWith('%PDF-', $pdf->render($data));
    }
}
