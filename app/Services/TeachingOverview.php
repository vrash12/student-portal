<?php

namespace App\Services;

use App\Enums\CandidateStatus;
use App\Models\AcademicPeriod;
use App\Models\Assessment;
use App\Models\Candidate;
use App\Models\ClassBatch;
use App\Models\InstructorAssignment;
use App\Models\User;
use App\Services\Grading\GradingThresholds;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Read model of what an instructor teaches: their subject assignments in an
 * academic period and the enrolled candidates in those classes. Everything
 * is scoped to the instructor's own assignments.
 */
final class TeachingOverview
{
    private const UPCOMING_ASSESSMENTS_LIMIT = 5;

    /**
     * @return array{
     *     period: array{id: int, name: string, hasThresholds: bool}|null,
     *     assignments: list<array{id: int, classSubjectId: int, subject: array{code: string, name: string}, classBatch: array{id: int, name: string}, enrolledCount: int}>,
     *     totals: array{subjects: int, classes: int, enrolledCandidates: int},
     *     upcomingAssessments: list<array{id: int, title: string, assessedOn: string, subject: string, classBatch: string, status: array{value: string, label: string, tone: string}}>
     * }
     */
    public function dashboard(User $instructor): array
    {
        $period = AcademicPeriod::query()->active()->first();

        if ($period === null) {
            return [
                'period' => null,
                'assignments' => [],
                'totals' => ['subjects' => 0, 'classes' => 0, 'enrolledCandidates' => 0],
                'upcomingAssessments' => [],
            ];
        }

        $assignments = $this->assignmentsIn($instructor, $period->id);
        $classIds = $assignments->map(fn (InstructorAssignment $assignment): int => $assignment->classSubject->class_batch_id)->unique()->values();
        $enrolled = $this->enrolledCounts($classIds);

        return [
            // Academic standing (shown in gradebooks) needs the period's passing and warning grades.
            'period' => ['id' => $period->id, 'name' => $period->name, 'hasThresholds' => GradingThresholds::forPeriod($period) !== null],
            'assignments' => $assignments->map(fn (InstructorAssignment $assignment): array => [
                'id' => $assignment->id,
                'classSubjectId' => $assignment->class_subject_id,
                'subject' => [
                    'code' => $assignment->classSubject->subject->code,
                    'name' => $assignment->classSubject->subject->name,
                ],
                'classBatch' => [
                    'id' => $assignment->classSubject->classBatch->id,
                    'name' => $assignment->classSubject->classBatch->name,
                ],
                'enrolledCount' => $enrolled->get($assignment->classSubject->class_batch_id, 0),
            ])->values()->all(),
            'totals' => [
                // Distinct subjects: one subject taught to several classes counts once.
                'subjects' => $assignments->map(fn (InstructorAssignment $assignment): int => $assignment->classSubject->subject_id)->unique()->count(),
                'classes' => $classIds->count(),
                // Each candidate belongs to one class, so summing distinct classes counts distinct candidates.
                'enrolledCandidates' => (int) $classIds->sum(fn (int $classId): int => $enrolled->get($classId, 0)),
            ],
            'upcomingAssessments' => $this->upcomingAssessments(
                $assignments->map(fn (InstructorAssignment $assignment): int => $assignment->class_subject_id)->all(),
            ),
        ];
    }

    /**
     * The next dated assessments (today or later, in the institution's
     * timezone) of the given class subjects.
     *
     * @param  list<int>  $classSubjectIds
     * @return list<array{id: int, title: string, assessedOn: string, subject: string, classBatch: string, status: array{value: string, label: string, tone: string}}>
     */
    private function upcomingAssessments(array $classSubjectIds): array
    {
        if ($classSubjectIds === []) {
            return [];
        }

        $today = now()->setTimezone((string) config('institution.timezone'))->toDateString();

        return Assessment::query()
            ->with(['classSubject.subject:id,name', 'classSubject.classBatch:id,name'])
            ->whereIn('class_subject_id', $classSubjectIds)
            ->where('assessed_on', '>=', $today)
            ->orderBy('assessed_on')
            ->orderBy('title')
            ->limit(self::UPCOMING_ASSESSMENTS_LIMIT)
            ->get()
            ->map(fn (Assessment $assessment): array => [
                'id' => $assessment->id,
                'title' => $assessment->title,
                'assessedOn' => $assessment->assessed_on->toDateString(),
                'subject' => $assessment->classSubject->subject->name,
                'classBatch' => $assessment->classSubject->classBatch->name,
                'status' => $assessment->status->toArray(),
            ])
            ->values()
            ->all();
    }

