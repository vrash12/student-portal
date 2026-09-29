<?php

namespace Tests\Feature\Grading;

use App\Enums\AssessmentStatus;
use App\Enums\AuditAction;
use App\Enums\CandidateStatus;
use App\Models\AcademicPeriod;
use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\ClassBatch;
use App\Models\ClassSubject;
use App\Models\Subject;
use Database\Seeders\DemoGradingSeeder;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Regression tests for the issues found in the Milestone 4 review.
 */
class GradingReviewRegressionTest extends TestCase
{
    use BuildsGradingFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildGradingFixtures();
    }

    public function test_swapping_rows_between_categories_is_a_change_and_is_audited_by_category(): void
    {
        $quiz = $this->createAssessment($this->quizzes, 'Quiz 1', '20');
        $this->recordScores($quiz, [$this->candidateInA->id => '18']);
        $this->finalize($quiz);

        // Same names and weights in the same order, but on the other category:
        // the category holding Quiz 1 moves from 40% to 60%.
        $this->actingAs($this->academicAdmin)
            ->put($this->gradingUrl(), ['categories' => [
                ['id' => $this->examinations->id, 'name' => 'Quizzes', 'weight' => '40'],
                ['id' => $this->quizzes->id, 'name' => 'Examinations', 'weight' => '60'],
            ], 'reason' => 'Category labels were swapped.'])
            ->assertSessionHasNoErrors();

        $this->assertSame('Examinations', $this->quizzes->fresh()->name);
        $this->assertSame('60.00', $this->quizzes->fresh()->weight);

        $entry = AuditLog::query()->where('action', AuditAction::GradingSchemeUpdated->value)->latest('id')->firstOrFail();
        $this->assertSame(
            ['id' => $this->quizzes->id, 'name' => 'Quizzes', 'weight' => '40.00'],
            $entry->old_values['categories'][0],
        );
        $this->assertContains(
            ['id' => $this->quizzes->id, 'name' => 'Examinations', 'weight' => '60.00'],
            $entry->new_values['categories'],
        );
    }

    public function test_the_total_and_the_missing_reason_are_reported_together(): void
    {
        $exam = $this->createAssessment($this->examinations, 'Midterm', '100');
        $this->recordScores($exam, [$this->candidateInA->id => '80']);
        $this->finalize($exam);

        $this->actingAs($this->academicAdmin)
            ->put($this->gradingUrl(), ['categories' => [
                ['id' => $this->quizzes->id, 'name' => 'Quizzes', 'weight' => '50'],
                ['id' => $this->examinations->id, 'name' => 'Examinations', 'weight' => '60'],
            ]])
            ->assertSessionHasErrors(['categories', 'reason']);
    }

    public function test_names_differing_only_by_accents_are_reported_on_the_row(): void
    {
        $this->actingAs($this->academicAdmin)
            ->put($this->gradingUrl(), ['categories' => [
                ['id' => $this->quizzes->id, 'name' => 'Examen', 'weight' => '50'],
                ['id' => $this->examinations->id, 'name' => 'Exámen', 'weight' => '50'],
            ]])
            ->assertSessionHasErrors('categories.1.name');

        $this->assertSame('Quizzes', $this->quizzes->fresh()->name);
    }

    public function test_scores_recorded_counts_only_candidates_who_can_be_graded(): void
    {
        $quiz = $this->createAssessment($this->quizzes, 'Quiz 1', '20');
        $this->recordScores($quiz, [$this->candidateInA->id => '18', $this->secondInA->id => '15']);

        $this->secondInA->forceFill(['status' => CandidateStatus::Withdrawn->value])->save();

        $this->actingAs($this->alpha)
            ->get("/my-classes/{$this->batchA->id}/subjects/{$this->offeringA1->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('gradableCount', 1)
                ->where('assessments.0.scoredCount', 1));
    }

    public function test_the_candidate_profile_uses_a_fixed_number_of_queries_for_grades(): void
    {
        $quiz = $this->createAssessment($this->quizzes, 'Quiz 1', '20');
        $this->recordScores($quiz, [$this->candidateInA->id => '18']);
        $this->finalize($quiz);

        // The first request also loads the acting user's role; measure warm.
        $this->profileQueryCount();
        $before = $this->profileQueryCount();

        // Three more graded subjects in the candidate's class.
        foreach (range(3, 5) as $number) {
            $offering = $this->offering($this->batchA, Subject::factory()->create(['code' => "SUBJ-{$number}", 'name' => "Subject {$number}"]));
            $this->setScheme($offering, ['Quizzes' => '100']);
        }

        $this->assertSame($before, $this->profileQueryCount());
    }

    public function test_demo_grading_seeder_skips_empty_classes_and_only_finalizes_scored_assessments(): void
    {
        AcademicPeriod::query()->update(['is_active' => false]);
        $period = AcademicPeriod::factory()->active()->create(['name' => 'Seeder Period']);
        $subject = Subject::factory()->create(['code' => 'SEED-1', 'name' => 'Seed Subject']);

        // Offering 0: a class without candidates. Offering 1: a class of one,
        // whose only candidate gets the deliberate excused absence on the
        // practical exercise.
        $empty = $this->offering(ClassBatch::factory()->for($period)->create(['name' => 'Empty Batch']), $subject);
        $single = $this->offering($singleClass = ClassBatch::factory()->for($period)->create(['name' => 'Single Batch']), $subject);
        $this->teach($this->alpha, $empty);
        $this->teach($this->alpha, $single);
        Candidate::factory()->create(['class_batch_id' => $singleClass->id]);

        $this->seed(DemoGradingSeeder::class);

        $this->assertFalse($empty->assessmentCategories()->exists());
        $statuses = $single->assessments()->pluck('status', 'title')->map(fn ($status) => $status instanceof AssessmentStatus ? $status->value : $status)->all();
        $this->assertSame(AssessmentStatus::Finalized->value, $statuses['Quiz 1']);
        $this->assertSame(AssessmentStatus::Draft->value, $statuses['Practical Exercise 1']);

        // Running again changes nothing.
        $counts = [ClassSubject::query()->has('assessmentCategories')->count(), DB::table('assessments')->count()];
        $this->seed(DemoGradingSeeder::class);
        $this->assertSame($counts, [ClassSubject::query()->has('assessmentCategories')->count(), DB::table('assessments')->count()]);
    }

    private function gradingUrl(): string
    {
        return "/classes/{$this->batchA->id}/subjects/{$this->offeringA1->id}/grading";
    }

    private function profileQueryCount(): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->academicAdmin)->get("/candidates/{$this->candidateInA->id}")->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
