<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An instructor teaching one subject to one class. Instructor access to
 * candidates and grades is scoped by these assignments.
 *
 * campus_id is a copy of the class's campus: composite foreign keys require
 * it to equal both the class subject's and the instructor's campus, so an
 * instructor can only teach on their own campus (owner decision 2026-10-03).
 */
class InstructorAssignment extends Model
{
    protected static function booted(): void
    {
        static::creating(function (InstructorAssignment $assignment): void {
            if ($assignment->getAttribute('campus_id') === null && $assignment->getAttribute('class_subject_id') !== null) {
                $assignment->setAttribute('campus_id', ClassSubject::query()->whereKey($assignment->getAttribute('class_subject_id'))->value('campus_id'));
            }
        });
    }

    /**
     * @return BelongsTo<ClassSubject, $this>
     */
    public function classSubject(): BelongsTo
    {
        return $this->belongsTo(ClassSubject::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function instructor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'instructor_id');
    }
}
