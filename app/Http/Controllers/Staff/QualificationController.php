<?php

namespace App\Http\Controllers\Staff;

use App\Enums\Permission;
use App\Enums\QualificationStatus;
use App\Http\Controllers\Controller;
use App\Models\ClassBatch;
use App\Services\Grading\CourseRecord;
use App\Services\Grading\CourseRecordService;
use App\Services\Performance\AreaDefinition;
use App\Services\Performance\CandidateQualification;
use App\Services\Performance\QualificationEngine;
use App\Support\AcademicOptions;
use App\Support\CandidateGroups;
use App\Support\PdfReport;
use App\Support\QueryFilters;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Qualification and class rank of every candidate of a class (route
 * middleware: performance.view). All results come from QualificationEngine;
 * the CGPA from CourseRecordService. One class at a time; Save as PDF lists
 * every candidate of the class shown.
 */
class QualificationController extends Controller
{
    public function __construct(private readonly QualificationEngine $engine, private readonly CourseRecordService $courses) {}

    public function index(Request $request): Response
    {
        $data = $this->build($request);
        $class = $data['class'];
        $areas = $data['areas'];

        return Inertia::render('staff/qualification/index', [
            'classOptions' => $data['classOptions'],
            'filters' => $data['filters'],
            'companyOptions' => $data['companyOptions'],
            'platoonOptions' => $data['platoonOptions'],
            'classBatch' => $class === null ? null : [
                'id' => $class->id,
                'name' => $class->name,
                'period' => $class->academicPeriod->name,
                'isActivePeriod' => $class->academicPeriod->is_active,
            ],
            'areas' => array_map(fn (AreaDefinition $area): array => $area->toArray(), $areas),
            'rows' => array_map(fn (CandidateQualification $qualification): array => [
                ...$qualification->toArray(withRank: true),
                'cgpa' => ($data['courses'][$qualification->candidate->id] ?? null)?->cgpaToArray(),
            ], $data['shown']),
            // Summaries of the company/platoon shown, before the status filter.
            'counts' => $this->engine->counts($data['inUnit']),
            'areaCounts' => $this->engine->areaCounts($data['inUnit'], $areas),
            'classSize' => $data['classSize'],
            'generatedAt' => now()->toIso8601String(),
            'can' => [
                'configure' => $request->user()->hasPermission(Permission::ConfigurePerformance),
                'viewCandidates' => $request->user()->hasPermission(Permission::ViewAllCandidates),
            ],
        ]);
    }

    /** The class shown, with the same filters, as a PDF file (Save as PDF): every candidate, ranked. */
    public function pdf(Request $request): HttpResponse
    {
        $data = $this->build($request);
        $class = $data['class'];
        abort_if($class === null, 404);
        $areas = $data['areas'];
        $filters = $data['filters'];
        $counts = $this->engine->counts($data['inUnit']);

        $meta = [
            ['Academic period', $class->academicPeriod->name.($class->academicPeriod->is_active ? ' (active)' : '')],
            ['Ranked together', $data['classSize'].' '.($data['classSize'] === 1 ? 'candidate' : 'candidates')],
        ];
        foreach (['company' => 'Company', 'platoon' => 'Platoon'] as $key => $label) {
            if ($filters[$key] !== '') {
                $meta[] = [$label, $filters[$key]];
            }
        }
        if ($filters['status'] !== '') {
            $meta[] = ['Qualification', QualificationStatus::from($filters['status'])->label()];
        }

        $sections = [
            ['type' => 'fields', 'heading' => 'Summary', 'perRow' => 4, 'fields' => [
                ['Candidates', (string) $counts['total']],
                ['Qualified', (string) $counts['qualified']],
                ['Pending', (string) $counts['pending']],
                ['Not Qualified', (string) $counts['notQualified']],
            ]],
        ];
        if ($areas === []) {
            $sections[] = ['type' => 'alert', 'text' => 'No active performance areas. Every candidate is Pending.'];
        }
        $sections[] = [
            'type' => 'table',
            'heading' => 'Candidates',
            'columns' => self::pdfColumns($areas),
            'rows' => array_map(fn (CandidateQualification $qualification): array => self::pdfRow($qualification, $areas, $data['courses'][$qualification->candidate->id] ?? null), $data['shown']),
            'empty' => $data['classSize'] === 0 ? 'No candidates in this class.' : 'No candidates match these filters.',
            'note' => "Class rank is by final grade over the whole class; filters don't change it. CGPA: unit-weighted average of every subject grade so far. Partial: a weighted area has no grade yet. Subject standing is not affected.",
        ];

        return PdfReport::download($request->user(), 'Qualification & Class Rank', $class->name, $meta, $sections, 'qualification-'.$class->name.'-'.now()->format('Ymd'), 'landscape');
    }

