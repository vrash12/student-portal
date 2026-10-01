<?php

namespace App\Http\Controllers\Staff;

use App\Enums\CandidateStatus;
use App\Http\Controllers\Controller;
use App\Models\Candidate;
use App\Models\ClassBatch;
use App\Services\TeachingOverview;
use App\Support\QueryFilters;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "My Classes": the read-only teaching view of the classes an instructor is
 * assigned to. Class management stays with administrators.
 */
class TeachingClassController extends Controller
{
    private const CANDIDATES_PER_PAGE = 10;

    public function __construct(private readonly TeachingOverview $teaching) {}

    public function index(Request $request): Response
    {
        $instructor = $request->user();
        $periods = $this->teaching->periods($instructor);
        $periodIds = array_column($periods, 'id');

        // Only periods in which the instructor teaches can be selected.
        $requested = QueryFilters::id($request, 'period');
        $periodId = $requested !== '' && in_array((int) $requested, $periodIds, true)
            ? (int) $requested
            : ($periodIds[0] ?? null);

        return Inertia::render('staff/teaching/classes/index', [
            'classes' => $periodId === null ? [] : $this->teaching->classes($instructor, $periodId),
            'periods' => $periods,
            'filters' => ['period' => $periodId === null ? '' : (string) $periodId],
        ]);
    }

    public function show(Request $request, ClassBatch $classBatch): Response
    {
        $instructor = $request->user();
        $classBatch->load('academicPeriod');

        $statusValues = array_map(fn (CandidateStatus $status): string => $status->value, CandidateStatus::cases());
        $filters = [
            'search' => QueryFilters::search($request),
            'status' => QueryFilters::oneOf($request, 'status', $statusValues),
        ];

        $candidates = $classBatch->candidates()
            ->when($filters['search'] !== '', fn (Builder $query) => $query->matching($filters['search']))
            ->when($filters['status'] !== '', fn (Builder $query) => $query->where('status', $filters['status']))
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->orderBy('id')
            ->paginate(self::CANDIDATES_PER_PAGE)
            ->withQueryString()
            ->through(fn (Candidate $candidate): array => [
                'id' => $candidate->id,
                'candidateNumber' => $candidate->candidate_number,
                'name' => $candidate->full_name,
                'status' => [
                    'value' => $candidate->status->value,
                    'label' => $candidate->status->label(),
                    'tone' => $candidate->status->tone(),
                ],
            ]);

        return Inertia::render('staff/teaching/classes/show', [
            'classBatch' => [
                'id' => $classBatch->id,
                'name' => $classBatch->name,
                'period' => [
                    'name' => $classBatch->academicPeriod->name,
                    'isActive' => $classBatch->academicPeriod->is_active,
                ],
            ],
            'subjects' => $this->teaching->subjectsTaughtIn($instructor, $classBatch),
            'candidates' => $candidates,
            'filters' => $filters,
            'statusOptions' => CandidateStatus::options(),
        ]);
    }
}
