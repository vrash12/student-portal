<?php

namespace App\Support;

use App\Enums\Permission;
use App\Models\AcademicPeriod;
use App\Models\Candidate;
use App\Models\ClassBatch;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Which classes a user may work with in a class-based module (attendance,
 * military fitness), decided from permissions (never role names):
 *
 * - "view all candidates" holders: every class of their campus (every
 *   campus for accounts not limited to one; CampusScope);
 * - otherwise holders of the module's permission who can teach: only the
 *   classes in which they currently teach a subject (User::teachesClass);
 * - anyone else: nothing.
 *
 * Built through the module's own scope (AttendanceScope, FitnessScope), so
 * every page, policy and the candidate profile use the same rule and
 * knowing a record's URL never grants access to it.
 */
final readonly class ClassScope
{
    private function __construct(
        private User $user,
        /** Every class of the campus scope (administrative view). */
        public bool $allClasses,
        /** Only the classes the user teaches. */
        private bool $taughtClasses,
        /** The campuses in view: the user's own, or every campus (optionally filtered). */
        public CampusScope $campus,
    ) {}

    /** $permission: the module permission that gives teaching staff the classes they teach. */
    public static function for(User $user, Permission $permission): self
    {
        if ($user->hasPermission(Permission::ViewAllCandidates)) {
            return new self($user, true, false, $user->campusScope());
        }

        return new self($user, false, $user->hasPermission($permission) && $user->canTeach(), $user->campusScope());
    }

    /**
     * The scope narrowed by the request's campus filter (accounts that see
     * every campus only; a campus-limited scope never changes).
     */
    public function filteredBy(Request $request): self
    {
        return new self($this->user, $this->allClasses, $this->taughtClasses, $this->campus->filteredBy($request));
    }

    public function isEmpty(): bool
    {
        return ! $this->allClasses && ! $this->taughtClasses;
    }

    /** Whether the class is in the scope. */
    public function allows(int $classBatchId): bool
    {
        if ($this->allClasses) {
            return $this->campus->allowsClass($classBatchId);
        }

        return $this->taughtClasses && $this->user->teachesClass($classBatchId) && $this->campus->allowsClass($classBatchId);
    }

    /** Whether the candidate's current class is in the scope. */
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
        $classes = ClassBatch::query()
            ->when($this->isEmpty(), fn (Builder $classes) => $classes->whereRaw('1 = 0'))
            ->when(! $this->allClasses && $this->taughtClasses, fn (Builder $classes) => $classes->whereHas(
                'classSubjects.instructorAssignments',
                fn (Builder $assignments) => $assignments->where('instructor_id', $this->user->id),
            ));

        return $this->campus->constrain($classes, 'class_batches.campus_id');
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
        $classes = $this->classes()->with('campus:id,code')->orderBy('name')->get(['id', 'name', 'academic_period_id', 'campus_id'])->groupBy('academic_period_id');
        $labelsCampuses = $this->campus->labelsCampuses();

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
                    ->map(fn (ClassBatch $classBatch): array => ['id' => $classBatch->id, 'name' => $this->campus->classLabel($classBatch->name, $classBatch->campus?->code, $labelsCampuses)])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }
}