    /**
     * The class chosen in the request (or the default), the validated
     * filters, every ranked candidate, those in the company/platoon shown,
     * and those also matching the status filter.
     *
     * @return array{classOptions: array, class: ?ClassBatch, filters: array<string, string>, companyOptions: array, platoonOptions: array, areas: list<AreaDefinition>, inUnit: list<CandidateQualification>, shown: list<CandidateQualification>, classSize: int, courses: array<int, CourseRecord>}
     */
    private function build(Request $request): array
    {
        // Classes of the user's campus (every campus when not limited to one); ranks stay within a class.
        $classOptions = AcademicOptions::classBatchesByPeriod($request->user()->campusScope());
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

        return [
            'classOptions' => $classOptions,
            'class' => $class,
            'filters' => $filters,
            'companyOptions' => $companyOptions,
            'platoonOptions' => $platoonOptions,
            'areas' => $areas,
            'inUnit' => $inUnit,
            'shown' => $shown,
            'classSize' => count($ranked),
            // The CGPA of each candidate shown.
            'courses' => $class === null || $shown === [] ? [] : $this->courses->forClass(
                $class,
                new Collection(array_map(fn (CandidateQualification $qualification) => $qualification->candidate, $shown)),
            ),
        ];
    }

    /**
     * @param  list<AreaDefinition>  $areas
     * @return list<array<string, mixed>>
     */
    private static function pdfColumns(array $areas): array
    {
        $areaWidth = $areas === [] ? 0 : min(11, intdiv(45, count($areas)));
        $configured = fn (float $value): string => rtrim(rtrim(number_format($value, 2), '0'), '.');

        return [
            ['label' => 'Rank', 'width' => '5%', 'numeric' => true],
            ['label' => 'Candidate', 'width' => '15%'],
            ['label' => 'Company / Platoon', 'width' => '10%'],
            ...array_map(fn (AreaDefinition $area): array => [
                'label' => $area->name,
                'width' => $areaWidth.'%',
                'rule' => implode(' · ', array_filter([
                    'Weight '.$configured((float) $area->weight),
                    'passing '.$configured((float) $area->passingGrade),
                    $area->mustPass ? 'must-pass' : null,
                ])),
            ], $areas),
            ['label' => 'CGPA', 'width' => '7%', 'numeric' => true],
            ['label' => 'Final Grade', 'width' => '7%', 'numeric' => true],
            ['label' => 'Qualification'],
        ];
    }

    /**
     * One candidate's cells, in the order of pdfColumns().
     *
     * @param  list<AreaDefinition>  $areas
     * @return list<string>
     */
    private static function pdfRow(CandidateQualification $qualification, array $areas, ?CourseRecord $course): array
    {
        $row = $qualification->toArray(withRank: true);
        $candidate = $row['candidate'];
        $results = collect($row['areas'])->keyBy('areaId');
        $decision = $row['qualification'];
        $grade = fn (?float $value): string => PdfReport::number($value);

        $areaCells = array_map(function (AreaDefinition $area) use ($results, $grade): string {
            $result = $results->get($area->id);
            if ($result === null) {
                return '—';
            }

            return implode("\n", array_filter([
                $grade($result['grade']),
                $result['status']['label'],
                $result['status']['value'] !== 'passed' ? $result['note'] : null,
            ]));
        }, $areas);

        return [
            (string) ($row['rank'] ?? '—'),
            $candidate['name']."\n".$candidate['candidateNumber'].($candidate['status']['value'] !== 'enrolled' ? ' · '.$candidate['status']['label'] : ''),
            implode(' · ', array_filter([$candidate['company'], $candidate['platoon']])) ?: '—',
            ...$areaCells,
            $grade($course?->cgpa).($course?->cgpa !== null && ! $course->isComplete() ? "\nIn progress" : ''),
            $grade($row['overall']['score']).($row['overall']['score'] !== null && ! $row['overall']['complete'] ? "\nPartial" : ''),
            implode("\n", array_filter([
                $decision['status']['label'],
                ...$decision['reasons'],
                $decision['pending'] === [] ? null : 'Waiting for: '.implode(', ', $decision['pending']),
            ])),
        ];
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
