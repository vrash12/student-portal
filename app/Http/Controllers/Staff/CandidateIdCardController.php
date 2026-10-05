<?php

namespace App\Http\Controllers\Staff;

use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Models\Candidate;
use App\Models\ClassBatch;
use App\Services\AuditLogger;
use App\Support\CandidateIdCard;
use App\Support\IdCardArtwork;
use App\Support\PdfDocument;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Candidate ID cards (owner request, 2026-10-05; administrators only for
 * now, CandidatePolicy::viewIdCard): the card on screen, front and back,
 * and a PDF at the standard ID size (CR80) for one candidate or for every
 * candidate of a class in training. Each PDF is recorded in the audit log.
 */
class CandidateIdCardController extends Controller
{
    public function show(Candidate $candidate): Response
    {
        $card = CandidateIdCard::data($candidate);

        return Inertia::render('staff/candidates/id-card', [
            'candidateId' => $candidate->id,
            'card' => [
                ...$card,
                'photoUrl' => $candidate->profile_photo_path === null ? null : route('candidates.photo', $candidate, false),
                'qrUrl' => route('candidates.qr', $candidate, false),
            ],
            'artwork' => IdCardArtwork::dataUris(),
            'missing' => CandidateIdCard::missing($candidate),
            'printedOn' => now()->timezone(config('institution.timezone'))->toDateString(),
            'pdfUrl' => route('candidates.id-card.pdf', $candidate, false),
        ]);
    }

    public function pdf(Candidate $candidate, AuditLogger $audit): HttpResponse
    {
        $audit->record(AuditAction::CandidateIdCardDownloaded, $candidate, newValues: ['cards' => 1]);

        return $this->download([$candidate], 'ID Card · '.$candidate->candidate_number, 'id-card-'.$candidate->candidate_number);
    }

    public function classSheet(ClassBatch $classBatch, AuditLogger $audit): HttpResponse
    {
        $candidates = $classBatch->candidates()
            ->whereIn('status', array_map(fn ($status) => $status->value, CandidateIdCard::IN_TRAINING))
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->orderBy('id')
            ->get();
        abort_if($candidates->isEmpty(), 404);

        $audit->record(AuditAction::CandidateIdCardDownloaded, $classBatch, newValues: ['cards' => $candidates->count()]);

        return $this->download($candidates->all(), 'ID Cards · '.$classBatch->name, 'id-cards-'.$classBatch->name);
    }

    /**
     * @param  list<Candidate>  $candidates
     */
    private function download(array $candidates, string $title, string $filename): HttpResponse
    {
        $html = view('pdf.id-cards', [
            'title' => $title,
            'cards' => array_map(fn (Candidate $candidate): array => $this->pdfCard($candidate), $candidates),
            'logo' => CandidateIdCard::logoDataUri(),
            'artwork' => IdCardArtwork::dataUris(forPdf: true),
            'printedOn' => now()->timezone(config('institution.timezone'))->format('M j, Y'),
        ])->render();

        return PdfDocument::download($html, 'portrait', $filename, PdfDocument::ID_CARD_PAPER, pageNumbers: false);
    }

    /**
     * The card's values with its picture and QR code for the PDF.
     *
     * @return array<string, mixed>
     */
    private function pdfCard(Candidate $candidate): array
    {
        $card = CandidateIdCard::data($candidate);

        return [
            ...$card,
            'validUntil' => $card['validUntil'] === null ? null : CarbonImmutable::parse($card['validUntil'])->format('M j, Y'),
            'photo' => CandidateIdCard::photoDataUri($candidate),
            'qr' => CandidateIdCard::qrDataUri($candidate),
        ];
    }
}
