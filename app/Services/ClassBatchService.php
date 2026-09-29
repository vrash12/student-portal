<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Models\AcademicPeriod;
use App\Models\ClassBatch;
use App\Models\ClassSubject;
use App\Models\Subject;
use Illuminate\Support\Facades\DB;

/**
 * Classes / batches and the subjects they take.
 */
final class ClassBatchService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function create(AcademicPeriod $period, string $name): ClassBatch
    {
        return DB::transaction(function () use ($period, $name): ClassBatch {
            $classBatch = new ClassBatch(['name' => $name]);
            $classBatch->academicPeriod()->associate($period);
            $classBatch->save();

            $this->audit->record(AuditAction::ClassBatchCreated, $classBatch, newValues: [
                'name' => $classBatch->name,
                'academic_period' => $period->name,
            ]);

            return $classBatch;
        });
    }

    public function rename(ClassBatch $classBatch, string $name): ClassBatch
    {
        return DB::transaction(function () use ($classBatch, $name): ClassBatch {
            $before = ['name' => $classBatch->name];

            $classBatch->fill(['name' => $name])->save();

            $this->audit->recordChanges(AuditAction::ClassBatchUpdated, $classBatch, $before, ['name' => $classBatch->name]);

            return $classBatch;
        });
    }

    public function addSubject(ClassBatch $classBatch, Subject $subject): ClassSubject
    {
        return DB::transaction(function () use ($classBatch, $subject): ClassSubject {
            $offering = new ClassSubject;
            $offering->classBatch()->associate($classBatch);
            $offering->subject()->associate($subject);
            $offering->save();

            $this->audit->record(AuditAction::ClassSubjectAdded, $offering, newValues: [
                'class' => $classBatch->name,
                'subject' => $subject->name,
            ]);

            return $offering;
        });
    }

    /**
     * Removes the subject from the class together with its instructor
     * assignments. Once assessments exist (Milestone 4), offerings with
     * recorded assessments must not be removable.
     */
    public function removeSubject(ClassSubject $offering): void
    {
        DB::transaction(function () use ($offering): void {
            $offering->loadMissing(['classBatch', 'subject', 'instructors']);

            $this->audit->record(AuditAction::ClassSubjectRemoved, $offering, oldValues: [
                'class' => $offering->classBatch->name,
                'subject' => $offering->subject->name,
                'instructors' => $offering->instructors->pluck('name')->values()->all(),
            ]);

            $offering->instructorAssignments()->delete();
            $offering->delete();
        });
    }
}
