<?php

namespace App\Models;

use App\Policies\ClassSubjectPolicy;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A subject taken by a class during its academic period. Instructors are
 * assigned to these offerings; grading categories and assessments belong to
 * them.
 */
#[UsePolicy(ClassSubjectPolicy::class)]
class ClassSubject extends Model
{
    /**
     * @return BelongsTo<ClassBatch, $this>
     */
    public function classBatch(): BelongsTo
    {
        return $this->belongsTo(ClassBatch::class);
    }

    /**
     * @return BelongsTo<Subject, $this>
     */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    /**
     * @return HasMany<InstructorAssignment, $this>
     */
    public function instructorAssignments(): HasMany
    {
        return $this->hasMany(InstructorAssignment::class);
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function instructors(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'instructor_assignments', 'class_subject_id', 'instructor_id')
            ->withTimestamps();
    }

    /**
     * The grading scheme: categories and their weights, in display order.
     *
     * @return HasMany<AssessmentCategory, $this>
     */
    public function assessmentCategories(): HasMany
    {
        return $this->hasMany(AssessmentCategory::class)->orderBy('position')->orderBy('id');
    }

    /**
     * @return HasMany<Assessment, $this>
     */
    public function assessments(): HasMany
    {
        return $this->hasMany(Assessment::class);
    }
}
