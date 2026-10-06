<?php

namespace App\Http\Controllers\Staff;

use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Models\Candidate;
use App\Models\ClassBatch;
use App\Services\AuditLogger;
use App\Services\Completion\CourseCompletion;
use App\Support\CandidatePresenter;
use App\Support\PdfDocument;
use Carbon\CarbonImmutable;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * Course completion documents (owner request, 2026-10-06): the Transcript of
 * Records and the Certificate of Completion of one candidate, and every
 * certificate of a class on one PDF for the graduation. Issued by
 * administrators (CandidatePolicy::issueCompletionDocuments,
 * ClassBatchPolicy::printCompletionCertificates); every issue is audited
 * with its reference number.
 */
class CompletionDocumentController extends Controller
{
    public function __construct(
        private readonly CourseCompletion $completion,
        private readonly AuditLogger $audit,
    ) {}

    public function transcript(Candidate $candidate): Response
    {
        $issued = $this->issuedAt();
        $record = $this->completion->transcript($candidate);
        $reference = self::reference('TOR', $candidate, $issued);

        $html = view('pdf.transcript', [
            ...$this->common($issued),
            'title' => 'Transcript of Records',
            'reference' => $reference,
            'candidate' => CandidatePresenter::details($candidate),
            'period' => $this->period($candidate),
            ...$record,
        ])->render();

        $this->audit->record(AuditAction::TranscriptIssued, $candidate, newValues: [
            'reference' => $reference,
            'final' => $record['summary']['transcriptFinal'],
        ]);

        return PdfDocument::download($html, 'portrait', 'transcript-'.$candidate->candidate_number);
    }

    public function certificate(Candidate $candidate): Response
    {
        $summary = $this->completion->summary($candidate);
        if (! $summary['certificate']['eligible']) {
            throw ValidationException::withMessages(['completion' => 'The certificate cannot be issued yet. '.implode(' ', $summary['certificate']['reasons'])]);
        }

        $issued = $this->issuedAt();
        $certificate = $this->certificateData($candidate, $summary, $issued);
        $this->audit->record(AuditAction::CompletionCertificateIssued, $candidate, newValues: ['reference' => $certificate['reference']]);

        return PdfDocument::download($this->certificates([$certificate], $issued), 'landscape', 'certificate-of-completion-'.$candidate->candidate_number, pageNumbers: false);
    }

    public function classCertificates(ClassBatch $classBatch): Response
    {
        $certifiable = $this->completion->certifiable($classBatch);
        if ($certifiable === []) {
            throw ValidationException::withMessages(['completion' => 'No candidate of this class can receive a certificate yet: a candidate needs the status Completed, a final grade in every subject and to have qualified.']);
        }

        $issued = $this->issuedAt();
        $certificates = array_map(fn (array $item): array => $this->certificateData($item['candidate'], $item['summary'], $issued), $certifiable);
        $this->audit->record(AuditAction::CompletionCertificateIssued, $classBatch, newValues: [
            'certificates' => count($certificates),
            'references' => array_column($certificates, 'reference'),
        ]);

        return PdfDocument::download($this->certificates($certificates, $issued), 'landscape', 'certificates-of-completion-'.$classBatch->name, pageNumbers: false);
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    private function certificateData(Candidate $candidate, array $summary, CarbonImmutable $issued): array
    {
        $candidate->loadMissing(['classBatch.academicPeriod', 'campus']);

        return [
            'reference' => self::reference('COC', $candidate, $issued),
            'name' => $candidate->full_name,
            'candidateNumber' => $candidate->candidate_number,
            'className' => $candidate->classBatch?->name,
            'campus' => $candidate->campus?->name,
            'period' => $candidate->classBatch?->academicPeriod->name,
            'finalGrade' => $summary['finalGrade']['score'],
            'cgpa' => $summary['cgpa']['grade'],
            'rank' => $summary['rank'],
            'classSize' => $summary['classSize'],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $certificates
     */
    private function certificates(array $certificates, CarbonImmutable $issued): string
    {
        return view('pdf.completion-certificates', [
            ...$this->common($issued),
            'title' => 'Certificate of Completion',
            'certificates' => $certificates,
        ])->render();
    }

    /**
     * @return array<string, mixed>
     */
    private function common(CarbonImmutable $issued): array
    {
        return [
            'organization' => config('institution.organization_name'),
            'systemName' => config('institution.system_name'),
            'courseName' => config('institution.completion.course_name'),
            'signatories' => config('institution.completion.signatories'),
            'logo' => PdfDocument::logo(),
            'issuedOn' => $issued->format('j F Y'),
            'issuedDay' => $issued->day,
            'issuedMonthYear' => $issued->format('F Y'),
            'generatedAt' => $issued->format('d M Y, h:i A T'),
        ];
    }

    /**
     * @return array{name: string, startsOn: string, endsOn: string}|null
     */
    private function period(Candidate $candidate): ?array
    {
        $period = $candidate->classBatch?->academicPeriod;

        return $period === null ? null : ['name' => $period->name, 'startsOn' => $period->starts_on->format('d M Y'), 'endsOn' => $period->ends_on->format('d M Y')];
    }

    private function issuedAt(): CarbonImmutable
    {
        return CarbonImmutable::now((string) config('institution.timezone'));
    }

    private static function reference(string $prefix, Candidate $candidate, CarbonImmutable $issued): string
    {
        return $prefix.'-'.$candidate->id.'-'.$issued->format('Ymd-His');
    }
}
