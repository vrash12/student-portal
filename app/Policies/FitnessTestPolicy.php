<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\ClassBatch;
use App\Models\FitnessTest;
use App\Models\User;
use App\Services\Fitness\FitnessScope;

/**
 * Fitness tests are kept by class (FitnessScope): users who can view all
 * candidates act on every class, instructors only on the classes they
 * teach. A test of another class is forbidden even when its URL is known.
 */
class FitnessTestPolicy
{
    /** See the test and its results. */
    public function view(User $actor, FitnessTest $test): bool
    {
        return $this->allows($actor, Permission::ViewFitness, $test->class_batch_id);
    }

    /** Edit or delete the test and record its results. */
    public function manage(User $actor, FitnessTest $test): bool
    {
        return $this->allows($actor, Permission::ManageFitness, $test->class_batch_id);
    }

    /** Create a test for the class. */
    public function create(User $actor, ClassBatch $classBatch): bool
    {
        return $this->allows($actor, Permission::ManageFitness, $classBatch->id);
    }

    private function allows(User $actor, Permission $permission, int $classBatchId): bool
    {
        return $actor->hasPermission($permission) && FitnessScope::for($actor)->allows($classBatchId);
    }
}
