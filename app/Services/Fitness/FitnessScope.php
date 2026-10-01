<?php

namespace App\Services\Fitness;

use App\Enums\Permission;
use App\Models\User;
use App\Support\ClassScope;

/**
 * Which classes' fitness tests a user may see and work with (ClassScope):
 * users who can view all candidates every class; instructors with fitness
 * access only the classes they teach (owner request 2026-10-02); anyone
 * else nothing. Managing a test also needs fitness.manage
 * (FitnessTestPolicy).
 */
final class FitnessScope
{
    public static function for(User $user): ClassScope
    {
        return ClassScope::for($user, Permission::ViewFitness);
    }
}
