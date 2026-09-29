<?php

namespace App\Policies;

use App\Models\ClassBatch;
use App\Models\User;

/**
 * Class management itself is protected by the `class_batches.manage`
 * permission on its routes. This policy covers the read-only teaching view.
 */
class ClassBatchPolicy
{
    /**
     * Teaching staff may open a class only while they teach one of its
     * subjects.
     */
    public function viewTeaching(User $actor, ClassBatch $classBatch): bool
    {
        return $actor->teachesClass($classBatch->id);
    }
}
