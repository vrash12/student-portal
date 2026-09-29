<?php

namespace App\Http\Controllers\Staff;

use App\Enums\CandidateStatus;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Candidates\StoreCandidateRequest;
use App\Http\Requests\Candidates\UpdateCandidateRequest;
use App\Models\Candidate;
use App\Models\ClassSubject;
use App\Services\CandidateService;
use App\Services\Grading\GradeCalculationService;
use App\Support\AcademicOptions;
use App\Support\QueryFilters;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CandidateController extends Controller
{
    private const PER_PAGE = 25;

    public function __construct(private readonly CandidateService $candidates) {}

    public function index(Request $request): Response
    {
        $statusValues = array_map(fn (CandidateStatus $status): string => $status->value, CandidateStatus::cases());
        $filters = [
            'search' => QueryFilters::search($request),
            'class' => QueryFilters::id($request, 'class'),
            'status' => QueryFilters::oneOf($request, 'status', $statusValues),
        ];

        $candidates = Candidate::query()
            ->with('classBatch')
            ->when($filters['search'] !== '', fn (Builder $query) => $query->matching($filters['search']))
            ->when($filters['class'] !== '', fn (Builder $query) => $query->where('class_batch_id', (int) $filters['class']))
            ->when($filters['status'] !== '', fn (Builder $query) => $query->where('status', $filters['status']))
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->orderBy('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (Candidate $candidate): array => [
                'id' => $candidate->id,
                'candidateNumber' => $candidate->candidate_number,
                'name' => $candidate->full_name,
                'className' => $candidate->classBatch?->name,
                'status' => $this->status($candidate->status),
                'updatedAt' => $candidate->updated_at?->toIso8601String(),
            ]);

        return Inertia::render('staff/candidates/index', [
            'candidates' => $candidates,
            'filters' => $filters,
            'classOptions' => AcademicOptions::classBatchesByPeriod(),
            'statusOptions' => CandidateStatus::options(),
            'canCreate' => $request->user()->can('create', Candidate::class),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('staff/candidates/create', [
            'classOptions' => AcademicOptions::classBatchesByPeriod(),
        ]);
    }

    public function store(StoreCandidateRequest $request): RedirectResponse
    {
        $candidate = $this->candidates->create($request->candidateData());

        Inertia::flash('toast', ['type' => 'success', 'message' => "Candidate {$candidate->candidate_number} created."]);

        return redirect()->route('candidates.show', $candidate);
    }

    public function show(Request $request, Candidate $candidate, GradeCalculationService $grades): Response
    {
        $candidate->load(['user', 'classBatch.academicPeriod']);
        $viewer = $request->user();

        // Viewers without "view all" reach this page by teaching the candidate's
        // class, so they only see the subjects (and grades) they teach there.
        $seesAllSubjects = $viewer->hasPermission(Permission::ViewAllCandidates);

        $offerings = $candidate->classBatch === null ? collect() : ClassSubject::query()
            ->where('class_batch_id', $candidate->class_batch_id)
            ->when(! $seesAllSubjects, fn (Builder $offerings) => $offerings->whereHas(
                'instructorAssignments',
                fn (Builder $assignments) => $assignments->where('instructor_id', $viewer->id),
            ))
            ->with(['subject', 'instructors'])
            ->get()
            ->sortBy(fn (ClassSubject $offering): string => $offering->subject->name)
            ->values();

        $subjects = $offerings
            ->map(fn (ClassSubject $offering): array => [
                'code' => $offering->subject->code,
                'name' => $offering->subject->name,
                'instructors' => $offering->instructors->pluck('name')->sort()->values()->all(),
            ])
            ->all();

        // One batched calculation for all subjects, and one lookup of the
        // subjects the viewer teaches (the gradebook access rule).
        $subjectGrades = $grades->forCandidate($candidate->id, $offerings->pluck('id')->all());
        $taughtOfferingIds = $viewer->canTeach()
            ? $viewer->teachingAssignments()->pluck('class_subject_id')->map(fn (mixed $id): int => (int) $id)->all()
            : [];

        $performance = $offerings
            ->map(fn (ClassSubject $offering): array => [
                'classSubjectId' => $offering->id,
                'code' => $offering->subject->code,
                'name' => $offering->subject->name,
                'result' => $subjectGrades[$offering->id]->toArray(),
                'canOpenGradebook' => in_array($offering->id, $taughtOfferingIds, true),
            ])
            ->all();

        $canManage = $viewer->can('update', $candidate);

        return Inertia::render('staff/candidates/show', [
            'candidate' => [
                ...$this->details($candidate),
                // Sign-in account details are administrative; instructors do not need them.
                'account' => $canManage ? [
                    'username' => $candidate->user->username,
                    'isActive' => $candidate->user->is_active,
                    'lastLoginAt' => $candidate->user->last_login_at?->toIso8601String(),
                ] : null,
                'createdAt' => $candidate->created_at?->toIso8601String(),
                'updatedAt' => $candidate->updated_at?->toIso8601String(),
            ],
            'subjects' => $subjects,
            'performance' => $performance,
            'canEdit' => $canManage,
            // Instructors return to the class they teach, not the full candidate list.
            'canBrowseCandidates' => $viewer->can('viewAny', Candidate::class),
        ]);
    }

    public function edit(Candidate $candidate): Response
    {
        $candidate->load(['user', 'classBatch.academicPeriod']);

        return Inertia::render('staff/candidates/edit', [
            'candidate' => [
                ...$this->details($candidate),
                'accountActive' => $candidate->user->is_active,
                'username' => $candidate->user->username,
            ],
            'classOptions' => AcademicOptions::classBatchesByPeriod(),
            'statusOptions' => CandidateStatus::options(),
        ]);
    }

    public function update(UpdateCandidateRequest $request, Candidate $candidate): RedirectResponse
    {
        $this->candidates->update($candidate, $request->candidateData());

        Inertia::flash('toast', ['type' => 'success', 'message' => "Candidate {$candidate->candidate_number} updated."]);

        return redirect()->route('candidates.show', $candidate);
    }

    /**
     * @return array{id: int, candidateNumber: string, firstName: string, lastName: string, name: string, status: array{value: string, label: string, tone: string}, classBatch: array{id: int, name: string, period: string}|null}
     */
    private function details(Candidate $candidate): array
    {
        return [
            'id' => $candidate->id,
            'candidateNumber' => $candidate->candidate_number,
            'firstName' => $candidate->first_name,
            'lastName' => $candidate->last_name,
            'name' => $candidate->full_name,
            'status' => $this->status($candidate->status),
            'classBatch' => $candidate->classBatch === null ? null : [
                'id' => $candidate->classBatch->id,
                'name' => $candidate->classBatch->name,
                'period' => $candidate->classBatch->academicPeriod->name,
            ],
        ];
    }

    /**
     * @return array{value: string, label: string, tone: string}
     */
    private function status(CandidateStatus $status): array
    {
        return ['value' => $status->value, 'label' => $status->label(), 'tone' => $status->tone()];
    }
}
