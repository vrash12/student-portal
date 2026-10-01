<?php

namespace App\Http\Controllers\Staff;

use App\Enums\CandidateStatus;
use App\Enums\ConductKind;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Conduct\ConductEntryRequest;
use App\Http\Requests\Conduct\VoidConductEntryRequest;
use App\Models\Candidate;
use App\Models\ConductEntry;
use App\Models\ConductType;
use App\Services\Conduct\ConductLedger;
use App\Services\Conduct\ConductScope;
use App\Services\Conduct\ConductService;
use App\Support\QueryFilters;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Merits and demerits (route middleware: conduct.manage). Users who can view
 * all candidates act on every candidate; others only on candidates of the
 * classes they teach (ConductScope / ConductPolicy).
 */
class ConductController extends Controller
{
    private const PER_PAGE = 10;

    public function __construct(
        private readonly ConductLedger $ledger,
        private readonly ConductService $conduct,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $scope = ConductScope::for($user);
        $filters = [
            'search' => QueryFilters::search($request),
            'class' => QueryFilters::id($request, 'class'),
        ];

        $query = Candidate::query()->with('classBatch:id,name');
        // Filters only narrow the scope; a class outside it simply lists nobody.
        $scope->constrain($query);

        $candidates = $query
            ->when($filters['search'] !== '', fn (Builder $candidates) => $candidates->matching($filters['search']))
            ->when($filters['class'] !== '', fn (Builder $candidates) => $candidates->where('class_batch_id', (int) $filters['class']))
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->orderBy('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        // One grouped query for the page.
        $totals = $this->ledger->totalsFor($candidates->getCollection()->modelKeys());

        return Inertia::render('staff/conduct/index', [
            'candidates' => $candidates->through(fn (Candidate $candidate): array => [
                'id' => $candidate->id,
                'candidateNumber' => $candidate->candidate_number,
                'name' => $candidate->full_name,
                'className' => $candidate->classBatch?->name,
                'status' => ['value' => $candidate->status->value, 'label' => $candidate->status->label(), 'tone' => $candidate->status->tone()],
                'totals' => $totals[$candidate->id],
            ]),
            'filters' => $filters,
            'classOptions' => $scope->classOptions(),
            'scope' => $scope->kind(),
            'can' => ['configureTypes' => $user->hasPermission(Permission::ConfigurePerformance)],
        ]);
    }

    public function show(Request $request, Candidate $candidate): Response
    {
        Gate::authorize('manage', [ConductEntry::class, $candidate]);

        $user = $request->user();
        $candidate->loadMissing('classBatch.academicPeriod');

        return Inertia::render('staff/conduct/show', [
            // Only what identifies the candidate.
            'candidate' => [
                'id' => $candidate->id,
                'candidateNumber' => $candidate->candidate_number,
                'name' => $candidate->full_name,
                'status' => ['value' => $candidate->status->value, 'label' => $candidate->status->label(), 'tone' => $candidate->status->tone()],
                'classBatch' => $candidate->classBatch === null ? null : [
                    'name' => $candidate->classBatch->name,
                    'period' => $candidate->classBatch->academicPeriod->name,
                ],
            ],
            'totals' => $this->ledger->totalsFor([$candidate->id])[$candidate->id],
            'entries' => $this->ledger->history($candidate),
            'types' => ConductType::query()->active()->ordered()->get()
                ->map(fn (ConductType $type): array => [
                    'id' => $type->id,
                    'name' => $type->name,
                    'kind' => $type->kind->value,
                    'defaultPoints' => $type->default_points,
                    'description' => $type->description,
                ])
                ->values()
                ->all(),
            'kinds' => array_map(fn (ConductKind $kind): array => [
                ...$kind->toArray(),
                'pluralLabel' => $kind->pluralLabel(),
            ], ConductKind::cases()),
            'today' => ConductEntryRequest::today(),
            'can' => [
                // Withdrawn candidates keep their entries but receive no new ones.
                'record' => $candidate->status !== CandidateStatus::Withdrawn,
                'viewCandidate' => $user->can('view', $candidate),
                'configureTypes' => $user->hasPermission(Permission::ConfigurePerformance),
            ],
        ]);
    }

    public function store(ConductEntryRequest $request, Candidate $candidate): RedirectResponse
    {
        $entry = $this->conduct->record($candidate, $request->entryData(), $request->user());

        $points = $entry->points === 1 ? '1 point' : "{$entry->points} points";
        Inertia::flash('toast', ['type' => 'success', 'message' => "{$entry->kind->label()} of {$points} recorded."]);

        return back();
    }

    public function void(VoidConductEntryRequest $request, ConductEntry $conductEntry): RedirectResponse
    {
        $this->conduct->void($conductEntry, (string) $request->validated('reason'), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Entry voided. It no longer counts toward the totals.']);

        return back();
    }
}
