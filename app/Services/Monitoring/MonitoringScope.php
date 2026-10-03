<?php

namespace App\Services\Monitoring;

use App\Enums\Permission;
use App\Models\AcademicPeriod;
use App\Models\ClassSubject;
use App\Models\User;
use App\Support\CampusScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Which class subjects a user may monitor, decided from permissions (never
 * role names). The only way to build one is MonitoringScope::for() (or
 * teaching()), so every monitoring view starts from the same rule:
 *
 * - "view all candidates" holders: every class subject of the period on
 *   their campus (every campus for accounts not limited to one; CampusScope);
 * - otherwise active teaching staff: only the class subjects they are
 *   assigned to teach (checked after canTeach(), so assignments left over
 *   from a former teaching role grant nothing);
 * - anyone else: nothing.
 *
 * Filters may only narrow this set, and every monitoring value is computed
 * from it, so instructors learn nothing about subjects they do not teach.
 */
final readonly class MonitoringScope
{
    private function __construct(
        /** Every class subject of the campus scope (administrative view). */
        public bool $allSubjects,
        /** Only the class subjects taught by this user. */
        public ?int $instructorId,
        /** The campuses in view: the user's own, or every campus (optionally filtered). */
        public CampusScope $campus,
    ) {}

    public static function for(User $user): self
    {
        if ($user->hasPermission(Permission::ViewAllCandidates)) {
            return new self(true, null, $user->campusScope());
        }

        return self::teaching($user);
    }

    /**
     * The scope narrowed by the request's campus filter (accounts that see
     * every campus only; a campus-limited scope never changes).
     */
    public function filteredBy(Request $request): self
    {
        return new self($this->allSubjects, $this->instructorId, $this->campus->filteredBy($request));
    }

    /** The scope narrowed to one campus (an institution-wide report filter; null keeps it). */
    public function narrowedTo(?int $campusId): self
    {
        return new self($this->allSubjects, $this->instructorId, $this->campus->narrowTo($campusId));
    }

    /**
     * The subjects the user teaches, whatever else they may view (used for
     * the instructor's own dashboard alerts).
     */
    public static function teaching(User $user): self
    {
        return new self(false, $user->canTeach() ? $user->id : null, $user->campusScope());
    }

    public function isEmpty(): bool
    {
        return ! $this->allSubjects && $this->instructorId === null;
    }

    /**
     * "all": standings over every subject of a class; "taught": only over
     * the viewer's own subjects; "none": nothing to show.
     */
    public function kind(): string
    {
        return match (true) {
            $this->allSubjects => 'all',
            $this->instructorId !== null => 'taught',
            default => 'none',
        };
    }

    /**
     * Class subjects of the period within the scope.
     *
     * @return Builder<ClassSubject>
     */
    public function offerings(int $periodId): Builder
    {
        $offerings = ClassSubject::query()
            ->whereHas('classBatch', fn (Builder $classes) => $classes->where('academic_period_id', $periodId))
            ->when($this->isEmpty(), fn (Builder $query) => $query->whereRaw('1 = 0'))
            ->when(! $this->allSubjects && $this->instructorId !== null, fn (Builder $query) => $query->whereHas(
                'instructorAssignments',
                fn (Builder $assignments) => $assignments->where('instructor_id', $this->instructorId),
            ));

        return $this->campus->constrain($offerings, 'class_subjects.campus_id');
    }

    /**
     * Periods that can be selected, active first then newest.
     *
     * @return list<array{id: int, name: string, isActive: bool}>
     */
    public function periods(): array
    {
        if ($this->isEmpty()) {
            return [];
        }

        return AcademicPeriod::query()
            ->when(! $this->allSubjects, fn (Builder $periods) => $periods->whereHas(
                'classBatches.classSubjects.instructorAssignments',
                fn (Builder $assignments) => $assignments->where('instructor_id', $this->instructorId),
            ))
            ->orderByDesc('is_active')
            ->orderByDesc('starts_on')
            ->orderByDesc('id')
            ->get()
            ->map(fn (AcademicPeriod $period): array => ['id' => $period->id, 'name' => $period->name, 'isActive' => $period->is_active])
            ->values()
            ->all();
    }
}
