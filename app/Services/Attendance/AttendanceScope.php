<?php

namespace App\Services\Attendance;

use App\Enums\Permission;
use App\Models\AcademicPeriod;
use App\Models\Candidate;
use App\Models\ClassBatch;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which classes a user may keep attendance for, decided from permissions
 * (never role names):
 *
 * - "view all candidates" holders: every class;
 * - otherwise attendance managers who can teach: only the classes in which
 *   they currently teach a subject (User::teachesClass);
 * - anyone else: nothing.
 *
 * Every attendance page, the policy and the candidate profile start from
 * this rule, so knowing a session's URL never grants access to it.
 */
final readonly class AttendanceScope
{
    private function __construct(
        private User $user,
        /** Every class (administrative view). */
        public bool $allClasses,
        /** Only the classes the user teaches. */
        private bool $taughtClasses,
    ) {}

    public static function for(User $user): self
    {
        if ($user->hasPermission(Permission::ViewAllCandidates)) {
            return new self($user, true, false);
        }

        return new self($user, false, $user->hasPermission(Permission::ManageAttendance) && $user->canTeach());
    }

    public function isEmpty(): bool
    {
        return ! $this->allClasses && ! $this->taughtClasses;
    }

    /** Whether the user may keep attendance for (and see the attendance of) the class. */
    public function allows(int $classBatchId): bool
    {
        return $this->allClasses || ($this->taughtClasses && $this->user->teachesClass($classBatchId));
    }

    /** Whether the user may see the candidate's attendance (their current class is in scope). */
    public function allowsCandidate(Candidate $candidate): bool
    {
        return $candidate->class_batch_id !== null && $this->allows((int) $candidate->class_batch_id);
    }

    /**
     * Classes within the scope.
     *
     * @return Builder<ClassBatch>
     */
    public function classes(): Builder
    {
        return ClassBatch::query()
            ->when($this->isEmpty(), fn (Builder $classes) => $classes->whereRaw('1 = 0'))
            ->when(! $this->allClasses && $this->taughtClasses, fn (Builder $classes) => $classes->whereHas(
                'classSubjects.instructorAssignments',
                fn (Builder $assignments) => $assignments->where('instructor_id', $this->user->id),
            ));
    }

    /**
     * Academic periods with at least one class in the scope, active first,
     * then newest.
     *
     * @return list<array{id: int, name: string, isActive: bool}>
     */
    public function periods(): array
    {
        return AcademicPeriod::query()
            ->whereIn('id', $this->classes()->select('academic_period_id'))
            ->orderByDesc('is_active')
            ->orderByDesc('starts_on')
            ->orderByDesc('id')
            ->get()
            ->map(fn (AcademicPeriod $period): array => ['id' => $period->id, 'name' => $period->name, 'isActive' => $period->is_active])
            ->values()
            ->all();
    }

    /**
     * Classes in the scope grouped by academic period, active period first
     * (the shape of AcademicOptions::classBatchesByPeriod).
     *
     * @return list<array{period: string, isActive: bool, classes: list<array{id: int, name: string}>}>
     */
    public function classOptions(): array
    {
        $classes = $this->classes()->orderBy('name')->get(['id', 'name', 'academic_period_id'])->groupBy('academic_period_id');

        return AcademicPeriod::query()
            ->whereKey($classes->keys()->all())
            ->orderByDesc('is_active')
            ->orderByDesc('starts_on')
            ->orderByDesc('id')
            ->get()
            ->map(fn (AcademicPeriod $period): array => [
                'period' => $period->name,
                'isActive' => $period->is_active,
                'classes' => $classes->get($period->id)
                    ->map(fn (ClassBatch $classBatch): array => ['id' => $classBatch->id, 'name' => $classBatch->name])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }
}
