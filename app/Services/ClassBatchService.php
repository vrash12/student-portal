<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Models\AcademicPeriod;
use App\Models\ClassBatch;
use App\Models\ClassSubject;
use App\Models\Subject;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
     * assignments and grading categories. A subject that has assessments
     * cannot be removed, because its grades are academic history.
     *
     * @throws ValidationException
     */
    public function removeSubject(ClassSubject $offering): void
    {
        DB::transaction(function () use ($offering): void {
            // Locking the offering blocks assessments from being created for
            // it until the removal is complete.
            $locked = ClassSubject::query()->with(['classBatch', 'subject', 'instructors', 'assessmentCategories'])->lockForUpdate()->findOrFail($offering->getKey());

            if ($locked->assessments()->exists()) {
                throw ValidationException::withMessages([
                    'offering' => "{$locked->subject->name} has assessments in {$locked->classBatch->name} and cannot be removed. Its grades are part of the academic record.",
                ]);
            }

            if ($locked->examinations()->exists()) {
                throw ValidationException::withMessages(['offering' => 'This subject has quizzes or examinations. Keep the class assignment to preserve its examination history.']);
            }

            $this->audit->record(AuditAction::ClassSubjectRemoved, $locked, oldValues: [
                'class' => $locked->classBatch->name,
                'subject' => $locked->subject->name,
                'instructors' => $locked->instructors->pluck('name')->values()->all(),
                'grading_categories' => $locked->assessmentCategories->pluck('name')->values()->all(),
            ]);

            $locked->instructorAssignments()->delete();
            $locked->assessmentCategories()->delete();
            $locked->delete();
        });
    }
}
