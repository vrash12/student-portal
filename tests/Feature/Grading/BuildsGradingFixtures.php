<?php

namespace Tests\Feature\Grading;

use App\Enums\SystemRole;
use App\Models\Assessment;
use App\Models\AssessmentCategory;
use App\Models\Candidate;
use App\Models\ClassSubject;
use App\Models\Subject;
use App\Models\User;
use App\Services\Grading\AssessmentService;
use App\Services\Grading\GradingSchemeService;
use App\Services\Grading\ScoreRecordingService;
use App\Support\DecimalValue;
use Tests\Feature\Teaching\BuildsTeachingFixtures;

/**
 * Grading setup shared by the Milestone 4 tests, on top of the teaching
 * fixtures (see BuildsTeachingFixtures):
 *
 * Active period
 *   Batch A: Subject 1 (Alpha)  grading: Quizzes 40%, Examinations 60%
 *            Subject 2 (Bravo)  grading: not set up
 *            candidates: A1 (enrolled), A2 (enrolled), A3 (withdrawn)
 *   Batch B: Subject 1 (Bravo)  grading: not set up
 *            candidates: B1 (enrolled)
 *
 * Administrator: academic administrator account (configures grading, does
 * not teach).
 */
trait BuildsGradingFixtures
{
    use BuildsTeachingFixtures;

    protected User $academicAdmin;

    /** Batch A, Subject 1, taught by Alpha. */
    protected ClassSubject $offeringA1;

    /** Batch A, Subject 2, taught by Bravo. */
    protected ClassSubject $offeringA2;

    /** Batch B, Subject 1, taught by Bravo. */
    protected ClassSubject $offeringB1;

    protected AssessmentCategory $quizzes;

    protected AssessmentCategory $examinations;

    /** Enrolled in Batch A (last name A2). */
    protected Candidate $secondInA;

    /** Withdrawn from Batch A (last name A3). */
    protected Candidate $withdrawnInA;

    protected function buildGradingFixtures(): void
    {
        $this->buildTeachingFixtures();

        $this->academicAdmin = $this->userWithRole(SystemRole::AcademicAdministrator, ['name' => 'Academic Admin']);

        $subject1 = Subject::query()->where('code', 'SUBJ-1')->sole();
        $subject2 = Subject::query()->where('code', 'SUBJ-2')->sole();
        $this->offeringA1 = $this->offering($this->batchA, $subject1);
        $this->offeringA2 = $this->offering($this->batchA, $subject2);
        $this->offeringB1 = $this->offering($this->batchB, $subject1);

        $this->secondInA = Candidate::query()->where('class_batch_id', $this->batchA->id)->where('last_name', 'A2')->sole();
        $this->withdrawnInA = Candidate::query()->where('class_batch_id', $this->batchA->id)->where('last_name', 'A3')->sole();

        $this->setScheme($this->offeringA1, ['Quizzes' => '40', 'Examinations' => '60']);
        $this->quizzes = $this->offeringA1->assessmentCategories()->where('name', 'Quizzes')->sole();
        $this->examinations = $this->offeringA1->assessmentCategories()->where('name', 'Examinations')->sole();
    }

    /**
     * @param  array<string, string>  $weights  category name => weight
     */
    protected function setScheme(ClassSubject $offering, array $weights, ?string $reason = null): void
    {
        $this->app->make(GradingSchemeService::class)->save(
            $offering,
            array_map(
                fn (string $name, string $weight): array => ['id' => null, 'name' => $name, 'weight' => $weight],
                array_keys($weights),
                array_values($weights),
            ),
            $reason,
        );
    }

    protected function createAssessment(
        AssessmentCategory $category,
        string $title,
        string $maxScore = '50',
        ?string $date = null,
        ?User $actor = null,
    ): Assessment {
        return $this->app->make(AssessmentService::class)->create($category->classSubject, [
            'title' => $title,
            'assessment_category_id' => $category->id,
            'max_score' => $maxScore,
            'assessed_on' => $date,
        ], $actor ?? $this->alpha);
    }

    /**
     * Records draft scores through the service.
     *
     * @param  array<int, string|null>  $scores  candidate id => raw score
     */
    protected function recordScores(Assessment $assessment, array $scores, ?User $actor = null): void
    {
        $existing = $assessment->scores()->get()->keyBy('candidate_id');

        $entries = [];
        foreach ($scores as $candidateId => $score) {
            $current = $existing->get($candidateId);
            $entries[$candidateId] = [
                'score' => $score,
                'comment' => $current?->comment,
                'expected_score' => DecimalValue::normalize($current?->score),
                'expected_comment' => $current?->comment,
            ];
        }

        $this->app->make(ScoreRecordingService::class)->recordDraftScores($assessment, $entries, $actor ?? $this->alpha);
    }

    protected function finalize(Assessment $assessment, ?User $actor = null): void
    {
        $this->app->make(AssessmentService::class)->finalize($assessment, $actor ?? $this->alpha);
        $assessment->refresh();
    }
}
