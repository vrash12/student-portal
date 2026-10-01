<?php

namespace App\Http\Controllers\Staff;

use App\Enums\Permission;
use App\Enums\QualificationStatus;
use App\Http\Controllers\Controller;
use App\Models\ClassBatch;
use App\Services\Performance\AreaDefinition;
use App\Services\Performance\CandidateQualification;
use App\Services\Performance\QualificationEngine;
use App\Support\AcademicOptions;
use App\Support\CandidateGroups;
use App\Support\QueryFilters;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Qualification and class rank of every candidate of a class (route
 * middleware: performance.view). All results come from QualificationEngine.
 * One class at a time, every candidate on one page, so the list can be
 * printed from the browser.
 */
class QualificationController extends Controller
{
    public function __construct(private readonly QualificationEngine $engine) {}

    public function index(Request $request): Response
    {
        $classOptions = AcademicOptions::classBatchesByPeriod();
        $classIds = [];
        foreach ($classOptions as $group) {
            foreach ($group['classes'] as $option) {
                $classIds[] = $option['id'];
            }
        }

        // The first class of the active (first) period by default; unknown classes show the default.
        $requestedClass = (int) QueryFilters::id($request, 'class');
        $classId = in_array($requestedClass, $classIds, true) ? $requestedClass : ($classIds[0] ?? null);
        $class = $classId === null ? null : ClassBatch::query()->with('academicPeriod')->find($classId);

        $companyOptions = $class === null ? [] : CandidateGroups::companies($class->id);
        $platoonOptions = $class === null ? [] : CandidateGroups::platoons($class->id);
        $filters = [
            'class' => $class === null ? '' : (string) $class->id,
            'company' => QueryFilters::oneOf($request, 'company', $companyOptions),
            'platoon' => QueryFilters::oneOf($request, 'platoon', $platoonOptions),
            'status' => QueryFilters::oneOf($request, 'status', array_map(fn (QualificationStatus $status): string => $status->value, QualificationStatus::cases())),
        ];

        $areas = $this->engine->activeAreas();
        // Ranks are within the whole class; the filters only narrow what is shown.
        $ranked = $class === null ? [] : $this->engine->forClass($class);
        $inUnit = array_values(array_filter($ranked, fn (CandidateQualification $qualification): bool => self::matches($qualification->candidate->company, $filters['company'])
            && self::matches($qualification->candidate->platoon, $filters['platoon'])));
        $shown = $filters['status'] === '' ? $inUnit : array_values(array_filter(
            $inUnit,
            fn (CandidateQualification $qualification): bool => $qualification->qualification->status->value === $filters['status'],
        ));

        return Inertia::render('staff/qualification/index', [
            'classOptions' => $classOptions,
            'filters' => $filters,
            'companyOptions' => $companyOptions,
            'platoonOptions' => $platoonOptions,
            'classBatch' => $class === null ? null : [
                'id' => $class->id,
                'name' => $class->name,
                'period' => $class->academicPeriod->name,
                'isActivePeriod' => $class->academicPeriod->is_active,
            ],
            'areas' => array_map(fn (AreaDefinition $area): array => $area->toArray(), $areas),
            'rows' => array_map(fn (CandidateQualification $qualification): array => $qualification->toArray(withRank: true), $shown),
            // Summaries of the company/platoon shown, before the status filter.
            'counts' => $this->engine->counts($inUnit),
            'areaCounts' => $this->engine->areaCounts($inUnit, $areas),
            'classSize' => count($ranked),
            'generatedAt' => now()->toIso8601String(),
            'can' => [
                'configure' => $request->user()->hasPermission(Permission::ConfigurePerformance),
                'viewCandidates' => $request->user()->hasPermission(Permission::ViewAllCandidates),
            ],
        ]);
    }

    /**
     * Company and platoon names are free text compared like the database
     * does (case-insensitive), so the filter matches the option it came from.
     */
    private static function matches(?string $value, string $filter): bool
    {
        return $filter === '' || ($value !== null && mb_strtolower($value) === mb_strtolower($filter));
    }
}
