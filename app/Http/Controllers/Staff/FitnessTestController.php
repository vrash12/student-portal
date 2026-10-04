<?php

namespace App\Http\Controllers\Staff;

use App\Enums\CandidateStatus;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Fitness\FitnessTestRequest;
use App\Http\Requests\Fitness\RecordFitnessResultsRequest;
use App\Models\Candidate;
use App\Models\ClassBatch;
use App\Models\FitnessEvent;
use App\Models\FitnessTest;
use App\Services\Fitness\FitnessResults;
use App\Services\Fitness\FitnessScope;
use App\Services\Fitness\FitnessTestService;
use App\Services\Fitness\FitnessValue;
use App\Support\PdfReport;
use App\Support\QueryFilters;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Military fitness tests: the list and results (fitness.view), creating
 * tests and recording results (fitness.manage). Permissions are enforced by
 * route middleware; everything is scoped by class (FitnessScope,
 * FitnessTestPolicy): users who can view all candidates see every class,
 * instructors only the classes they teach.
 */
class FitnessTestController extends Controller
{
    private const PER_PAGE = 10;

    public function __construct(
        private readonly FitnessTestService $tests,
        private readonly FitnessResults $results,
    ) {}

    public function index(Request $request): Response
    {
        // The user's scope, narrowed by the campus filter for accounts that see every campus.
        $scope = FitnessScope::for($request->user())->filteredBy($request);
        $periods = $scope->periods();
        $periodIds = array_column($periods, 'id');
        $requestedPeriod = QueryFilters::id($request, 'period');
        $filters = [
            // The active (first) period by default; unknown periods show the default.
            'period' => in_array((int) $requestedPeriod, $periodIds, true) ? $requestedPeriod : (string) ($periodIds[0] ?? ''),
            'campus' => $scope->campus->filterValue(),
            'class' => QueryFilters::id($request, 'class'),
        ];

        $classes = $scope->classes()->where('academic_period_id', (int) $filters['period'])->orderBy('name')->get(['id', 'name']);
        if (! $classes->contains('id', (int) $filters['class'])) {
            $filters['class'] = '';
        }

        $tests = FitnessTest::query()
            ->whereIn('class_batch_id', $filters['class'] !== '' ? [(int) $filters['class']] : $classes->modelKeys())
            ->with('classBatch:id,name')
            ->withCount('events')
            ->orderByDesc('tested_on')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $summaries = $this->results->summaries($tests->getCollection());
        $rosterSizes = Candidate::query()
            ->whereIn('class_batch_id', $tests->getCollection()->pluck('class_batch_id')->unique()->all())
            ->where('status', '!=', CandidateStatus::Withdrawn->value)
            ->selectRaw('class_batch_id, count(*) as aggregate')
            ->groupBy('class_batch_id')
            ->pluck('aggregate', 'class_batch_id');

        return Inertia::render('staff/fitness/index', [
            'tests' => $tests->through(fn (FitnessTest $test): array => [
                'id' => $test->id,
                'title' => $test->title,
                'testedOn' => $test->tested_on->toDateString(),
                'classBatch' => ['id' => $test->classBatch->id, 'name' => $test->classBatch->name],
                'eventCount' => (int) $test->events_count,
                'rosterCount' => (int) ($rosterSizes[$test->class_batch_id] ?? 0),
                'summary' => $summaries[$test->id],
            ]),
            'filters' => $filters,
            'campusOptions' => $scope->campus->filterOptions(),
            'periods' => array_map(fn (array $period): array => ['id' => $period['id'], 'name' => $period['name']], $periods),
            'classes' => $classes->map(fn (ClassBatch $class): array => ['id' => $class->id, 'name' => $class->name])->all(),
            // "all": every class; "taught": only the classes the user teaches.
            'scope' => $scope->allClasses ? 'all' : 'taught',
            'can' => [
                'manage' => $request->user()->hasPermission(Permission::ManageFitness) && $scope->classes()->exists(),
                'configure' => $request->user()->hasPermission(Permission::ConfigureFitness),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        $scope = FitnessScope::for($request->user());
        $classOptions = $scope->classOptions();
        $requestedClass = (int) QueryFilters::id($request, 'class');
        $available = collect($classOptions)->flatMap(fn (array $group): array => array_column($group['classes'], 'id'))->all();

        return Inertia::render('staff/fitness/tests/create', [
            'classOptions' => $classOptions,
            // Pre-selects the class the list was filtered by, when the user may use it.
            'selectedClassId' => in_array($requestedClass, $available, true) ? $requestedClass : null,
            'scope' => $scope->allClasses ? 'all' : 'taught',
            'events' => FitnessEvent::query()->active()->ordered()->get()->map(function (FitnessEvent $event): array {
                $standard = $event->standard();

                return [
                    'id' => $event->id,
                    'name' => $event->name,
                    'unitLabel' => $event->unit->label(),
                    'methodLabel' => $standard->method->label(),
                    'passingPoints' => $standard->passingPoints,
                    'maximumPoints' => $standard->maximumPoints(),
                    'passingDisplay' => FitnessValue::format($standard->passingValue, $event->unit),
                    'maximumDisplay' => FitnessValue::format($standard->maximumValue, $event->unit),
                ];
            })->all(),
            'today' => now()->timezone(config('institution.timezone'))->toDateString(),
        ]);
    }

    public function store(FitnessTestRequest $request): RedirectResponse
    {
        $classBatch = ClassBatch::query()->findOrFail((int) $request->validated('class_batch_id'));
        Gate::authorize('create', [FitnessTest::class, $classBatch]);
        $test = $this->tests->create($classBatch, $request->details(), $request->eventIds(), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => "Fitness test {$test->title} created. Record the results below."]);

        return redirect()->route('fitness.tests.show', $test);
    }

    public function show(Request $request, FitnessTest $fitnessTest): Response
    {
        $fitnessTest->load(['classBatch.academicPeriod', 'creator:id,name']);
        $canManage = $request->user()->can('manage', $fitnessTest);
        $sheet = $this->results->sheet($fitnessTest);
        $hasResults = $fitnessTest->results()->exists();

        return Inertia::render('staff/fitness/tests/show', [
            'test' => [
                ...$this->details($fitnessTest),
                'createdBy' => $fitnessTest->creator->name,
            ],
            ...$sheet,
            'can' => [
                'manage' => $canManage,
                'delete' => $canManage && ! $hasResults,
                // Staff who can view all candidates, or the class's instructors (CandidatePolicy).
                'viewCandidates' => $request->user()->hasPermission(Permission::ViewAllCandidates) || $request->user()->teachesClass($fitnessTest->class_batch_id),
            ],
        ]);
    }

    /**
     * The test as a PDF (owner request 2026-10-05): the summary, each event's
     * standard and pass rate, and every candidate's results with points and
     * outcome. Figures come from FitnessResults, as on the test page.
     */
    public function pdf(Request $request, FitnessTest $fitnessTest): HttpResponse
    {
        $fitnessTest->load(['classBatch.academicPeriod', 'classBatch.campus', 'creator:id,name']);
        $sheet = $this->results->sheet($fitnessTest);
        $summary = $sheet['summary'];
        $counts = $summary['counts'];
        $points = fn (int|float|null $value): string => $value === null ? '—' : rtrim(rtrim(number_format((float) $value, 2), '0'), '.');

        $sections = [
            ['type' => 'fields', 'heading' => 'Summary', 'perRow' => 5, 'fields' => [
                ['Passed', (string) $counts['passed']],
                ['Failed', (string) $counts['failed']],
                ['Incomplete', (string) $counts['incomplete']],
                ['Not tested', (string) $counts['not_tested']],
                ['Mean overall points', $points($summary['meanPoints'])],
            ], 'note' => 'A candidate passes the test by reaching the passing points of every event. The overall points are the mean of the event points, once every event has a result.'],
            ['type' => 'table', 'heading' => 'Events', 'keep' => true, 'columns' => [
                ['label' => 'Event'], ['label' => 'Scoring', 'width' => '20%'],
                ['label' => 'Passing', 'width' => '17%', 'numeric' => true], ['label' => 'Best', 'width' => '17%', 'numeric' => true],
                ['label' => 'Passed', 'width' => '15%', 'numeric' => true],
            ], 'rows' => array_map(function (array $event, array $rate) use ($points): array {
                return [
                    $event['name'],
                    $event['method'] === 'table' ? 'Points table ('.count($event['table']).' rows)' : 'Scaled',
                    $event['passingDisplay'].' ('.$points($event['passingPoints']).' pts)',
                    $event['maximumDisplay'].' ('.$points($event['maximumPoints']).' pts)',
                    $rate['recorded'] === 0 ? '—' : $rate['passed'].' of '.$rate['recorded'],
                ];
            }, $sheet['events'], $summary['eventPassRates'])],
        ];

        $eventColumns = array_map(fn (array $event): array => ['label' => $event['name'], 'numeric' => true], $sheet['events']);
        $sections[] = [
            'type' => 'table',
            'heading' => 'Results',
            'columns' => [['label' => 'Candidate', 'width' => '11%'], ['label' => 'Name', 'width' => '20%'], ...$eventColumns, ['label' => 'Overall', 'width' => '9%', 'numeric' => true], ['label' => 'Outcome', 'width' => '11%']],
            'rows' => array_map(function (array $row) use ($sheet, $points): array {
                $cells = array_map(function (array $event) use ($row, $points): string {
                    $result = $row['results'][$event['id']] ?? null;

                    return $result === null ? '—' : $result['display']."\n".$points($result['points']).' pts'.($result['passed'] ? '' : ', below');
                }, $sheet['events']);

                return [$row['candidate']['candidateNumber'], $row['candidate']['name'], ...$cells, $points($row['outcome']['points']), $row['outcome']['status']['label']];
            }, $sheet['rows']),
            'empty' => 'No candidates in this class.',
            'note' => 'Each event shows the result, then its points; "below" marks a result short of the event\'s passing points.',
        ];
        if ($fitnessTest->notes) {
            $sections[] = ['type' => 'text', 'heading' => 'Notes', 'text' => $fitnessTest->notes];
        }

        $class = $fitnessTest->classBatch;

        return PdfReport::download(
            $request->user(),
            'Fitness Test Results',
            $fitnessTest->title,
            [
                ['Class', $class->name],
                ['Academic period', $class->academicPeriod->name],
                ['Campus', PdfReport::value($class->campus?->name)],
                ['Test date', $fitnessTest->tested_on->format('d M Y')],
                ['Created by', $fitnessTest->creator->name],
            ],
            $sections,
            'fitness-test-'.$fitnessTest->title.'-'.$class->name,
            count($sheet['events']) > 3 ? 'landscape' : 'portrait',
        );
    }

    public function edit(FitnessTest $fitnessTest): Response
    {
        $fitnessTest->load('classBatch.academicPeriod');

        return Inertia::render('staff/fitness/tests/edit', ['test' => $this->details($fitnessTest)]);
    }

    public function update(FitnessTestRequest $request, FitnessTest $fitnessTest): RedirectResponse
    {
        $this->tests->update($fitnessTest, $request->details());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Fitness test details saved.']);

        return redirect()->route('fitness.tests.show', $fitnessTest);
    }

    public function destroy(FitnessTest $fitnessTest): RedirectResponse
    {
        $this->tests->delete($fitnessTest);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Fitness test {$fitnessTest->title} deleted."]);

        return redirect()->route('fitness.index');
    }

    public function recordResults(RecordFitnessResultsRequest $request, FitnessTest $fitnessTest): RedirectResponse
    {
        $changed = $this->tests->recordResults($fitnessTest, $request->results(), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => $changed === 0
            ? 'No results changed.'
            : ($changed === 1 ? '1 result saved.' : "{$changed} results saved.")]);

        return redirect()->route('fitness.tests.show', $fitnessTest);
    }

    /**
     * @return array<string, mixed>
     */
    private function details(FitnessTest $test): array
    {
        return [
            'id' => $test->id,
            'title' => $test->title,
            'testedOn' => $test->tested_on->toDateString(),
            'notes' => $test->notes,
            'classBatch' => [
                'id' => $test->classBatch->id,
                'name' => $test->classBatch->name,
                'period' => $test->classBatch->academicPeriod->name,
            ],
        ];
    }
}
