<?php

namespace App\Http\Controllers\Portal;

use App\Enums\MedicalDocumentCategory;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Staff\MedicalDocumentController;
use App\Http\Requests\Medical\UploadMedicalDocumentRequest;
use App\Models\CandidateMedicalDocument;
use App\Services\Medical\MedicalDocumentService;
use App\Support\MedicalRecordPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The candidate's "My Medical Records" page (owner request, 2026-10-02):
 * upload medical certificates, check-up findings and similar documents,
 * follow their review, withdraw an upload while it waits, and read the
 * medical record fields the medical staff share with candidates. Always the
 * signed-in candidate's own records.
 */
class MedicalController extends Controller
{
    public function __construct(private readonly MedicalDocumentService $documents) {}

    public function show(Request $request): Response
    {
        $candidate = $request->user()->candidate()->firstOrFail();
        $documents = MedicalRecordPresenter::documents($candidate, 'candidate', $request->user());

        return Inertia::render('portal/medical', [
            'documents' => $documents,
            'fields' => MedicalRecordPresenter::forCandidate($candidate),
            'categories' => MedicalDocumentCategory::options(),
            'limits' => [
                'maxMb' => intdiv(MedicalDocumentService::MAX_KB, 1024),
                'maxDocuments' => MedicalDocumentService::MAX_PER_CANDIDATE,
                'remaining' => max(0, MedicalDocumentService::MAX_PER_CANDIDATE - count($documents)),
            ],
            'today' => now()->timezone(config('institution.timezone'))->toDateString(),
        ]);
    }

    public function store(UploadMedicalDocumentRequest $request): RedirectResponse
    {
        $candidate = $request->user()->candidate()->firstOrFail();
        $this->documents->upload($candidate, $request->file('file'), $request->details(), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Document uploaded. The medical staff will review it.']);

        return redirect()->route('portal.medical');
    }

    public function destroy(Request $request, CandidateMedicalDocument $medicalDocument): RedirectResponse
    {
        $this->documents->withdraw($medicalDocument, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Document withdrawn.']);

        return redirect()->route('portal.medical');
    }

    public function file(Request $request, CandidateMedicalDocument $medicalDocument): BinaryFileResponse
    {
        return MedicalDocumentController::fileResponse($this->documents, $medicalDocument, $request->boolean('download'));
    }
}
