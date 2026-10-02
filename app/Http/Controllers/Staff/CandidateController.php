<?php

namespace App\Http\Controllers\Staff;

use App\Enums\CandidateStatus;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Candidates\StoreCandidateRequest;
use App\Http\Requests\Candidates\UpdateCandidateRequest;
use App\Models\Candidate;
use App\Models\ClassBatch;
use App\Models\ClassSubject;
use App\Models\ConductEntry;
use App\Models\User;
use App\Services\Attendance\AttendanceScope;
use App\Services\CandidateService;
use App\Services\Fitness\FitnessResults;
use App\Services\Grading\GradeCalculationService;
use App\Services\Grading\GradingThresholds;
use App\Services\Medical\MedicalRecordService;
use App\Services\Monitoring\CandidateAcademicRecord;
use App\Services\Monitoring\CandidateProfileRecord;
use App\Services\Performance\CandidatePerformanceRecord;
use App\Support\AcademicOptions;
use App\Support\CandidateGroups;
use App\Support\CandidatePresenter;
use App\Support\ListCharts;
use App\Support\MedicalRecordPresenter;
use App\Support\QueryFilters;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CandidateController extends Controller
{
    private const PER_PAGE = 10;

    /** Fitness tests shown on a candidate profile, newest first. */
    private const FITNESS_HISTORY = 5;

    /** Latest merits/demerits and attendance sessions on a profile; the full records have their own pages. */
    private const CONDUCT_HISTORY = 5;

    private const ATTENDANCE_HISTORY = 5;

    public function __construct(private readonly CandidateService $candidates) {}

    public function index(Request $request): Response
    {
        $statusValues = array_map(fn (CandidateStatus $status): string => $status->value, CandidateStatus::cases());
        // Only names already in use are valid filters; anything else is ignored.
        $companies = CandidateGroups::companies();
        $platoons = CandidateGroups::platoons();
        $filters = [
            'search' => QueryFilters::search($request),
            'class' => QueryFilters::id($request, 'class'),
            'status' => QueryFilters::oneOf($request, 'status', $statusValues),
            'company' => QueryFilters::oneOf($request, 'company', $companies),
            'platoon' => QueryFilters::oneOf($request, 'platoon', $platoons),
        ];

        $query = Candidate::query()
            ->with('classBatch')
            ->when($filters['search'] !== '', fn (Builder $query) => $query->matching($filters['search']))
            ->when($filters['class'] !== '', fn (Builder $query) => $query->where('class_batch_id', (int) $filters['class']))
            ->when($filters['status'] !== '', fn (Builder $query) => $query->where('status', $filters['status']))
            ->when($filters['company'] !== '', fn (Builder $query) => $query->where('company', $filters['company']))
            ->when($filters['platoon'] !== '', fn (Builder $query) => $query->where('platoon', $filters['platoon']));

        $classNames = ClassBatch::query()->pluck('name', 'id');
        $charts = [
            ListCharts::bars('Candidates by Status', 'Enrollment status of the matching candidates.',
                ListCharts::countBy($query, 'status', fn (mixed $value): string => CandidateStatus::tryFrom((string) $value)?->label() ?? (string) $value), 'candidate', 'candidates'),
            ListCharts::bars('Candidates by Class', 'The ten largest classes among the matching candidates.',
                ListCharts::countBy($query, 'class_batch_id', fn (mixed $value): string => $value === null ? 'No class' : (string) ($classNames[$value] ?? 'Unknown class')), 'candidate', 'candidates'),
        ];

        $candidates = $query
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->orderBy('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (Candidate $candidate): array => [
                'id' => $candidate->id,
                'candidateNumber' => $candidate->candidate_number,
                'name' => $candidate->full_name,
                'className' => $candidate->classBatch?->name,
                'company' => $candidate->company,
                'platoon' => $candidate->platoon,
                'status' => $this->status($candidate->status),
                'updatedAt' => $candidate->updated_at?->toIso8601String(),
            ]);

        return Inertia::render('staff/candidates/index', [
            'candidates' => $candidates,
            'charts' => $charts,
            'filters' => $filters,
            'classOptions' => AcademicOptions::classBatchesByPeriod(),
            'statusOptions' => CandidateStatus::options(),
            'companyOptions' => $companies,
            'platoonOptions' => $platoons,
            'canCreate' => $request->user()->can('create', Candidate::class),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('staff/candidates/create', [
            'classOptions' => AcademicOptions::classBatchesByPeriod(),
            ...$this->groupSuggestions(),
        ]);
    }

    public function store(StoreCandidateRequest $request): RedirectResponse
    {
        $candidate = $this->candidates->create($request->candidateData());

        Inertia::flash('toast', ['type' => 'success', 'message' => "Candidate {$candidate->candidate_number} created."]);

        return redirect()->route('candidates.show', $candidate);
    }

    public function show(Request $request, Candidate $candidate, GradeCalculationService $grades, CandidateAcademicRecord $record, CandidateProfileRecord $profile, FitnessResults $fitness, CandidatePerformanceRecord $performanceRecord): Response
    {
        $candidate->load(['user', 'classBatch.academicPeriod']);
        $viewer = $request->user();

        // Viewers without "view all" reach this page by teaching the candidate's
        // class, so they only see the subjects (and grades) they teach there.
        $seesAllSubjects = $viewer->hasPermission(Permission::ViewAllCandidates);

        $offerings = $candidate->classBatch === null ? new EloquentCollection : ClassSubject::query()
            ->where('class_batch_id', $candidate->class_batch_id)
            ->when(! $seesAllSubjects, fn (Builder $offerings) => $offerings->whereHas(
                'instructorAssignments',
                fn (Builder $assignments) => $assignments->where('instructor_id', $viewer->id),
            ))
            ->with(['subject', 'instructors'])
            ->get()
            ->sortBy(fn (ClassSubject $offering): string => $offering->subject->name)
            ->values();

        $subjects = $offerings
            ->map(fn (ClassSubject $offering): array => [
                'code' => $offering->subject->code,
                'name' => $offering->subject->name,
                'instructors' => $offering->instructors->pluck('name')->sort()->values()->all(),
            ])
            ->all();

        // One batched calculation for all subjects, and one lookup of the
        // subjects the viewer teaches (the gradebook access rule).
        $subjectGrades = $grades->forCandidate($candidate, $offerings->pluck('id')->all());
        $taughtOfferingIds = $viewer->canTeach()
            ? $viewer->teachingAssignments()->pluck('class_subject_id')->map(fn (mixed $id): int => (int) $id)->all()
            : [];

        $performance = $offerings
            ->map(fn (ClassSubject $offering): array => [
                'classSubjectId' => $offering->id,
                'code' => $offering->subject->code,
                'name' => $offering->subject->name,
                'result' => $subjectGrades[$offering->id]->toArray(),
                'canOpenGradebook' => in_array($offering->id, $taughtOfferingIds, true),
            ])
            ->all();

        $canManage = $viewer->can('update', $candidate);

        // Over the subjects shown only, so instructors learn nothing about
        // subjects they do not teach.
        $overallStanding = $grades->overallStanding($subjectGrades);
        $thresholds = $candidate->classBatch === null ? null : GradingThresholds::forPeriod($candidate->classBatch->academicPeriod);
        // Withdrawn candidates keep their grades but are not monitored.
        $monitored = $candidate->classBatch !== null && $candidate->isGradableIn($candidate->classBatch->id);
        $subjectResults = $offerings
            ->map(fn (ClassSubject $offering): array => ['offering' => $offering, 'grade' => $subjectGrades[$offering->id]])
            ->all();

        return Inertia::render('staff/candidates/show', [
            'candidate' => [
                ...$this->details($candidate),
                // Sign-in account details are administrative; instructors do not need them.
                'account' => $canManage ? [
                    'username' => $candidate->user->username,
                    'isActive' => $candidate->user->is_active,
                    'lastLoginAt' => $candidate->user->last_login_at?->toIso8601String(),
                ] : null,
                'createdAt' => $candidate->created_at?->toIso8601String(),
                'updatedAt' => $candidate->updated_at?->toIso8601String(),
            ],
            'subjects' => $subjects,
            'performance' => $performance,
            'standing' => [
                'overall' => $overallStanding->toArray(),
                // "all": every subject of the class; "taught": only the viewer's subjects.
                'scope' => $seesAllSubjects ? 'all' : 'taught',
                'thresholds' => $thresholds?->toArray(),
                'monitored' => $monitored,
                'canConfigureThresholds' => $viewer->hasPermission(Permission::ConfigureGrading),
            ],
            // The same definition of a concern as academic monitoring.
            'warnings' => $monitored ? $record->warnings($subjectResults) : [],
            'assessmentResults' => $record->assessmentResults($candidate, $offerings, $monitored),
            'examinationResults' => $profile->examinationResults($candidate, false, $seesAllSubjects ? null : $offerings->modelKeys()),
            'recentActivity' => $record->recentActivity($candidate, $offerings->modelKeys(), $monitored),
            // Military fitness history (newest first), for staff who may view fitness records.
            'fitness' => $viewer->hasPermission(Permission::ViewFitness) ? $fitness->history($candidate, self::FITNESS_HISTORY) : null,
            ...$this->performanceSections($viewer, $candidate, $performanceRecord),
            // Every field for medical staff and, view only, for those who teach the class; null otherwise.
            'medical' => $this->medical($candidate, $viewer),
            'canEdit' => $canManage,
            // Instructors return to the class they teach, not the full candidate list.
            'canBrowseCandidates' => $viewer->can('viewAny', Candidate::class),
            // A new QR code for a lost or shared card (candidates.manage).
            'qrReissueUrl' => $viewer->can('update', $candidate) ? route('candidates.qr.reissue', $candidate) : null,
        ]);
    }

    public function edit(Candidate $candidate): Response
    {
        $candidate->load(['user', 'classBatch.academicPeriod']);

        return Inertia::render('staff/candidates/edit', [
            'candidate' => [
                ...$this->details($candidate),
                'accountActive' => $candidate->user->is_active,
                'username' => $candidate->user->username,
            ],
            'classOptions' => AcademicOptions::classBatchesByPeriod(),
            'statusOptions' => CandidateStatus::options(),
            ...$this->groupSuggestions(),
        ]);
    }

    public function update(UpdateCandidateRequest $request, Candidate $candidate): RedirectResponse
    {
        $this->candidates->update($candidate, $request->candidateData());

        Inertia::flash('toast', ['type' => 'success', 'message' => "Candidate {$candidate->candidate_number} updated."]);

        return redirect()->route('candidates.show', $candidate);
    }

    /**
     * The performance panels of the profile, each null when the viewer may
     * not see it (the page then leaves the panel out):
     *
     * - qualification: users who see every subject of the candidate
     *   (candidates.view_all) or every qualification (performance.view).
     *   Area grades combine every subject of an area and the fitness
     *   results, so instructors, who only see the subjects they teach and
     *   no fitness records, do not get them. The class rank only with
     *   performance.view.
     * - conduct: users who may act on the candidate under the conduct scope,
     *   or who view all candidates.
     * - attendance: users who keep the attendance of the candidate's class,
     *   or who view all candidates.
     *
     * @return array{qualification: array<string, mixed>|null, conduct: array<string, mixed>|null, attendance: array<string, mixed>|null}
     */
    private function performanceSections(User $viewer, Candidate $candidate, CandidatePerformanceRecord $performanceRecord): array
    {
        $seesAllCandidates = $viewer->hasPermission(Permission::ViewAllCandidates);
        $seesRank = $viewer->hasPermission(Permission::ViewPerformance);

        $managesConduct = $viewer->can('manage', [ConductEntry::class, $candidate]);
        $attendanceScope = AttendanceScope::for($viewer);
        $keepsAttendance = $viewer->hasPermission(Permission::ManageAttendance) && $attendanceScope->allowsCandidate($candidate);

        return [
            'qualification' => $seesAllCandidates || $seesRank ? [
                ...$performanceRecord->qualification($candidate, withRank: $seesRank),
                'showRank' => $seesRank,
                'canConfigure' => $viewer->hasPermission(Permission::ConfigurePerformance),
            ] : null,
            'conduct' => $managesConduct || $seesAllCandidates ? [
                ...$performanceRecord->conduct($candidate, self::CONDUCT_HISTORY),
                'canManage' => $managesConduct,
            ] : null,
            'attendance' => $seesAllCandidates || $attendanceScope->allowsCandidate($candidate) ? [
                ...$performanceRecord->attendance($candidate, self::ATTENDANCE_HISTORY),
                'canManage' => $keepsAttendance,
            ] : null,
        ];
    }

    /**
     * Company and platoon names already in use, suggested while typing so
     * the same unit is not entered under different spellings.
     *
     * @return array{companyOptions: list<string>, platoonOptions: list<string>}
     */
    private function groupSuggestions(): array
    {
        return ['companyOptions' => CandidateGroups::companies(), 'platoonOptions' => CandidateGroups::platoons()];
    }

    /**
     * @return array<string, mixed>
     */
    private function details(Candidate $candidate): array
    {
        return CandidatePresenter::details($candidate);
    }

    /**
     * @return array{value: string, label: string, tone: string}
     */
    private function status(CandidateStatus $status): array
    {
        return ['value' => $status->value, 'label' => $status->label(), 'tone' => $status->tone()];
    }

    /**
     * The medical panel; a full record seen through an instructor's approved
     * access is recorded in the audit log (without values).
     *
     * @return array<string, mixed>|null
     */
    private function medical(Candidate $candidate, User $viewer): ?array
    {
        $medical = MedicalRecordPresenter::forStaff($candidate, $viewer);
        if ($medical !== null && $medical['scope'] === 'granted') {
            app(MedicalRecordService::class)->recordInstructorView($candidate, $viewer);
        }

        return $medical;
    }
}
