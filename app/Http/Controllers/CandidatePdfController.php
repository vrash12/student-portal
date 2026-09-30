<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Models\Candidate;
use App\Services\AuditLogger;
use App\Services\CandidatePdfService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class CandidatePdfController extends Controller
{
    public function show(Request $request, Candidate $candidate, string $type, CandidatePdfService $pdf): Response
    {
        return $this->download($request, $candidate, $type, $pdf);
    }

    public function own(Request $request, string $type, CandidatePdfService $pdf): Response
    {
        return $this->download($request, $request->user()->candidate()->firstOrFail(), $type, $pdf);
    }

    private function download(Request $request, Candidate $candidate, string $type, CandidatePdfService $pdf): Response
    {
        $data = $pdf->data($request->user(), $candidate, $type);
        $bytes = $pdf->render($data);
        app(AuditLogger::class)->record(AuditAction::CandidateRecordDownloaded, $candidate,
            newValues: ['document_type' => $type], actor: $request->user());
        $filename = $type.'-record-'.(Str::slug($candidate->candidate_number) ?: $candidate->id).'.pdf';

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
