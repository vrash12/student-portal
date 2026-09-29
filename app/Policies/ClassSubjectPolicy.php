<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\ClassSubject;
use App\Models\User;

/**
 * Grading of one subject of one class. Instructors are limited to the
 * subjects they are assigned to teach; knowing a URL never grants access.
 * Whether an action is allowed in the current state (draft or finalized) is
 * decided by the grading services, not here.
 */
class ClassSubjectPolicy
{
    /**
     * Open the gradebook: assigned teaching staff only.
     */
    public function viewGradebook(User $actor, ClassSubject $offering): bool
    {
        return $actor->teachesOffering($offering->id);
    }

    /**
     * Create assessments and record, finalize, and correct scores.
     */
    public function recordGrades(User $actor, ClassSubject $offering): bool
    {
        return $actor->hasPermission(Permission::RecordGrades) && $actor->teachesOffering($offering->id);
    }

    /**
     * Set the grading categories and weights (administrative).
     */
    public function configureGrading(User $actor, ClassSubject $offering): bool
    {
        return $actor->hasPermission(Permission::ConfigureGrading);
    }
}
