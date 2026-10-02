<?php

namespace App\Http\Controllers\Staff;

use App\Enums\GradeCorrectionStatus;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Grading\DecideGradeCorrectionRequest;
use App\Http\Requests\Grading\StoreGradeCorrectionRequest;
use App\Models\Assessment;
use App\Models\GradeCorrectionRequest;
use App\Models\User;
use App\Services\Grading\GradeCorrectionService;
use App\Support\DecimalValue;
use App\Support\GradeCorrectionPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Grade correction requests (owner request, 2026-10-02): instructors file
 * them from a finalized assessment; administrators approve or reject them.
 * Authorization: GradeCorrectionRequestPolicy (and AssessmentPolicy::manage
 * for filing) on the routes; the rules live in GradeCorrectionService.
 */
class GradeCorrectionController extends Controller
{
    private const PER_PAGE = 10;

    private const RELATIONS = [
        'assessment.classSubject.subject:id,name',
        'assessment.classSubject.classBatch:id,name',
        'candidate',
        'requester:id,name',
        'decider:id,name',
    ];

    public function __construct(private readonly GradeCorrectionService $corrections) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $canApprove = $user->hasPermission(Permission::ApproveGradeCorrections);
        $requested = (string) $request->query('status', '');
        // Approvers start on the requests waiting for them; instructors on all of theirs.
        $status = GradeCorrectionStatus::tryFrom($requested)?->value ?? ($requested === 'all' ? 'all' : ($canApprove ? GradeCorrectionStatus::Pending->value : 'all'));

        $visible = $this->visibleTo($user, $canApprove);
        $counts = (clone $visible)->toBase()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        $requests = (clone $visible)
            ->when($status !== 'all', fn (Builder $query) => $query->where('status', $status))
            ->with(self::RELATIONS)
            // Pending first (oldest waiting longest on top), then the latest decisions.
            ->orderByRaw("case when status = 'pending' then 0 else 1 end")
            ->orderByRaw("case when status = 'pending' then created_at end asc")
            ->orderByDesc('decided_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return Inertia::render('staff/grade-corrections/index', [
            'requests' => $requests->through(fn (GradeCorrectionRequest $correction): array => GradeCorrectionPresenter::summary($correction)),
            'status' => $status,
            'counts' => [
                ...collect(GradeCorrectionStatus::cases())->mapWithKeys(fn (GradeCorrectionStatus $case): array => [$case->value => (int) ($counts[$case->value] ?? 0)])->all(),
                'all' => (int) $counts->sum(),
            ],
            'statuses' => GradeCorrectionStatus::options(),
            'scope' => $canApprove ? 'all' : 'own',
        ]);
    }

    public function show(Request $request, GradeCorrectionRequest $gradeCorrectionRequest): Response
    {
        $gradeCorrectionRequest->load(self::RELATIONS);
        $user = $request->user();
        $score = $gradeCorrectionRequest->assessment->scores()->where('candidate_id', $gradeCorrectionRequest->candidate_id)->first();

        return Inertia::render('staff/grade-corrections/show', [
            'correction' => [
                ...GradeCorrectionPresenter::details($gradeCorrectionRequest),
                // The score as it is now, so a change since filing is visible before deciding.
                'scoreNow' => $score?->score === null ? null : DecimalValue::display($score->score),
                'commentNow' => $score?->comment,
            ],
            'can' => [
                'decide' => $gradeCorrectionRequest->isPending() && $user->can('decide', $gradeCorrectionRequest),
                'cancel' => $gradeCorrectionRequest->isPending() && $user->can('cancel', $gradeCorrectionRequest),
                'openAssessment' => $user->can('view', $gradeCorrectionRequest->assessment),
                'ownRequest' => (int) $gradeCorrectionRequest->requested_by === (int) $user->getKey(),
            ],
        ]);
    }

    public function store(StoreGradeCorrectionRequest $request, Assessment $assessment): RedirectResponse
    {
        $correction = $this->corrections->request(
            $assessment,
            $request->integer('candidate_id'),
            $request->scoreValue(),
            $request->commentValue(),
            $request->incidentType(),
            (string) $request->validated('incident_details'),
            $request->expectedScore(),
            $request->expectedComment(),
            $request->user(),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => "Correction request #{$correction->id} sent. The score changes only after an administrator approves it."]);

        return redirect()->route('assessments.show', $assessment);
    }

    public function approve(DecideGradeCorrectionRequest $request, GradeCorrectionRequest $gradeCorrectionRequest): RedirectResponse
    {
        $this->corrections->approve($gradeCorrectionRequest, $request->note(), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Correction approved. The score was changed and the change recorded.']);

        return redirect()->route('grade-corrections.show', $gradeCorrectionRequest);
    }

    public function reject(DecideGradeCorrectionRequest $request, GradeCorrectionRequest $gradeCorrectionRequest): RedirectResponse
    {
        $this->corrections->reject($gradeCorrectionRequest, (string) $request->note(), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Correction rejected. The score was not changed.']);

        return redirect()->route('grade-corrections.show', $gradeCorrectionRequest);
    }

    public function cancel(Request $request, GradeCorrectionRequest $gradeCorrectionRequest): RedirectResponse
    {
        $this->corrections->cancel($gradeCorrectionRequest, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Correction request cancelled.']);

        return redirect()->route('grade-corrections.show', $gradeCorrectionRequest);
    }

    /**
     * Every request for approvers; otherwise the user's own requests and
     * those of the subjects they teach.
     *
     * @return Builder<GradeCorrectionRequest>
     */
    private function visibleTo(User $user, bool $canApprove): Builder
    {
        $query = GradeCorrectionRequest::query();
        if ($canApprove) {
            return $query;
        }

        $taught = $user->teachingAssignments()->pluck('class_subject_id')->all();

        return $query->where(fn (Builder $visible) => $visible
            ->where('requested_by', $user->getKey())
            ->orWhereHas('assessment', fn (Builder $assessment) => $assessment->whereIn('class_subject_id', $taught)));
    }
}
