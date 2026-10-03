<?php

namespace App\Http\Controllers\Staff;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Academic\AcademicPeriodRequest;
use App\Models\AcademicPeriod;
use App\Models\ClassBatch;
use App\Models\ClassSubject;
use App\Models\InstructorAssignment;
use App\Models\TrainingPhase;
use App\Services\AcademicPeriodService;
use App\Services\Grading\GradingThresholds;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AcademicPeriodController extends Controller
{
    public function __construct(private readonly AcademicPeriodService $periods) {}

    public function index(Request $request): Response
    {
        $periods = AcademicPeriod::query()
            ->withCount('classBatches')
            ->orderByDesc('starts_on')
            ->get()
            ->map(fn (AcademicPeriod $period): array => [
                ...$this->present($period),
                'classCount' => (int) $period->class_batches_count,
                'thresholds' => GradingThresholds::forPeriod($period)?->toArray(),
            ])
            ->all();

        return Inertia::render('staff/academic-periods/index', [
            'periods' => $periods,
            'can' => [
                'configureGrading' => $request->user()->hasPermission(Permission::ConfigureGrading),
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('staff/academic-periods/create');
    }

    public function store(AcademicPeriodRequest $request): RedirectResponse
    {
        $period = $this->periods->create($request->periodData());

        Inertia::flash('toast', ['type' => 'success', 'message' => "Academic period {$period->name} created."]);

        return redirect()->route('academic-periods.index');
    }

    public function edit(AcademicPeriod $academicPeriod): Response
    {
        return Inertia::render('staff/academic-periods/edit', ['period' => $this->present($academicPeriod)]);
    }

    public function update(AcademicPeriodRequest $request, AcademicPeriod $academicPeriod): RedirectResponse
    {
        $this->periods->update($academicPeriod, $request->periodData());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Academic period updated.']);

        return redirect()->route('academic-periods.index');
    }

    /**
     * The whole term in one view: every class of the period, the subjects it
     * takes, the instructors assigned to each, and its candidate count.
     */
    public function show(Request $request, AcademicPeriod $academicPeriod): Response
    {
        $classes = ClassBatch::query()
            ->where('academic_period_id', $academicPeriod->id)
            ->withCount('candidates')
            ->with(['classSubjects.subject:id,code,name,is_active', 'classSubjects.instructorAssignments.instructor:id,name,is_active'])
            ->orderBy('name')
            ->get();

        $presentedClasses = $classes->map(fn (ClassBatch $class): array => [
            'id' => $class->id,
            'name' => $class->name,
            'candidateCount' => (int) $class->candidates_count,
            'subjects' => $class->classSubjects
                ->sortBy(fn (ClassSubject $offering): string => $offering->subject->name)
                ->map(fn (ClassSubject $offering): array => [
                    'classSubjectId' => $offering->id,
                    'code' => $offering->subject->code,
                    'name' => $offering->subject->name,
                    'isActive' => $offering->subject->is_active,
                    'instructors' => $offering->instructorAssignments
                        ->sortBy(fn (InstructorAssignment $assignment): string => $assignment->instructor->name)
                        ->map(fn (InstructorAssignment $assignment): array => [
                            'id' => $assignment->instructor->id,
                            'name' => $assignment->instructor->name,
                            'isActive' => $assignment->instructor->is_active,
                        ])
                        ->values()
                        ->all(),
                ])
                ->values()
                ->all(),
        ])->values();

        $offerings = $classes->flatMap->classSubjects;

        return Inertia::render('staff/academic-periods/show', [
            'period' => [
                ...$this->present($academicPeriod),
                'thresholds' => GradingThresholds::forPeriod($academicPeriod)?->toArray(),
            ],
            // The year's training phases, in order (the course lasts one year).
            'phases' => $academicPeriod->trainingPhases()->ordered()->get()->map(fn (TrainingPhase $phase): array => $phase->toSummary())->all(),
            'classes' => $presentedClasses->all(),
            'totals' => [
                'classes' => $classes->count(),
                'subjects' => $offerings->pluck('subject_id')->unique()->count(),
                'instructors' => $offerings->flatMap->instructorAssignments->pluck('instructor_id')->unique()->count(),
                'candidates' => (int) $classes->sum('candidates_count'),
                'unassignedSubjects' => $offerings->filter(fn (ClassSubject $offering): bool => $offering->instructorAssignments->isEmpty())->count(),
            ],
            'can' => [
                'configureGrading' => $request->user()->hasPermission(Permission::ConfigureGrading),
                'manageClasses' => $request->user()->hasPermission(Permission::ManageClassBatches),
                'manageAssignments' => $request->user()->hasPermission(Permission::ManageInstructorAssignments),
            ],
        ]);
    }

    public function activate(AcademicPeriod $academicPeriod): RedirectResponse
    {
        $this->periods->activate($academicPeriod);

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$academicPeriod->name} is now the active academic period."]);

        return redirect()->route('academic-periods.index');
    }

    /**
     * @return array{id: int, name: string, startsOn: string, endsOn: string, isActive: bool}
     */
    private function present(AcademicPeriod $period): array
    {
        return [
            'id' => $period->id,
            'name' => $period->name,
            'startsOn' => $period->starts_on->toDateString(),
            'endsOn' => $period->ends_on->toDateString(),
            'isActive' => $period->is_active,
        ];
    }
}
