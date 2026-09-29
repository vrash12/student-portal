<?php

namespace Tests\Feature\Teaching;

use App\Enums\CandidateStatus;
use App\Enums\SystemRole;
use App\Models\AcademicPeriod;
use App\Models\Candidate;
use App\Models\ClassBatch;
use App\Models\ClassSubject;
use App\Models\InstructorAssignment;
use App\Models\Subject;
use App\Models\User;
use App\Services\ClassBatchService;
use App\Services\InstructorAssignmentService;

/**
 * Teaching setup shared by the Milestone 3 tests:
 *
 * Active period
 *   Batch A: Subject 1 (Alpha), Subject 2 (Bravo)   candidates: 2 enrolled, 1 withdrawn
 *   Batch B: Subject 1 (Bravo)                      candidates: 1 enrolled
 * Past period
 *   Batch Old: Subject 1 (Alpha)                    candidates: 1 completed
 */
trait BuildsTeachingFixtures
{
    protected AcademicPeriod $activePeriod;

    protected AcademicPeriod $pastPeriod;

    protected ClassBatch $batchA;

    protected ClassBatch $batchB;

    protected ClassBatch $batchOld;

    protected User $alpha;

    protected User $bravo;

    protected Candidate $candidateInA;

    protected Candidate $candidateInB;

    protected function buildTeachingFixtures(): void
    {
        $this->activePeriod = AcademicPeriod::factory()->active()->create(['name' => 'Period Current', 'starts_on' => '2026-08-03']);
        $this->pastPeriod = AcademicPeriod::factory()->create(['name' => 'Period Past', 'starts_on' => '2026-01-05', 'ends_on' => '2026-05-29']);

        $this->batchA = ClassBatch::factory()->for($this->activePeriod)->create(['name' => 'Sample Batch A']);
        $this->batchB = ClassBatch::factory()->for($this->activePeriod)->create(['name' => 'Sample Batch B']);
        $this->batchOld = ClassBatch::factory()->for($this->pastPeriod)->create(['name' => 'Sample Batch Old']);

        $subject1 = Subject::factory()->create(['code' => 'SUBJ-1', 'name' => 'Subject 1']);
        $subject2 = Subject::factory()->create(['code' => 'SUBJ-2', 'name' => 'Subject 2']);

        $this->alpha = $this->userWithRole(SystemRole::Instructor, ['name' => 'Instructor Alpha']);
        $this->bravo = $this->userWithRole(SystemRole::Instructor, ['name' => 'Instructor Bravo']);

        $this->teach($this->alpha, $this->offering($this->batchA, $subject1));
        $this->teach($this->bravo, $this->offering($this->batchA, $subject2));
        $this->teach($this->bravo, $this->offering($this->batchB, $subject1));
        $this->teach($this->alpha, $this->offering($this->batchOld, $subject1));

        $this->candidateInA = Candidate::factory()->create(['class_batch_id' => $this->batchA->id, 'last_name' => 'A1']);
        Candidate::factory()->create(['class_batch_id' => $this->batchA->id, 'last_name' => 'A2']);
        Candidate::factory()->create([
            'class_batch_id' => $this->batchA->id,
            'last_name' => 'A3',
            'status' => CandidateStatus::Withdrawn->value,
        ]);
        $this->candidateInB = Candidate::factory()->create(['class_batch_id' => $this->batchB->id, 'last_name' => 'B1']);
        Candidate::factory()->create([
            'class_batch_id' => $this->batchOld->id,
            'last_name' => 'Old1',
            'status' => CandidateStatus::Completed->value,
        ]);
    }

    protected function offering(ClassBatch $classBatch, Subject $subject): ClassSubject
    {
        $existing = ClassSubject::query()->where('class_batch_id', $classBatch->id)->where('subject_id', $subject->id)->first();

        return $existing ?? $this->app->make(ClassBatchService::class)->addSubject($classBatch, $subject);
    }

    protected function teach(User $instructor, ClassSubject $offering): InstructorAssignment
    {
        return $this->app->make(InstructorAssignmentService::class)->assign($offering, $instructor);
    }
}
