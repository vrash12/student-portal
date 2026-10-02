<?php

namespace App\Http\Controllers\Staff;

use App\Enums\MedicalAccessStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Medical\MedicalAccessRequestForm;
use App\Models\Candidate;
use App\Models\MedicalAccessRequest;
use App\Services\Medical\MedicalAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Instructors' requests to see a full medical record (owner request,
 * 2026-10-02): instructors file and cancel them from the candidate profile;
 * medical staff list, approve (1, 7 or 30 days), reject and withdraw them.
 * Rules: MedicalAccessService.
 */
class MedicalAccessController extends Controller
{
    private const PER_PAGE = 10;

    private const FILTERS = ['pending', 'active', 'all'];

    public function __construct(private readonly MedicalAccessService $access) {}

    public function index(Request $request): Response
    {
        $requested = (string) $request->query('status', 'pending');
        $status = in_array($requested, self::FILTERS, true) ? $requested : 'pending';
        $user = $request->user();

        $requests = MedicalAccessRequest::query()
            ->when($status === 'pending', fn (Builder $query) => $query->where('status', MedicalAccessStatus::Pending->value))
            ->when($status === 'active', fn (Builder $query) => $query->active())
            ->with(['candidate.classBatch:id,name', 'requester:id,name', 'decider:id,name', 'revoker:id,name'])
            ->orderByRaw("case when status = 'pending' then 0 else 1 end")
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return Inertia::render('staff/medical/access-requests', [
            'requests' => $requests->through(fn (MedicalAccessRequest $access): array => [
                'id' => $access->id,
                'candidate' => [
                    'id' => $access->candidate->id,
                    'number' => $access->candidate->candidate_number,
                    'name' => $access->candidate->full_name,
                    'className' => $access->candidate->classBatch?->name,
                ],
                'requestedBy' => $access->requester->name,
                'requestedAt' => $access->created_at?->toIso8601String(),
                'reason' => $access->reason,
                'status' => $access->displayStatus(),
                'expiresAt' => $access->expires_at?->toIso8601String(),
                'decidedBy' => $access->decider?->name,
                'decidedAt' => $access->decided_at?->toIso8601String(),
                'decisionNote' => $access->decision_note,
                'revokedBy' => $access->revoker?->name,
                'can' => [
                    'decide' => $access->isPending() && $user->can('decide', $access),
                    'revoke' => $access->isActive() && $user->can('revoke', $access),
                ],
            ]),
            'status' => $status,
            'counts' => [
                'pending' => MedicalAccessRequest::query()->where('status', MedicalAccessStatus::Pending->value)->count(),
                'active' => MedicalAccessRequest::query()->active()->count(),
                'all' => MedicalAccessRequest::query()->count(),
            ],
            'durations' => MedicalAccessService::DURATIONS,
            'defaultDuration' => MedicalAccessService::DEFAULT_DURATION,
        ]);
    }

    public function store(MedicalAccessRequestForm $request, Candidate $candidate): RedirectResponse
    {
        $this->access->request($candidate, (string) $request->validated('reason'), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Request sent. You will see the full medical record once the medical staff approve it.']);

        return redirect()->route('candidates.show', $candidate);
    }

    public function cancel(Request $request, MedicalAccessRequest $medicalAccessRequest): RedirectResponse
    {
        $this->access->cancel($medicalAccessRequest, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Request cancelled.']);

        return redirect()->route('candidates.show', $medicalAccessRequest->candidate_id);
    }

    public function approve(MedicalAccessRequestForm $request, MedicalAccessRequest $medicalAccessRequest): RedirectResponse
    {
        $days = (int) $request->validated('days');
        $this->access->approve($medicalAccessRequest, $days, $request->note(), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => $days === 1 ? 'Access approved for 1 day.' : "Access approved for {$days} days."]);

        return back();
    }

    public function reject(MedicalAccessRequestForm $request, MedicalAccessRequest $medicalAccessRequest): RedirectResponse
    {
        $this->access->reject($medicalAccessRequest, (string) $request->note(), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Request rejected. The instructor sees your reason.']);

        return back();
    }

    public function revoke(Request $request, MedicalAccessRequest $medicalAccessRequest): RedirectResponse
    {
        $this->access->revoke($medicalAccessRequest, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Access withdrawn. The instructor sees only the shared fields again.']);

        return back();
    }
}
