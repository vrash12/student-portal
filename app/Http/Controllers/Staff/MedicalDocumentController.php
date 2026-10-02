<?php

namespace App\Http\Controllers\Staff;

use App\Enums\MedicalDocumentStatus;
use App\Http\Controllers\Controller;
use App\Models\CandidateMedicalDocument;
use App\Services\Medical\MedicalDocumentService;
use App\Support\MedicalRecordPresenter;
use App\Support\QueryFilters;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as Response204;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Medical documents uploaded by candidates, for staff (owner request,
 * 2026-10-02): the review queue and accept/return (medical.manage), opening
 * and downloading files (medical.view), and the protected, view-only file
 * feed for instructors with approved access.
 */
class MedicalDocumentController extends Controller
{
    private const PER_PAGE = 10;

    /** Header the protected viewer sends; a plain page visit (new tab, typed address) lacks it. */
    public const VIEWER_HEADER = 'X-Medical-Viewer';

    public function __construct(private readonly MedicalDocumentService $documents) {}

    public function index(Request $request): Response
    {
        $tab = QueryFilters::oneOf($request, 'tab', ['waiting', 'returned', 'accepted', 'all']) ?: 'waiting';
        $search = QueryFilters::search($request);

        $query = CandidateMedicalDocument::query()
            ->with(['candidate:id,candidate_number,first_name,middle_name,last_name,suffix,class_batch_id', 'candidate.classBatch:id,name', 'reviewer:id,name'])
            ->when($tab === 'waiting', fn (Builder $documents) => $documents->where('status', MedicalDocumentStatus::Submitted->value))
            ->when($tab === 'returned', fn (Builder $documents) => $documents->where('status', MedicalDocumentStatus::Returned->value))
            ->when($tab === 'accepted', fn (Builder $documents) => $documents->where('status', MedicalDocumentStatus::Accepted->value))
            ->when($search !== '', fn (Builder $documents) => $documents->whereHas('candidate', fn (Builder $candidates) => $candidates->matching($search)));

        $documents = $query
            // Waiting documents oldest first (first come, first reviewed); others newest first.
            ->when($tab === 'waiting', fn (Builder $documents) => $documents->oldest('id'), fn (Builder $documents) => $documents->latest('id'))
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (CandidateMedicalDocument $document): array => [
                ...MedicalRecordPresenter::document($document, 'staff', $request->user()),
                'candidate' => [
                    'id' => $document->candidate->id,
                    'number' => $document->candidate->candidate_number,
                    'name' => $document->candidate->full_name,
                    'className' => $document->candidate->classBatch?->name,
                ],
            ]);

        return Inertia::render('staff/medical/documents', [
            'documents' => $documents,
            'filters' => ['tab' => $tab, 'search' => $search],
            'counts' => [
                'waiting' => CandidateMedicalDocument::query()->waiting()->count(),
            ],
        ]);
    }

    /** Medical staff open (or download, with ?download=1) any document. */
    public function file(Request $request, CandidateMedicalDocument $medicalDocument): BinaryFileResponse
    {
        return self::fileResponse($this->documents, $medicalDocument, $request->boolean('download'));
    }

    /**
     * The file for the protected viewer of an instructor of the candidate's class.
     * Only the viewer's own request (with its header) gets it, so the address
     * does not open on its own in a tab with the browser's print and download
     * buttons. Every opening is audited.
     */
    public function protected(Request $request, CandidateMedicalDocument $medicalDocument): BinaryFileResponse
    {
        abort_unless($request->header(self::VIEWER_HEADER) === '1', 404);

        $this->documents->recordView($medicalDocument, $request->user());

        $path = $this->documents->absolutePath($medicalDocument);
        abort_if($path === null, 404);

        return response()->file($path, [
            'Content-Type' => $medicalDocument->mime_type,
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
            'Vary' => self::VIEWER_HEADER,
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    /** The protected viewer reports a Print Screen press; it is recorded, nothing else. */
    public function printScreen(Request $request, CandidateMedicalDocument $medicalDocument): Response204
    {
        $this->documents->recordPrintScreen($medicalDocument, $request->user());

        return response()->noContent();
    }

    public function accept(Request $request, CandidateMedicalDocument $medicalDocument): RedirectResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);
        $this->documents->accept($medicalDocument, $data['note'] ?? null, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Document accepted.']);

        return back();
    }

    public function return(Request $request, CandidateMedicalDocument $medicalDocument): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']], [
            'reason.required' => 'Tell the candidate why the document is returned.',
            'reason.min' => 'Tell the candidate why the document is returned (at least 5 characters).',
        ]);
        $this->documents->return($medicalDocument, $data['reason'], $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Document returned to the candidate with your reason.']);

        return back();
    }

    /**
     * The stored file, shown in the browser or (download) saved. Never cached.
     */
    public static function fileResponse(MedicalDocumentService $documents, CandidateMedicalDocument $document, bool $download): BinaryFileResponse
    {
        $path = $documents->absolutePath($document);
        abort_if($path === null, 404);

        $extension = MedicalDocumentService::TYPES[$document->mime_type] ?? 'bin';
        $name = (Str::slug(Str::limit($document->title, 80, '')) ?: 'medical-document').'.'.$extension;

        return response()->file($path, [
            'Content-Type' => $document->mime_type,
            'Content-Disposition' => ($download ? 'attachment' : 'inline').'; filename="'.$name.'"',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
            // The browser's PDF viewer needs object-src; nothing else may load. Images are sandboxed.
            'Content-Security-Policy' => $document->isPdf() ? "default-src 'none'; object-src 'self'; style-src 'unsafe-inline'" : "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox",
        ]);
    }
}
