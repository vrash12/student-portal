<?php

namespace App\Http\Controllers\Staff;

use App\Enums\AcademicStanding;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\AcademicPeriod;
use App\Models\Candidate;
use App\Models\ClassSubject;
use App\Services\Grading\GradingThresholds;
use App\Services\Monitoring\AcademicMonitoring;
use App\Services\Monitoring\MonitoredCandidate;
use App\Services\Monitoring\MonitoringPresenter;
use App\Services\Monitoring\MonitoringScope;
use App\Support\QueryFilters;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Academic monitoring: candidates requiring attention in a period, with
 * counts by standing (MILESTONES.md Milestone 6). What a user sees is decided
 * by MonitoringScope; every filter only narrows it, and filter values outside
 * the scope are ignored.
 */
class AcademicMonitoringController extends Controller
{
    private const PER_PAGE = 25;

    public function __construct(
        private readonly AcademicMonitoring $monitoring,
        private readonly MonitoringPresenter $presenter,
    ) {}

    public function __invoke(Request $request): Response
    {
        $viewer = $request->user();
        $scope = MonitoringScope::for($viewer);

        // The requested period if selectable, else the active one if
        // selectable (listed first), else the most recent.
        $periods = $scope->periods();
        $periodIds = array_column($periods, 'id');
        $requested = QueryFilters::id($request, 'period');
        $periodId = $requested !== '' && in_array((int) $requested, $periodIds, true) ? (int) $requested : ($periodIds[0] ?? null);
        $period = $periodId === null ? null : AcademicPeriod::query()->findOrFail($periodId);

        /** @var Collection<int, ClassSubject> $scoped */
        $scoped = $period === null
            ? new Collection
            : $scope->offerings($period->id)->with(['subject:id,code,name', 'classBatch.academicPeriod'])->get();

        $classOptions = $this->classOptions($scoped);
        $class = $this->allowedId($request, 'class', array_column($classOptions, 'id'));
        // Subject options follow the selected class.
        $subjectOptions = $this->subjectOptions($class === '' ? $scoped : $scoped->where('class_batch_id', (int) $class));
        $subject = $this->allowedId($request, 'subject', array_column($subjectOptions, 'id'));

        $filters = [
            'period' => $requested !== '' && $periodId === (int) $requested ? $requested : '',
            'class' => $class,
            'subject' => $subject,
            'standing' => QueryFilters::oneOf($request, 'standing', AcademicMonitoring::STANDING_FILTERS),
            'search' => QueryFilters::search($request),
            'sort' => QueryFilters::oneOf($request, 'sort', AcademicMonitoring::SORTS),
        ];

        $offerings = $scoped
            ->when($class !== '', fn (Collection $offerings) => $offerings->where('class_batch_id', (int) $class))
            ->when($subject !== '', fn (Collection $offerings) => $offerings->where('subject_id', (int) $subject))
            ->values();

        $monitored = $this->monitoring->evaluate($offerings);
        $thresholds = $period === null ? null : GradingThresholds::forPeriod($period);
        // Without thresholds there is no standing to filter or sort by.
        $standing = $thresholds === null ? '' : $filters['standing'];
        $sort = $thresholds === null && $filters['sort'] === '' ? 'lowest' : $filters['sort'];

        $rows = $this->monitoring->sort(
            $this->monitoring->filter($monitored, $standing, $this->matchingIds($monitored, $filters['search'])),
            $sort,
        );

        $page = LengthAwarePaginator::resolveCurrentPage();
        $taught = $viewer->canTeach()
            ? $viewer->teachingAssignments()->pluck('class_subject_id')->map(fn (mixed $id): int => (int) $id)->all()
            : [];
        $subjectView = $subject !== '';

        // Pages past the end are empty. Not slicing them also keeps huge page
        // numbers from overflowing the offset.
        $lastPage = max(1, (int) ceil(count($rows) / self::PER_PAGE));
        $pageRows = $page > $lastPage ? collect() : collect($rows)->forPage($page, self::PER_PAGE)->values();

        $candidates = (new LengthAwarePaginator(
            $pageRows,
            count($rows),
            self::PER_PAGE,
            $page,
            ['path' => $request->url()],
        ))->withQueryString()->through(fn (MonitoredCandidate $entry): array => $this->presenter->row($entry, $subjectView, $taught));

        return Inertia::render('staff/monitoring/index', [
            'period' => $period === null ? null : ['id' => $period->id, 'name' => $period->name, 'isActive' => $period->is_active],
            'periods' => $periods,
            'scope' => $scope->kind(),
            'thresholds' => $thresholds?->toArray(),
            'canConfigureThresholds' => $viewer->hasPermission(Permission::ConfigureGrading),
            'counts' => $this->monitoring->counts($monitored),
            'subjectsRequiringAttention' => $subjectView ? [] : $this->monitoring->subjectsRequiringAttention($monitored),
            'filters' => $filters,
            'classOptions' => $classOptions,
            'subjectOptions' => $subjectOptions,
            'standingOptions' => $this->standingOptions(),
            'view' => $subjectView ? 'subject' : 'overall',
            'singleClass' => count($classOptions) === 1 || $class !== '',
            'candidates' => $candidates,
        ]);
    }

    /**
     * Ids of monitored candidates matching the search, with the same
     * database search as every other candidate list; null without a search.
     *
     * @param  list<MonitoredCandidate>  $monitored
     * @return list<int>|null
     */
    private function matchingIds(array $monitored, string $search): ?array
    {
        if ($search === '') {
            return null;
        }

        $ids = array_map(fn (MonitoredCandidate $entry): int => $entry->candidate->id, $monitored);

        return $ids === [] ? [] : Candidate::query()
            ->whereKey($ids)
            ->matching($search)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * @param  Collection<int, ClassSubject>  $offerings
     * @return list<array{id: int, name: string}>
     */
    private function classOptions(Collection $offerings): array
    {
        return $offerings
            ->map(fn (ClassSubject $offering): array => ['id' => $offering->classBatch->id, 'name' => $offering->classBatch->name])
            ->unique('id')
            ->sortBy('name')
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, ClassSubject>  $offerings
     * @return list<array{id: int, code: string, name: string}>
     */
    private function subjectOptions(Collection $offerings): array
    {
        return $offerings
            ->map(fn (ClassSubject $offering): array => ['id' => $offering->subject->id, 'code' => $offering->subject->code, 'name' => $offering->subject->name])
            ->unique('id')
            ->sortBy('name')
            ->values()
            ->all();
    }

    /**
     * @param  list<int>  $allowed
     */
    private function allowedId(Request $request, string $key, array $allowed): string
    {
        $value = QueryFilters::id($request, $key);

        return $value !== '' && in_array((int) $value, $allowed, true) ? $value : '';
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function standingOptions(): array
    {
        return [
            ...array_map(
                fn (AcademicStanding $standing): array => ['value' => $standing->value, 'label' => $standing->label()],
                [AcademicStanding::Failing, AcademicStanding::AtRisk, AcademicStanding::Incomplete, AcademicStanding::Passing],
            ),
            ['value' => 'none', 'label' => 'No Standing Yet'],
        ];
    }
}
