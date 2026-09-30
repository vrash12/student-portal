<?php

namespace App\Services;

use App\Models\Candidate;
use App\Models\ClassSubject;
use App\Models\User;
use App\Services\Monitoring\CandidateProfileRecord;
use App\Support\CandidatePresenter;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class CandidatePdfService
{
    private const HISTORY_LIMIT = 1000;

    public function __construct(private readonly CandidateProfileRecord $records) {}

    /** Both document types use explicit safe fields, including for administrator downloads. */
    public function data(User $actor, Candidate $candidate, string $type): array
    {
        Gate::forUser($actor)->authorize('downloadRecord', $candidate);
        abort_unless(in_array($type, ['registration', 'academic'], true), 404);
        $candidate->loadMissing('classBatch.academicPeriod');
        $generated = now()->timezone(config('institution.timezone'));
        $data = [
            'type' => $type,
            'title' => $type === 'registration' ? 'Registration Record' : 'Academic Record',
            'candidate' => CandidatePresenter::details($candidate),
            'organization' => config('institution.organization_name'),
            'systemName' => config('institution.system_name'),
            'generatedAt' => $generated->format('d M Y, h:i A T'),
            'reference' => strtoupper($type === 'registration' ? 'REG' : 'ACAD').'-'.$candidate->id.'-'.$generated->format('Ymd-His'),
            'logo' => $this->logo(),
        ];

        if ($type === 'registration') {
            $data['subjects'] = ClassSubject::query()->where('class_batch_id', $candidate->class_batch_id ?? 0)
                ->with(['subject:id,code,name', 'instructors:id,name'])->get()->sortBy('subject.name')->values()
                ->map(fn (ClassSubject $offering): array => [
                    'code' => $offering->subject->code, 'name' => $offering->subject->name,
                    'instructors' => $offering->instructors->pluck('name')->sort()->values()->all(),
                ])->all();
        } else {
            $data['academics'] = $this->records->academics($candidate);
            // Explicit first page: a profile's current pagination must not omit PDF records.
            $assessments = $this->records->assessmentHistory($candidate, self::HISTORY_LIMIT, 1);
            $exams = $this->records->examinationResults($candidate, true, perPage: self::HISTORY_LIMIT, page: 1);
            if ($assessments->total() > self::HISTORY_LIMIT || $exams->total() > self::HISTORY_LIMIT) {
                throw ValidationException::withMessages(['pdf' => 'This record exceeds the 1,000-entry PDF limit. Use the paginated academic record to review the full history.']);
            }
            $data['assessments'] = $assessments->items();
            $data['examinations'] = $exams->items();
        }

        return $data;
    }

    public function render(array $data): string
    {
        $cache = storage_path('framework/cache/pdf');
        File::ensureDirectoryExists($cache);
        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setIsFontSubsettingEnabled(true);
        $options->setDefaultFont('DejaVu Sans');
        $options->setChroot([public_path('branding'), base_path('vendor/dompdf/dompdf/lib/fonts')]);
        $options->setTempDir($cache);
        $options->setFontCache($cache);
        $pdf = new Dompdf($options);
        $pdf->setPaper('letter');
        $pdf->loadHtml(view('pdf.candidate-record', $data)->render(), 'UTF-8');
        $pdf->render();
        $pdf->getCanvas()->page_text(470, 758, 'Page {PAGE_NUM} of {PAGE_COUNT}', $pdf->getFontMetrics()->getFont('DejaVu Sans'), 8, [0.35, 0.39, 0.43]);

        return $pdf->output();
    }

    /** Embed only a configured local raster logo; never request remote resources. */
    private function logo(): ?string
    {
        $url = config('institution.logo_url');
        if (! is_string($url) || ! str_starts_with($url, '/') || str_starts_with($url, '//')) {
            return null;
        }
        $root = realpath(public_path());
        $path = realpath(public_path(ltrim($url, '/')));
        if ($root === false || $path === false || ! str_starts_with($path, $root.DIRECTORY_SEPARATOR) || ! is_file($path) || filesize($path) > 5 * 1024 * 1024) {
            return null;
        }
        $mime = mime_content_type($path);
        if (! in_array($mime, ['image/png', 'image/jpeg'], true)) {
            return null;
        }

        return 'data:'.$mime.';base64,'.base64_encode(file_get_contents($path));
    }
}
