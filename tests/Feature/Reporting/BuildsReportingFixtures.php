<?php

namespace Tests\Feature\Reporting;

use App\Enums\Permission as PermissionCode;
use App\Enums\SystemRole;
use App\Models\Assessment;
use App\Models\Candidate;
use App\Models\ClassBatch;
use App\Models\ClassSubject;
use App\Models\Examination;
use App\Models\ExaminationAttempt;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Grading\GradingThresholdService;
use Tests\Feature\Grading\BuildsGradingFixtures;

/**
 * Reporting setup on top of the grading fixtures (see BuildsGradingFixtures),
 * with passing 75 and warning 80 in the active period. Each finalized
 * assessment is out of 100 and the only one of its subject, so the subject
 * grade equals the score:
 *
 *   Batch A, Subject 1 (Alpha)   Quiz 1:                A1 90, A2 60
 *   Batch A, Subject 2 (Bravo)   Subject 2 Examination: A1 70, A2 no score
 *   Batch B, Subject 1 (Bravo)   Batch B Quiz:          B1 77
 *
 * Overall standings: A1 Failing (70), A2 Failing (60), B1 Needs Improvement (77).
 */
trait BuildsReportingFixtures
{
    use BuildsGradingFixtures;

    protected Assessment $quiz1;

    protected Assessment $exam2;

    protected Assessment $quizB;

    protected function buildReportingFixtures(bool $withScores = true, bool $withThresholds = true): void
    {
        $this->buildGradingFixtures();
        if ($withThresholds) {
            $this->app->make(GradingThresholdService::class)->save($this->activePeriod, '75', '80', null);
        }
        if (! $withScores) {
            return;
        }

        $this->quiz1 = $this->createAssessment($this->quizzes, 'Quiz 1', '100', '2026-08-20');
        $this->recordScores($this->quiz1, [$this->candidateInA->id => '90', $this->secondInA->id => '60']);
        $this->finalize($this->quiz1);

        $this->travel(1)->minutes();
        $this->setScheme($this->offeringA2, ['Examinations' => '100']);
        $this->exam2 = $this->createAssessment($this->offeringA2->assessmentCategories()->sole(), 'Subject 2 Examination', '100', '2026-08-25', $this->bravo);
        $this->recordScores($this->exam2, [$this->candidateInA->id => '70'], $this->bravo);
        $this->finalize($this->exam2, $this->bravo);

        $this->travel(1)->minutes();
        $this->setScheme($this->offeringB1, ['Quizzes' => '100']);
        $this->quizB = $this->createAssessment($this->offeringB1->assessmentCategories()->sole(), 'Batch B Quiz', '100', '2026-08-27', $this->bravo);
        $this->recordScores($this->quizB, [$this->candidateInB->id => '77'], $this->bravo);
        $this->finalize($this->quizB, $this->bravo);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function makeExamination(ClassSubject $offering, string $title, array $attributes = []): Examination
    {
        $exam = new Examination;
        $exam->forceFill([
            'class_subject_id' => $offering->id,
            'created_by' => $this->alpha->id,
            'title' => $title,
            'kind' => 'examination',
            'status' => 'published',
            'duration_minutes' => 30,
            'release_results' => false,
            ...$attributes,
        ])->save();

        return $exam->fresh();
    }

    /**
     * A submitted, graded attempt by default.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function makeAttempt(Examination $exam, Candidate $candidate, array $attributes = []): ExaminationAttempt
    {
        $attempt = new ExaminationAttempt;
        $attempt->forceFill([
            'candidate_id' => $candidate->id,
            'examination_id' => $exam->id,
            'attempt_number' => 1,
            'status' => 'submitted',
            'started_at' => now()->subMinutes(10),
            'expires_at' => now()->addMinutes(20),
            'submitted_at' => now(),
            'delivery' => ['synthetic-delivery-secret'],
            'answers' => ['synthetic-answer-secret'],
            'scoring_key' => ['synthetic-scoring-key-secret'],
            'result_status' => 'graded',
            'earned_points' => 8,
            'total_points' => 10,
            'percentage' => 80,
            'passed' => true,
            ...$attributes,
        ])->save();

        return $attempt->fresh();
    }

    protected function makeCandidate(?ClassBatch $classBatch, string $number, array $attributes = []): Candidate
    {
        return Candidate::factory()->create([
            'class_batch_id' => $classBatch?->id,
            'candidate_number' => "2026-R{$number}",
            'first_name' => 'Candidate',
            'last_name' => "R{$number}",
            ...$attributes,
        ]);
    }

    /**
     * A staff account whose custom role grants staff access plus the given permissions.
     *
     * @param  list<PermissionCode>  $permissions
     */
    protected function staffWithPermissions(string $roleCode, array $permissions): User
    {
        $role = Role::query()->create(['code' => $roleCode, 'name' => ucwords(str_replace('_', ' ', $roleCode))]);
        $codes = array_map(fn (PermissionCode $permission): string => $permission->value, [PermissionCode::AccessStaffArea, ...$permissions]);
        $role->permissions()->sync(Permission::query()->whereIn('code', $codes)->pluck('id'));

        $user = $this->userWithRole(SystemRole::Instructor, ['name' => ucwords(str_replace('_', ' ', $roleCode))]);
        $user->role()->associate($role)->save();

        return $user->fresh();
    }
}