    /**
     * Classes the instructor teaches in a period, with the subjects they
     * teach in each.
     *
     * @return list<array{id: int, name: string, subjects: list<array{code: string, name: string}>, enrolledCount: int}>
     */
    public function classes(User $instructor, int $periodId): array
    {
        $assignments = $this->assignmentsIn($instructor, $periodId);
        $byClass = $assignments->groupBy(fn (InstructorAssignment $assignment): int => $assignment->classSubject->class_batch_id);
        $enrolled = $this->enrolledCounts($byClass->keys());

        return $byClass
            ->map(function (Collection $classAssignments, int $classId) use ($enrolled): array {
                /** @var InstructorAssignment $first */
                $first = $classAssignments->first();

                return [
                    'id' => $classId,
                    'name' => $first->classSubject->classBatch->name,
                    'subjects' => $classAssignments
                        ->map(fn (InstructorAssignment $assignment): array => [
                            'code' => $assignment->classSubject->subject->code,
                            'name' => $assignment->classSubject->subject->name,
                        ])
                        ->sortBy('name')
                        ->values()
                        ->all(),
                    'enrolledCount' => $enrolled->get($classId, 0),
                ];
            })
            ->sortBy('name')
            ->values()
            ->all();
    }

    /**
     * Academic periods in which the instructor has assignments, active first.
     *
     * @return list<array{id: int, name: string, isActive: bool}>
     */
    public function periods(User $instructor): array
    {
        return AcademicPeriod::query()
            ->whereHas('classBatches.classSubjects.instructorAssignments', fn (Builder $assignments) => $assignments->where('instructor_id', $instructor->id))
            ->orderByDesc('is_active')
            ->orderByDesc('starts_on')
            ->get()
            ->map(fn (AcademicPeriod $period): array => ['id' => $period->id, 'name' => $period->name, 'isActive' => $period->is_active])
            ->values()
            ->all();
    }

    /**
     * Subjects of one class that the instructor teaches, with the class
     * subject id that identifies each gradebook.
     *
     * @return list<array{classSubjectId: int, code: string, name: string}>
     */
    public function subjectsTaughtIn(User $instructor, ClassBatch $classBatch): array
    {
        return $instructor->teachingAssignments()
            ->with('classSubject.subject')
            ->whereHas('classSubject', fn (Builder $offerings) => $offerings->where('class_batch_id', $classBatch->id))
            ->get()
            ->map(fn (InstructorAssignment $assignment): array => [
                'classSubjectId' => $assignment->class_subject_id,
                'code' => $assignment->classSubject->subject->code,
                'name' => $assignment->classSubject->subject->name,
            ])
            ->sortBy('name')
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, InstructorAssignment>
     */
    private function assignmentsIn(User $instructor, int $periodId): Collection
    {
        return $instructor->teachingAssignments()
            ->with(['classSubject.subject', 'classSubject.classBatch'])
            ->whereHas('classSubject.classBatch', fn (Builder $classes) => $classes->where('academic_period_id', $periodId))
            ->get()
            // Two separate keys keep each subject's classes together.
            ->sortBy([
                fn (InstructorAssignment $a, InstructorAssignment $b): int => $a->classSubject->subject->name <=> $b->classSubject->subject->name,
                fn (InstructorAssignment $a, InstructorAssignment $b): int => $a->classSubject->classBatch->name <=> $b->classSubject->classBatch->name,
            ])
            ->values();
    }

    /**
     * Enrolled candidates per class, in one grouped query.
     *
     * @param  Collection<int, int>  $classIds
     * @return Collection<int, int>
     */
    private function enrolledCounts(Collection $classIds): Collection
    {
        if ($classIds->isEmpty()) {
            return collect();
        }

        return Candidate::query()
            ->whereIn('class_batch_id', $classIds->all())
            ->where('status', CandidateStatus::Enrolled->value)
            ->groupBy('class_batch_id')
            ->selectRaw('class_batch_id, count(*) as enrolled')
            ->pluck('enrolled', 'class_batch_id')
            ->map(fn (mixed $count): int => (int) $count);
    }
}
