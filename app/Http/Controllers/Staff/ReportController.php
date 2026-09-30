<?php

namespace App\Http\Controllers\Staff;

use App\Services\ReportingService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

final class ReportController
{
    public function __invoke(Request $request, ReportingService $reports)
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
        $perPage = $print ? 5000 : 25;
        $page = $print ? 1 : (int) $request->input('page', 1);
        $rows = $report['rows'];
        $report['rows'] = new LengthAwarePaginator(array_slice($rows, ($page - 1) * $perPage, $perPage), count($rows), $perPage, $page, ['path' => $request->url(), 'query' => $request->query()]);

        return Inertia::render('staff/reports/index', $report + ['printMode' => $print, 'types' => ReportingService::TYPES])
            ->toResponse($request)->header('Cache-Control', 'no-store, private');
    }
}
