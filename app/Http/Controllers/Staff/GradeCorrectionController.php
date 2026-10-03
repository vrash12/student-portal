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
use App\Support\PdfReport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
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

    /** The incident report of a correction request as a PDF file (Save as PDF). */
    public function pdf(Request $request, GradeCorrectionRequest $gradeCorrectionRequest): HttpResponse
    {
        $gradeCorrectionRequest->load(self::RELATIONS);
        $correction = GradeCorrectionPresenter::details($gradeCorrectionRequest);
        $max = $correction['assessment']['maxScore'];
        $score = fn (?string $value): string => $value === null ? 'No score' : "{$value} / {$max}";
        $status = $correction['status']['value'];

        $sections = [
            ['type' => 'fields', 'heading' => 'Requested Change', 'fields' => [
                ['Candidate', $correction['candidate']['name']."\n".$correction['candidate']['number']],
                ['Assessment', $correction['assessment']['title']."\n".$correction['assessment']['subject'].' · '.$correction['assessment']['className']],
                ['Requested By', $correction['requestedBy']."\n".PdfReport::dateTime($correction['requestedAt'])],
                ['Maximum Score', $max],
                ['Score When Filed', $score($correction['currentScore'])],
                ['Requested Score', $score($correction['proposedScore'])],
                ['Comment When Filed', PdfReport::value($correction['currentComment'])],
                ['Requested Comment', PdfReport::value($correction['proposedComment'])],
            ]],
            ['type' => 'fields', 'heading' => 'Incident Report', 'perRow' => 1, 'fields' => [
                ['What Happened', $correction['incidentType']['label']],
                ['Details', $correction['incidentDetails']],
            ]],
        ];
        if ($status !== GradeCorrectionStatus::Pending->value) {
            $decision = [
                [$status === GradeCorrectionStatus::Cancelled->value ? 'Cancelled By' : 'Decided By', PdfReport::value($correction['decidedBy'])."\n".PdfReport::dateTime($correction['decidedAt'])],
                ['Result', $correction['status']['label']],
            ];
            if ($correction['decisionNote'] !== null) {
                $decision[] = [$status === GradeCorrectionStatus::Rejected->value ? 'Reason for Rejection' : 'Note', $correction['decisionNote']];
            }
            $sections[] = ['type' => 'fields', 'heading' => 'Decision', 'fields' => $decision];
        }

        return PdfReport::download(
            $request->user(),
            'Grade Correction Incident Report',
            "Correction Request #{$correction['id']}",
            [['Status', $correction['status']['label']]],
            $sections,
            "incident-report-{$correction['id']}",
            reference: 'GCR-'.$correction['id'],
        );
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
     * Every request of the approver's campus (every campus when not limited
     * to one); otherwise the user's own requests and those of the subjects
     * they teach.
     *
     * @return Builder<GradeCorrectionRequest>
     */
    private function visibleTo(User $user, bool $canApprove): Builder
    {
        $query = GradeCorrectionRequest::query();
        if ($canApprove) {
            return $query->whereIn('assessment_id', $user->campusScope()->constrainByOffering(Assessment::query()->select('id')));
        }

        $taught = $user->teachingAssignments()->pluck('class_subject_id')->all();

        return $query->where(fn (Builder $visible) => $visible
            ->where('requested_by', $user->getKey())
            ->orWhereHas('assessment', fn (Builder $assessment) => $assessment->whereIn('class_subject_id', $taught)));
    }
}
