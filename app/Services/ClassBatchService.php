<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Models\AcademicPeriod;
use App\Models\Campus;
use App\Models\ClassBatch;
use App\Models\ClassSubject;
use App\Models\Subject;
use App\Models\TrainingPhase;
use App\Support\DecimalValue;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Classes / batches and the subjects they take.
 */
final class ClassBatchService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** The academic period and the campus are fixed for the life of the class. */
    public function create(AcademicPeriod $period, Campus $campus, string $name): ClassBatch
    {
        return DB::transaction(function () use ($period, $campus, $name): ClassBatch {
            // Locked so a campus being deactivated or removed at the same moment waits.
            $campus = Campus::query()->lockForUpdate()->findOrFail($campus->getKey());
            if (! $campus->is_active) {
                throw ValidationException::withMessages(['campus_id' => "{$campus->name} is inactive. Choose an active campus."]);
            }

            $classBatch = new ClassBatch(['name' => $name]);
            $classBatch->academicPeriod()->associate($period);
            $classBatch->campus()->associate($campus);
            $classBatch->save();

            $this->audit->record(AuditAction::ClassBatchCreated, $classBatch, newValues: [
                'name' => $classBatch->name,
                'academic_period' => $period->name,
                'campus' => $campus->name,
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

    /**
     * @param  string  $units  the subject's weight in phase averages and the CGPA, e.g. "3" or "1.5"
     */
    public function addSubject(ClassBatch $classBatch, Subject $subject, ?TrainingPhase $phase = null, string $units = ClassSubject::DEFAULT_UNITS): ClassSubject
    {
        return DB::transaction(function () use ($classBatch, $subject, $phase, $units): ClassSubject {
            $offering = new ClassSubject;
            $offering->classBatch()->associate($classBatch);
            $offering->subject()->associate($subject);
            $offering->trainingPhase()->associate($phase);
            $offering->units = DecimalValue::normalize($units);
            $offering->save();

            $this->audit->record(AuditAction::ClassSubjectAdded, $offering, newValues: [
                'class' => $classBatch->name,
                'subject' => $subject->name,
                'phase' => $phase?->name,
                'units' => DecimalValue::display($offering->units),
            ]);

            return $offering;
        });
    }

    /**
     * Moves a subject of a class to another training phase (or none) and
     * sets its units. Grades are not stored, so phase averages and the CGPA
     * follow at once; the change is audited with the previous values.
     */
    public function updateSubject(ClassSubject $offering, ?TrainingPhase $phase, string $units): ClassSubject
    {
        return DB::transaction(function () use ($offering, $phase, $units): ClassSubject {
            $locked = ClassSubject::query()->with(['classBatch', 'subject', 'trainingPhase'])->lockForUpdate()->findOrFail($offering->getKey());
            $before = self::placement($locked);

            $locked->trainingPhase()->associate($phase);
            $locked->units = DecimalValue::normalize($units);
            $locked->save();

            $after = self::placement($locked);
            if ($after !== $before) {
                $this->audit->record(AuditAction::ClassSubjectUpdated, $locked, oldValues: $before, newValues: [
                    'class' => $locked->classBatch->name,
                    'subject' => $locked->subject->name,
                    ...$after,
                ]);
            }

            return $locked;
        });
    }

    /**
     * @return array{phase: string|null, units: string}
     */
    private static function placement(ClassSubject $offering): array
    {
        return ['phase' => $offering->trainingPhase?->name, 'units' => DecimalValue::display($offering->units)];
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
