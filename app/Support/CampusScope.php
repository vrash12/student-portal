<?php

namespace App\Support;

use App\Models\Campus;
use App\Models\Candidate;
use App\Models\ClassBatch;
use App\Models\ClassSubject;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;

/**
 * The single campus rule (owner decision 2026-10-03): which campuses a staff
 * account may see and work with.
 *
 * - An account with a campus (instructors, campus administrators) sees only
 *   that campus.
 * - An account without a campus (institution-wide administrators) sees every
 *   campus, and may narrow lists and reports to one with a campus filter.
 *
 * Every "see all" decision goes through this class: the module scopes
 * (ClassScope, MonitoringScope, ConductScope), the policies and the lists of
 * administrative pages. Permissions still decide what a user may do; this
 * only decides where. Candidate accounts have no campus here: the portal
 * shows a candidate only their own records.
 */
final readonly class CampusScope
{
    private function __construct(
        /** The only campus in the scope, or null for every campus. */
        public ?int $campusId,
        /** Whether the account itself sees every campus (before any filter). */
        private bool $institutionWide,
    ) {}

    public static function for(User $user): self
    {
        $campusId = $user->getAttribute('campus_id');

        return $campusId === null ? new self(null, true) : new self((int) $campusId, false);
    }

    /** Every campus (console commands, demo seeders, tests). */
    public static function everyCampus(): self
    {
        return new self(null, true);
    }

    /** Whether the account sees every campus (it has no campus of its own). */
    public function isInstitutionWide(): bool
    {
        return $this->institutionWide;
    }

    /** Whether the scope covers every campus (no account campus and no filter). */
    public function coversEveryCampus(): bool
    {
        return $this->campusId === null;
    }

    /** Whether a record on this campus is in the scope. Records without a campus never are, unless every campus is. */
    public function allows(?int $campusId): bool
    {
        if ($this->campusId === null) {
            return true;
        }

        return $campusId !== null && $campusId === $this->campusId;
    }

    /**
     * Whether a record (a class, candidate, staff account, grade record,
     * test, session...) belongs to a campus in the scope. Shared records
     * (subjects, academic years, settings) are in every scope.
     */
    public function allowsRecord(Model $record): bool
    {
        if ($this->campusId === null || ! AuditCampus::isCampusRecord($record)) {
            return true;
        }

        return $this->allows(AuditCampus::of($record));
    }

    /**
     * Whether class names in this scope need their campus beside them: the
     * scope spans several campuses that have classes, and those may each
     * have a class of the same name in one academic year. (All four
     * campuses always exist; one without classes changes nothing.)
     */
    public function labelsCampuses(): bool
    {
        return $this->campusId === null && ClassBatch::query()->distinct()->count('campus_id') > 1;
    }

    /** A class's name, with its campus code when the scope spans several campuses. */
    public function classLabel(string $name, ?string $campusCode, bool $labelsCampuses): string
    {
        return $labelsCampuses && $campusCode !== null ? "{$name} · {$campusCode}" : $name;
    }

    /**
     * The scope's classes of an academic period by id, labelled with
     * classLabel() (two campuses may each have a "Class A").
     *
     * @return array<int, string>
     */
    public function classLabels(int $periodId): array
    {
        $labelsCampuses = $this->labelsCampuses();

        return $this->constrain(ClassBatch::query()->where('academic_period_id', $periodId), 'class_batches.campus_id')
            ->with('campus:id,code')
            ->get(['id', 'name', 'campus_id'])
            ->mapWithKeys(fn (ClassBatch $classBatch): array => [$classBatch->id => $this->classLabel($classBatch->name, $classBatch->campus?->code, $labelsCampuses)])
            ->all();
    }

    /** Whether the class's campus is in the scope. */
    public function allowsClass(?int $classBatchId): bool
    {
        if ($this->campusId === null) {
            return true;
        }

        if ($classBatchId === null) {
            return false;
        }

        $campusId = ClassBatch::query()->whereKey($classBatchId)->value('campus_id');

        return $campusId !== null && (int) $campusId === $this->campusId;
    }

    /**
     * Narrows an institution-wide scope to one campus (a list or report
     * filter). A campus-limited scope never widens or moves; unknown campuses
     * are ignored, so an invalid filter shows the unfiltered list.
     */
    public function narrowTo(?int $campusId): self
    {
        if (! $this->institutionWide || $this->campusId !== null || $campusId === null) {
            return $this;
        }

        return Campus::query()->whereKey($campusId)->exists() ? new self($campusId, true) : $this;
    }

    /** The scope narrowed by the request's campus filter (query parameter `campus`). */
    public function filteredBy(Request $request, string $key = 'campus'): self
    {
        $requested = QueryFilters::id($request, $key);

        return $this->narrowTo($requested === '' ? null : (int) $requested);
    }

    /** The active campus filter for the page ('' when none), for list filters. */
    public function filterValue(): string
    {
        return $this->institutionWide && $this->campusId !== null ? (string) $this->campusId : '';
    }

    /**
     * Limits a query to the scope by its campus column.
     *
     * @template TQuery of Builder|QueryBuilder|Relation
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    public function constrain(Builder|QueryBuilder|Relation $query, string $column): Builder|QueryBuilder|Relation
    {
        if ($this->campusId !== null) {
            $query->where($column, $this->campusId);
        }

        return $query;
    }

    /**
     * Limits a query of records that belong to a candidate (medical
     * documents, charges, merits...) to candidates of the scope.
     *
     * @template TQuery of Builder|QueryBuilder|Relation
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    public function constrainByCandidate(Builder|QueryBuilder|Relation $query, string $column = 'candidate_id'): Builder|QueryBuilder|Relation
    {
        if ($this->campusId !== null) {
            $query->whereIn($column, Candidate::query()->select('id')->where('campus_id', $this->campusId));
        }

        return $query;
    }

    /**
     * Limits a query of records that belong to a class (fitness tests,
     * attendance sessions) to classes of the scope.
     *
     * @template TQuery of Builder|QueryBuilder|Relation
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    public function constrainByClass(Builder|QueryBuilder|Relation $query, string $column = 'class_batch_id'): Builder|QueryBuilder|Relation
    {
        if ($this->campusId !== null) {
            $query->whereIn($column, ClassBatch::query()->select('id')->where('campus_id', $this->campusId));
        }

        return $query;
    }

    /**
     * Limits a query of records that belong to a class subject
     * (assessments, examinations) to class subjects of the scope.
     *
     * @template TQuery of Builder|QueryBuilder|Relation
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    public function constrainByOffering(Builder|QueryBuilder|Relation $query, string $column = 'class_subject_id'): Builder|QueryBuilder|Relation
    {
        if ($this->campusId !== null) {
            $query->whereIn($column, ClassSubject::query()->select('id')->where('campus_id', $this->campusId));
        }

        return $query;
    }

    /**
     * Campuses that can be chosen in a list filter: every campus for an
     * institution-wide account (the filter is hidden otherwise), in the fixed
     * order South, North, East, West.
     *
     * @return list<array{id: int, name: string, code: string, isActive: bool}>
     */
    public function filterOptions(): array
    {
        if (! $this->institutionWide) {
            return [];
        }

        return self::options(Campus::query());
    }

    /**
     * Campuses new records may be placed on: active campuses in the account's
     * own scope (its campus, or every active campus).
     *
     * @return list<array{id: int, name: string, code: string, isActive: bool}>
     */
    public function assignableOptions(): array
    {
        return self::options(Campus::query()->active()->when(
            ! $this->institutionWide,
            fn (Builder $campuses) => $campuses->whereKey($this->campusId),
        ));
    }

    /** @return list<int> */
    public function assignableIds(): array
    {
        return array_column($this->assignableOptions(), 'id');
    }

    /**
     * @param  Builder<Campus>  $campuses
     * @return list<array{id: int, name: string, code: string, isActive: bool}>
     */
    private static function options(Builder $campuses): array
    {
        return $campuses
            ->get(['id', 'name', 'code', 'is_active'])
            ->sortBy(fn (Campus $campus): int => $campus->position())
            ->map(fn (Campus $campus): array => [
                'id' => $campus->id,
                'name' => $campus->name,
                'code' => $campus->code,
                'isActive' => $campus->is_active,
            ])
            ->values()
            ->all();
    }
}
