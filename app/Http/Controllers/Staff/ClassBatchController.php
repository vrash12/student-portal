<?php

namespace App\Http\Controllers\Staff;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Academic\ClassBatchRequest;
use App\Models\AcademicPeriod;
use App\Models\AssessmentCategory;
use App\Models\Candidate;
use App\Models\ClassBatch;
use App\Models\ClassSubject;
use App\Models\Subject;
use App\Models\TrainingPhase;
use App\Services\ClassBatchService;
use App\Support\AcademicOptions;
use App\Support\DecimalValue;
use App\Support\ListCharts;
use App\Support\QueryFilters;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ClassBatchController extends Controller
{
    private const PER_PAGE = 10;

    private const CANDIDATES_PER_PAGE = 10;

    public function __construct(private readonly ClassBatchService $classes) {}

    public function index(Request $request): Response
    {
        $activePeriodId = AcademicPeriod::query()->active()->value('id');
        $requestedPeriod = QueryFilters::id($request, 'period');
        $periodId = $requestedPeriod !== '' ? (int) $requestedPeriod : $activePeriodId;

        $classBatches = ClassBatch::query()
            ->with('academicPeriod')
            ->withCount(['candidates', 'classSubjects'])
            ->when($periodId !== null, fn (Builder $query) => $query->where('academic_period_id', $periodId))
            ->orderBy('name')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (ClassBatch $classBatch): array => [
                'id' => $classBatch->id,
                'name' => $classBatch->name,
                'period' => $classBatch->academicPeriod->name,
                'candidateCount' => (int) $classBatch->candidates_count,
                'subjectCount' => (int) $classBatch->class_subjects_count,
            ]);

        $largest = ClassBatch::query()
            ->withCount('candidates')
            ->when($periodId !== null, fn (Builder $query) => $query->where('academic_period_id', $periodId))
            ->orderByDesc('candidates_count')
            ->orderBy('name')
            ->limit(10)
            ->get();
        $charts = [
            ListCharts::bars('Candidates per Class', 'The ten largest classes of the selected academic period.',
                $largest->map(fn (ClassBatch $classBatch): array => ['label' => $classBatch->name, 'value' => (int) $classBatch->candidates_count])->all(), 'candidate', 'candidates'),
            ListCharts::bars('Subjects per Class', 'Subjects offered in the same classes.',
                ClassBatch::query()->withCount('classSubjects')->whereKey($largest->modelKeys())->orderByDesc('class_subjects_count')->get()
                    ->map(fn (ClassBatch $classBatch): array => ['label' => $classBatch->name, 'value' => (int) $classBatch->class_subjects_count])->all(), 'subject', 'subjects'),
        ];

        return Inertia::render('staff/classes/index', [
            'classes' => $classBatches,
            'charts' => $charts,
            'filters' => ['period' => $periodId === null ? '' : (string) $periodId],
            'periods' => AcademicOptions::academicPeriods(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('staff/classes/create', [
            'periods' => AcademicOptions::academicPeriods(),
        ]);
    }

    public function store(ClassBatchRequest $request): RedirectResponse
    {
        $period = AcademicPeriod::query()->findOrFail($request->integer('academic_period_id'));
        $classBatch = $this->classes->create($period, $request->string('name')->value());

        Inertia::flash('toast', ['type' => 'success', 'message' => "Class {$classBatch->name} created."]);

        return redirect()->route('classes.show', $classBatch);
    }

    public function show(Request $request, ClassBatch $classBatch): Response
    {
        $classBatch->load([
            'academicPeriod',
            'classSubjects' => fn ($offerings) => $offerings->withCount('assessments'),
            'classSubjects.subject',
            'classSubjects.trainingPhase',
            'classSubjects.instructorAssignments.instructor',
            'classSubjects.assessmentCategories',
        ]);

        // In phase order (subjects not in a phase last), then by name.
        $offerings = $classBatch->classSubjects->sortBy(fn (ClassSubject $offering): array => [$offering->trainingPhase?->number ?? PHP_INT_MAX, $offering->subject->name]);
        $offeredSubjectIds = $offerings->pluck('subject_id')->all();

        $candidates = $classBatch->candidates()
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->paginate(self::CANDIDATES_PER_PAGE)
            ->withQueryString()
            ->through(fn (Candidate $candidate): array => [
                'id' => $candidate->id,
                'candidateNumber' => $candidate->candidate_number,
                'name' => $candidate->full_name,
                'status' => ['label' => $candidate->status->label(), 'tone' => $candidate->status->tone()],
            ]);

        return Inertia::render('staff/classes/show', [
            'classBatch' => [
                'id' => $classBatch->id,
                'name' => $classBatch->name,
                'period' => [
                    'id' => $classBatch->academicPeriod->id,
                    'name' => $classBatch->academicPeriod->name,
                    'isActive' => $classBatch->academicPeriod->is_active,
                ],
            ],
            'offerings' => $offerings->map(fn (ClassSubject $offering): array => [
                'id' => $offering->id,
                'subject' => [
                    'code' => $offering->subject->code,
                    'name' => $offering->subject->name,
                    'isActive' => $offering->subject->is_active,
                ],
                'phase' => $offering->trainingPhase?->toSummary(),
                'units' => DecimalValue::display($offering->units),
                'instructors' => $offering->instructorAssignments
                    ->sortBy(fn ($assignment) => $assignment->instructor->name)
                    ->map(fn ($assignment): array => [
                        'assignmentId' => $assignment->id,
                        'id' => $assignment->instructor->id,
                        'name' => $assignment->instructor->name,
                        'isActive' => $assignment->instructor->is_active,
                    ])
                    ->values()
                    ->all(),
                'grading' => $offering->assessmentCategories
                    ->map(fn (AssessmentCategory $category): array => [
                        'name' => $category->name,
                        'weight' => DecimalValue::display($category->weight),
                    ])
                    ->values()
                    ->all(),
                'assessmentCount' => (int) $offering->assessments_count,
            ])->values()->all(),
            'candidates' => $candidates,
            'subjectOptions' => Subject::query()
                ->active()
                ->whereNotIn('id', $offeredSubjectIds)
                ->orderBy('name')
                ->get(['id', 'code', 'name'])
                ->map(fn (Subject $subject): array => ['id' => $subject->id, 'code' => $subject->code, 'name' => $subject->name])
                ->all(),
            'instructorOptions' => AcademicOptions::eligibleInstructors(),
            // Phases of the class's academic year only.
            'phases' => $classBatch->academicPeriod->trainingPhases()->ordered()->get()->map(fn (TrainingPhase $phase): array => $phase->toSummary())->all(),
            'can' => [
                'manageAssignments' => $request->user()->hasPermission(Permission::ManageInstructorAssignments),
                'viewCandidates' => $request->user()->can('viewAny', Candidate::class),
                'configureGrading' => $request->user()->hasPermission(Permission::ConfigureGrading),
                'viewPeriod' => $request->user()->hasPermission(Permission::ManageAcademicPeriods),
            ],
        ]);
    }

    public function edit(ClassBatch $classBatch): Response
    {
        $classBatch->load('academicPeriod');

        return Inertia::render('staff/classes/edit', [
            'classBatch' => [
                'id' => $classBatch->id,
                'name' => $classBatch->name,
                'period' => $classBatch->academicPeriod->name,
            ],
        ]);
    }

    public function update(ClassBatchRequest $request, ClassBatch $classBatch): RedirectResponse
    {
        $this->classes->rename($classBatch, $request->string('name')->value());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Class updated.']);

        return redirect()->route('classes.show', $classBatch);
    }
}
