<?php

namespace App\Http\Controllers\Staff;

use App\Services\ReportCharts;
use App\Services\ReportingService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

final class ReportController
{
    public function __invoke(Request $request, ReportingService $reports, ReportCharts $charts)
    {
        $filters = $request->validate([
            'type' => ['sometimes', Rule::in(array_keys(ReportingService::TYPES))],
            'period' => 'nullable|integer|min:1', 'class' => 'nullable|integer|min:1',
            'subject' => 'nullable|integer|min:1', 'search' => 'nullable|string|max:100',
            'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d|after_or_equal:from',
            'print' => 'sometimes|boolean', 'page' => 'sometimes|integer|min:1',
        ]);
        $report = $reports->generate($request->user(), $filters);
        $print = $request->boolean('print');
        $perPage = $print ? 5000 : 10;
        $rows = $report['rows'];
        // Charts summarize every row of the filtered report, not only the visible page.
        $report['charts'] = $charts->for($report['filters']['type'], $rows, (int) $report['filters']['period'] ?: null);
        $rows = array_map(fn (array $row): array => array_diff_key($row, [ReportingService::CHART_KEY => true]), $rows);
        $page = $print ? 1 : (int) $request->input('page', 1);
        // Pages past the end are empty; the offset is never computed for them, so a huge page number cannot overflow it.
        $offset = $page > (int) ceil(count($rows) / $perPage) ? count($rows) : ($page - 1) * $perPage;
        $report['rows'] = new LengthAwarePaginator(array_slice($rows, $offset, $perPage), count($rows), $perPage, $page, ['path' => $request->url(), 'query' => $request->query()]);

        return Inertia::render('staff/reports/index', $report + ['printMode' => $print, 'types' => ReportingService::TYPES])
            ->toResponse($request)->header('Cache-Control', 'no-store, private');
    }
}
