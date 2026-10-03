<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Enums\Permission;
use App\Models\Candidate;
use App\Services\AuditLogger;
use App\Support\CandidateQrCode;
use App\Support\PdfDocument;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * Candidates' QR codes (owner request, 2026-10-02): the image on the staff
 * profile and on the candidate's own My Information page, reissuing a code
 * (the old one stops working), and the address inside every code, which
 * leads signed-in staff to the candidate's profile.
 */
class CandidateQrController extends Controller
{
    /**
     * /q/{token}: staff who may see the candidate go to the profile, the
     * candidate to their own My Information; anyone else gets "not found"
     * (a code says nothing about whose it is).
     */
    public function open(Request $request, string $token): RedirectResponse
    {
        $candidate = CandidateQrCode::find($token);
        abort_if($candidate === null, 404);
        $user = $request->user();

        if ($user->hasPermission(Permission::AccessStaffArea) && $user->can('view', $candidate)) {
            return redirect()->route('candidates.show', $candidate);
        }
        if ($user->hasPermission(Permission::AccessExamPortal) && (int) $candidate->user_id === (int) $user->id) {
            return redirect()->route('portal.profile');
        }

        abort(404);
    }

    /** The code of a candidate, for staff who may see the candidate. */
    public function show(Candidate $candidate): Response
    {
        return $this->image($candidate);
    }

    /** The signed-in candidate's own code. */
    public function own(Request $request): Response
    {
        return $this->image($request->user()->candidate()->firstOrFail());
    }

    /** The candidate's QR card as a PDF file (Save as PDF), for staff who may see the candidate. */
    public function pdf(Candidate $candidate): Response
    {
        return $this->card($candidate);
    }

    /** The signed-in candidate's own QR card as a PDF file. */
    public function ownPdf(Request $request): Response
    {
        return $this->card($request->user()->candidate()->firstOrFail());
    }

    /** A new code for a lost or shared card; the old code stops working at once. */
    public function reissue(Request $request, Candidate $candidate, AuditLogger $audit): RedirectResponse
    {
        DB::transaction(function () use ($candidate, $request, $audit): void {
            $candidate->forceFill(['qr_token' => CandidateQrCode::newToken(), 'qr_token_issued_at' => now()])->save();
            $audit->record(AuditAction::CandidateQrReissued, $candidate, newValues: ['candidate' => $candidate->candidate_number], actor: $request->user());
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'New QR code issued. The old code no longer works.']);

        return back();
    }

    private function card(Candidate $candidate): Response
    {
        $candidate->loadMissing(['classBatch', 'campus']);
        $html = view('pdf.qr-card', [
            'qr' => 'data:image/svg+xml;base64,'.base64_encode(CandidateQrCode::svg($candidate, 600)),
            'name' => $candidate->full_name,
            'candidateNumber' => $candidate->candidate_number,
            'className' => $candidate->classBatch?->name,
            // The candidate's campus (owner decision 2026-10-03).
            'campusName' => $candidate->campus?->name,
            'organization' => config('institution.organization_name'),
            'systemName' => config('institution.system_name'),
            'generatedAt' => now()->timezone(config('institution.timezone'))->format('d M Y, h:i A T'),
            'logo' => PdfDocument::logo(),
        ])->render();

        return PdfDocument::download($html, 'portrait', 'qr-code-'.$candidate->candidate_number);
    }

    private function image(Candidate $candidate): Response
    {
        return response(CandidateQrCode::svg($candidate), 200, [
            'Content-Type' => 'image/svg+xml',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'",
        ]);
    }
}
