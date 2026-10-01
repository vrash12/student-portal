<?php

namespace App\Services\Conduct;

use App\Enums\Permission;
use App\Models\AcademicPeriod;
use App\Models\Candidate;
use App\Models\ClassBatch;
use App\Models\ClassSubject;
use App\Models\User;
use App\Support\AcademicOptions;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which candidates a user may act on for merits and demerits, decided from
 * permissions (never role names):
 *
 * - "view all candidates" holders: every candidate;
 * - otherwise active teaching staff: only candidates whose current class
 *   they teach (User::teachesClass, checked after canTeach(), so assignments
 *   left over from a former teaching role grant nothing);
 * - anyone else: nobody.
 *
 * ConductPolicy uses covers() for single records; list pages narrow their
 * queries with constrain(), so knowing a URL never widens the scope.
 */
final readonly class ConductScope
{
    private function __construct(
        /** Every candidate (administrative view). */
        public bool $allCandidates,
        /** Only candidates of the classes this user teaches. */
        private ?User $teacher,
    ) {}

    public static function for(User $user): self
    {
        if ($user->hasPermission(Permission::ViewAllCandidates)) {
            return new self(true, null);
        }

        return new self(false, $user->canTeach() ? $user : null);
    }

    public function isEmpty(): bool
    {
        return ! $this->allCandidates && $this->teacher === null;
    }

    /**
     * "all": every candidate; "taught": the classes the user teaches;
     * "none": nobody.
     */
    public function kind(): string
    {
        return match (true) {
            $this->allCandidates => 'all',
            $this->teacher !== null => 'taught',
            default => 'none',
        };
    }

    public function covers(Candidate $candidate): bool
    {
        if ($this->allCandidates) {
            return true;
        }

        return $this->teacher !== null
            && $candidate->class_batch_id !== null
            && $this->teacher->teachesClass((int) $candidate->class_batch_id);
    }

    /**
     * Narrows a candidate query to the scope.
     *
     * @param  Builder<Candidate>  $candidates
     */
    public function constrain(Builder $candidates): void
    {
        if ($this->allCandidates) {
            return;
        }

        if ($this->teacher === null) {
            $candidates->whereRaw('1 = 0');

            return;
        }

        $candidates->whereIn('candidates.class_batch_id', $this->taughtClassIds());
    }

    /**
     * Class filter options within the scope, grouped by academic period
     * (active period first), in the shape of AcademicOptions::classBatchesByPeriod().
     *
     * @return list<array{period: string, isActive: bool, classes: list<array{id: int, name: string}>}>
     */
    public function classOptions(): array
    {
        if ($this->allCandidates) {
            return AcademicOptions::classBatchesByPeriod();
        }

        if ($this->teacher === null) {
            return [];
        }

        return AcademicPeriod::query()
            ->with(['classBatches' => fn ($classes) => $classes->whereIn('id', $this->taughtClassIds())->orderBy('name')])
            ->orderByDesc('is_active')
            ->orderByDesc('starts_on')
            ->get()
            ->filter(fn (AcademicPeriod $period): bool => $period->classBatches->isNotEmpty())
            ->map(fn (AcademicPeriod $period): array => [
                'period' => $period->name,
                'isActive' => $period->is_active,
                'classes' => $period->classBatches
                    ->map(fn (ClassBatch $classBatch): array => ['id' => $classBatch->id, 'name' => $classBatch->name])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * Subquery of the ids of the classes the teacher is assigned to.
     *
     * @return Builder<ClassSubject>
     */
    private function taughtClassIds(): Builder
    {
        $teacherId = $this->teacher?->id;

        return ClassSubject::query()
            ->select('class_batch_id')
            ->whereHas('instructorAssignments', fn (Builder $assignments) => $assignments->where('instructor_id', $teacherId));
    }
}
