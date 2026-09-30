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

    /** Rows that fit one column of a landscape page next to another column. */
    private const COLUMN_ROWS = 26;

    /** Space reserved in the left column for the current subjects table. */
    private const CURRENT_SUBJECT_ROWS = 10;

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
            'title' => $type === 'registration' ? 'Certificate of Registration' : 'Academic Record',
            'candidate' => CandidatePresenter::details($candidate),
            'organization' => config('institution.organization_name'),
            'systemName' => config('institution.system_name'),
            'generatedAt' => $generated->format('d M Y, h:i A T'),
            'reference' => strtoupper($type === 'registration' ? 'REG' : 'ACAD').'-'.$candidate->id.'-'.$generated->format('Ymd-His'),
            'logo' => $this->logo(),
            'period' => $this->currentPeriod($candidate),
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
            $data['periods'] = $this->periods($data['period'], $data['assessments'], $data['examinations']);
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
        // Landscape, like the institution's printed registration form.
        $pdf->setPaper('letter', 'landscape');
        $pdf->loadHtml(view('pdf.candidate-record', $data)->render(), 'UTF-8');
        $pdf->render();
        $pdf->getCanvas()->page_text(700, 590, 'Page {PAGE_NUM} of {PAGE_COUNT}', $pdf->getFontMetrics()->getFont('DejaVu Sans'), 7, [0.35, 0.39, 0.43]);

        return $pdf->output();
    }

    /**
     * The candidate's current academic period (semester), if assigned to a class.
     *
     * @return array{id: int, name: string, startsOn: ?string, endsOn: ?string}|null
     */
    private function currentPeriod(Candidate $candidate): ?array
    {
        $period = $candidate->classBatch?->academicPeriod;

        return $period === null ? null : [
            'id' => $period->id,
            'name' => $period->name,
            'startsOn' => $period->starts_on?->toDateString(),
            'endsOn' => $period->ends_on?->toDateString(),
        ];
    }

    /**
     * Records grouped by academic period (semester), oldest first; each period
     * is printed on its own landscape page. The current period is always
     * included (it carries the current subject grades and standing).
     *
     * @param  array{id: int, name: string, startsOn: ?string, endsOn: ?string}|null  $current
     * @param  list<array<string, mixed>>  $assessments
     * @param  list<array<string, mixed>>  $examinations
     * @return list<array{period: array<string, mixed>, isCurrent: bool, assessments: list<array<string, mixed>>, examinations: list<array<string, mixed>>, twoColumns: bool}>
     */
    private function periods(?array $current, array $assessments, array $examinations): array
    {
        $periods = [];
        if ($current !== null) {
            $periods[$current['id']] = ['period' => $current, 'assessments' => [], 'examinations' => []];
        }
        foreach (['assessments' => $assessments, 'examinations' => $examinations] as $key => $rows) {
            foreach ($rows as $row) {
                $id = $row['period']['id'];
                $periods[$id] ??= ['period' => $row['period'], 'assessments' => [], 'examinations' => []];
                $periods[$id][$key][] = $row;
            }
        }

        uasort($periods, fn (array $a, array $b): int => [$a['period']['startsOn'] ?? '', $a['period']['id']] <=> [$b['period']['startsOn'] ?? '', $b['period']['id']]);

        return array_values(array_map(function (array $group) use ($current): array {
            $isCurrent = $current !== null && $group['period']['id'] === $current['id'];
            // Assessments are listed oldest first within a semester.
            $group['assessments'] = array_reverse($group['assessments']);
            $group['examinations'] = array_reverse($group['examinations']);

            return $group + [
                'isCurrent' => $isCurrent,
                // Side-by-side columns fit one landscape page for typical semesters;
                // longer ones are printed stacked so every row stays readable.
                'twoColumns' => count($group['assessments']) <= self::COLUMN_ROWS
                    && count($group['examinations']) + ($isCurrent ? self::CURRENT_SUBJECT_ROWS : 0) <= self::COLUMN_ROWS,
            ];
        }, $periods));
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
