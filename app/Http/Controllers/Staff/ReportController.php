<?php

namespace App\Http\Controllers\Staff;

use App\Services\ReportCharts;
use App\Services\ReportingService;
use App\Support\PdfReport;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

final class ReportController
{
    /** Rows in one PDF; the PDF says so when the report has more. */
    public const PDF_ROW_LIMIT = 5000;

    private const PER_PAGE = 10;

    public function __invoke(Request $request, ReportingService $reports, ReportCharts $charts)
    {
        $report = $reports->generate($request->user(), $this->filters($request));
        $rows = $report['rows'];
        // Charts summarize every row of the filtered report, not only the visible page.
        $report['charts'] = $charts->for($report['filters']['type'], $rows, (int) $report['filters']['period'] ?: null);
        $rows = self::withoutChartData($rows);
        $page = (int) $request->input('page', 1);
        // Pages past the end are empty; the offset is never computed for them, so a huge page number cannot overflow it.
        $offset = $page > (int) ceil(count($rows) / self::PER_PAGE) ? count($rows) : ($page - 1) * self::PER_PAGE;
        $report['rows'] = new LengthAwarePaginator(array_slice($rows, $offset, self::PER_PAGE), count($rows), self::PER_PAGE, $page, ['path' => $request->url(), 'query' => $request->query()]);

        return Inertia::render('staff/reports/index', $report + ['types' => ReportingService::TYPES])
            ->toResponse($request)->header('Cache-Control', 'no-store, private');
    }

    /** The filtered report as a PDF file (Save as PDF), every row up to the limit. */
    public function pdf(Request $request, ReportingService $reports): Response
    {
        $report = $reports->generate($request->user(), $this->filters($request));
        $filters = $report['filters'];
        $rows = self::withoutChartData($report['rows']);
        $type = ReportingService::TYPES[$filters['type']] ?? 'Report';
        $results = in_array($filters['type'], ['examination', 'quiz'], true);
        $name = fn (array $options, mixed $id, string $all): string => $id === '' || $id === null ? $all
            : (collect($options)->firstWhere('id', (int) $id)['name'] ?? $all);

        $numeric = $report['numericColumns'] ?? [];
        $columns = array_map(fn (string $key, string $label): array => [
            'label' => $key === 'classBatch' ? 'Class / Batch' : $label,
            'numeric' => in_array($key, $numeric, true),
        ], array_keys($report['columns']), $report['columns']);
        $keys = array_keys($report['columns']);
        $shown = array_slice($rows, 0, self::PDF_ROW_LIMIT);

        $meta = [
            ['Academic period', $name($report['periods'], $filters['period'], 'No academic period')],
            ['Class / Batch', $name($report['classes'], $filters['class'], 'All authorized classes')],
            ['Subject', $name($report['subjects'], $filters['subject'], 'All authorized subjects')],
            ['Records', number_format(count($rows))],
        ];
        if ($results) {
            $meta[] = ['Submitted', ($filters['from'] ?: 'any').' through '.($filters['to'] ?: 'any')];
        }
        if ($filters['search'] !== '') {
            $meta[] = ['Search', $filters['search']];
        }

        $sections = [];
        if (count($rows) > self::PDF_ROW_LIMIT) {
            $sections[] = ['type' => 'alert', 'text' => 'This PDF lists the first '.number_format(self::PDF_ROW_LIMIT).' of '.number_format(count($rows)).' rows. Narrow the filters for a complete report.'];
        }
        $sections[] = [
            'type' => 'table',
            'columns' => $columns,
            'rows' => array_map(fn (array $row): array => array_map(fn (string $key): string => PdfReport::value($row[$key] ?? null), $keys), $shown),
            'empty' => 'No records for this report.',
            'note' => ($results
                ? 'Every submitted or expired attempt is listed separately. Scores awaiting review are not final.'
                : 'Current weighted grades from finalized assessments. Provisional grades are included; configured period thresholds determine standing. This is a current snapshot, not a historical as-of report.')
                .($report['scope'] === 'taught' ? ' Standing covers only the subjects you teach.' : ''),
        ];

        return PdfReport::download($request->user(), $type, null, $meta, $sections, 'report-'.Str::slug($type).'-'.now()->format('Ymd-His'), 'landscape');
    }

    /** @return array<string, mixed> */
    private function filters(Request $request): array
    {
        return $request->validate([
            'type' => ['sometimes', Rule::in(array_keys(ReportingService::TYPES))],
            'period' => 'nullable|integer|min:1', 'class' => 'nullable|integer|min:1',
            'subject' => 'nullable|integer|min:1', 'search' => 'nullable|string|max:100',
            'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d|after_or_equal:from',
            'page' => 'sometimes|integer|min:1',
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private static function withoutChartData(array $rows): array
    {
        return array_map(fn (array $row): array => array_diff_key($row, [ReportingService::CHART_KEY => true]), $rows);
    }
}
