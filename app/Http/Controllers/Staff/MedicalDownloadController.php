<?php

namespace App\Http\Controllers\Staff;

use App\Enums\MedicalDownloadStatus;
use App\Http\Controllers\Controller;
use App\Models\CandidateMedicalDocument;
use App\Models\MedicalDownloadRequest;
use App\Services\Medical\MedicalDocumentService;
use App\Services\Medical\MedicalDownloadService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Instructors' requests to download an uploaded medical document (owner
 * request, 2026-10-02): instructors file and cancel them from the document
 * list of a record they may view, and download with an active approval;
 * medical staff list, approve, reject and withdraw them. Rules:
 * MedicalDownloadService.
 */
class MedicalDownloadController extends Controller
{
    private const PER_PAGE = 10;

    private const FILTERS = ['pending', 'active', 'all'];

    public function __construct(private readonly MedicalDownloadService $downloads) {}

    public function index(Request $request): Response
    {
        $requested = (string) $request->query('status', 'pending');
        $status = in_array($requested, self::FILTERS, true) ? $requested : 'pending';
        $user = $request->user();

        $requests = MedicalDownloadRequest::query()
            ->when($status === 'pending', fn (Builder $query) => $query->where('status', MedicalDownloadStatus::Pending->value))
            ->when($status === 'active', fn (Builder $query) => $query->active())
            ->with(['document:id,candidate_id,title,category,mime_type', 'candidate.classBatch:id,name', 'requester:id,name', 'decider:id,name', 'revoker:id,name'])
            ->orderByRaw("case when status = 'pending' then 0 else 1 end")
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return Inertia::render('staff/medical/download-requests', [
            'requests' => $requests->through(fn (MedicalDownloadRequest $download): array => [
                'id' => $download->id,
                'candidate' => [
                    'id' => $download->candidate->id,
                    'number' => $download->candidate->candidate_number,
                    'name' => $download->candidate->full_name,
                    'className' => $download->candidate->classBatch?->name,
                ],
                'document' => [
                    'id' => $download->document->id,
                    'title' => $download->document->title,
                    'category' => $download->document->category->label(),
                    'fileUrl' => route('medical.documents.file', $download->document, false),
                ],
                'requestedBy' => $download->requester->name,
                'requestedAt' => $download->created_at?->toIso8601String(),
                'reason' => $download->reason,
                'status' => $download->displayStatus(),
                'expiresAt' => $download->expires_at?->toIso8601String(),
                'decidedBy' => $download->decider?->name,
                'decisionNote' => $download->decision_note,
                'revokedBy' => $download->revoker?->name,
                'downloadCount' => $download->download_count,
                'lastDownloadedAt' => $download->last_downloaded_at?->toIso8601String(),
                'can' => [
                    'decide' => $download->isPending() && $user->can('decide', $download),
                    'revoke' => $download->isActive() && $user->can('revoke', $download),
                ],
            ]),
            'status' => $status,
            'counts' => [
                'pending' => MedicalDownloadRequest::query()->where('status', MedicalDownloadStatus::Pending->value)->count(),
                'active' => MedicalDownloadRequest::query()->active()->count(),
                'all' => MedicalDownloadRequest::query()->count(),
            ],
            'days' => MedicalDownloadService::DAYS,
        ]);
    }

    public function store(Request $request, CandidateMedicalDocument $medicalDocument): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:'.MedicalDownloadService::REASON_MIN, 'max:'.MedicalDownloadService::REASON_MAX]], [
            'reason.required' => 'Explain why you need a copy of this document.',
            'reason.min' => 'Explain why in at least '.MedicalDownloadService::REASON_MIN.' characters.',
            'reason.max' => 'Use at most '.MedicalDownloadService::REASON_MAX.' characters.',
        ]);
        $this->downloads->request($medicalDocument, $data['reason'], $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Download requested. You can download the document once the medical staff approve it.']);

        return back();
    }

    public function cancel(Request $request, MedicalDownloadRequest $medicalDownloadRequest): RedirectResponse
    {
        $this->downloads->cancel($medicalDownloadRequest, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Download request cancelled.']);

        return back();
    }

    public function approve(Request $request, MedicalDownloadRequest $medicalDownloadRequest): RedirectResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);
        $this->downloads->approve($medicalDownloadRequest, $data['note'] ?? null, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Download approved for '.MedicalDownloadService::DAYS.' days.']);

        return back();
    }

    public function reject(Request $request, MedicalDownloadRequest $medicalDownloadRequest): RedirectResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:1000']], [
            'note.required' => 'Explain why the request is rejected. The instructor will see this.',
        ]);
        $this->downloads->reject($medicalDownloadRequest, $data['note'], $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Download request rejected. The instructor sees your reason.']);

        return back();
    }

    public function revoke(Request $request, MedicalDownloadRequest $medicalDownloadRequest): RedirectResponse
    {
        $this->downloads->revoke($medicalDownloadRequest, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Download withdrawn.']);

        return back();
    }

    /** The approved instructor's copy: always an attachment, never cached. */
    public function file(Request $request, MedicalDownloadRequest $medicalDownloadRequest): BinaryFileResponse
    {
        $path = $this->downloads->download($medicalDownloadRequest, $request->user());
        $document = $medicalDownloadRequest->document;
        $extension = MedicalDocumentService::TYPES[$document->mime_type] ?? 'bin';
        $name = (Str::slug(Str::limit($document->title, 80, '')) ?: 'medical-document').'.'.$extension;

        return response()->file($path, [
            'Content-Type' => $document->mime_type,
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }
}
