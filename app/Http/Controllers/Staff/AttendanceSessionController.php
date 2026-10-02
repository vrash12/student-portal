<?php

namespace App\Http\Controllers\Staff;

use App\Enums\AttendanceStatus;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\AttendanceSessionRequest;
use App\Http\Requests\Attendance\RecordAttendanceRequest;
use App\Models\AttendanceSession;
use App\Models\ClassBatch;
use App\Services\Attendance\AttendanceLedger;
use App\Services\Attendance\AttendanceScope;
use App\Services\Attendance\AttendanceService;
use App\Support\QueryFilters;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Training sessions and their attendance (attendance.manage, enforced by
 * route middleware). Everything is scoped by class (AttendanceScope): users
 * who can view all candidates act on every class, others only on the
 * classes they teach; a session of another class is forbidden.
 */
class AttendanceSessionController extends Controller
{
    private const PER_PAGE = 10;

    public function __construct(
        private readonly AttendanceService $attendance,
        private readonly AttendanceLedger $ledger,
    ) {}

    public function index(Request $request): Response
    {
        $scope = AttendanceScope::for($request->user());
        $periods = $scope->periods();
        $periodIds = array_column($periods, 'id');
        $requestedPeriod = QueryFilters::id($request, 'period');
        $filters = [
            // The active (first) period by default; unknown periods show the default.
            'period' => in_array((int) $requestedPeriod, $periodIds, true) ? $requestedPeriod : (string) ($periodIds[0] ?? ''),
            'class' => QueryFilters::id($request, 'class'),
        ];

        $classes = $scope->classes()->where('academic_period_id', (int) $filters['period'])->orderBy('name')->get(['id', 'name']);
        if (! $classes->contains('id', (int) $filters['class'])) {
            $filters['class'] = '';
        }

        $sessions = AttendanceSession::query()
            ->whereIn('class_batch_id', $filters['class'] !== '' ? [(int) $filters['class']] : $classes->modelKeys())
            ->with('classBatch:id,name')
            ->orderByDesc('held_on')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $counts = $this->ledger->sessionCounts($sessions->getCollection()->modelKeys());
        $rosterSizes = $this->ledger->rosterSizes($sessions->getCollection()->pluck('class_batch_id')->unique()->values()->all());

        return Inertia::render('staff/attendance/index', [
            'sessions' => $sessions->through(fn (AttendanceSession $session): array => [
                'id' => $session->id,
                'title' => $session->title,
                'heldOn' => $session->held_on->toDateString(),
                'hours' => (float) $session->hours,
                'classBatch' => ['id' => $session->classBatch->id, 'name' => $session->classBatch->name],
                'counts' => $counts[$session->id],
                'rosterCount' => $rosterSizes[$session->class_batch_id] ?? 0,
            ]),
            'filters' => $filters,
            'periods' => $periods,
            'classes' => $classes->map(fn (ClassBatch $class): array => ['id' => $class->id, 'name' => $class->name])->values()->all(),
            // "all": every class; "taught": only the classes the user teaches.
            'scope' => $scope->allClasses ? 'all' : 'taught',
            'can' => [
                'create' => $scope->classes()->exists(),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        $scope = AttendanceScope::for($request->user());
        $classOptions = $scope->classOptions();
        $requestedClass = (int) QueryFilters::id($request, 'class');
        $available = collect($classOptions)->flatMap(fn (array $group): array => array_column($group['classes'], 'id'))->all();

        return Inertia::render('staff/attendance/sessions/create', [
            'classOptions' => $classOptions,
            // Pre-selects the class the list was filtered by, when the user may use it.
            'selectedClassId' => in_array($requestedClass, $available, true) ? $requestedClass : null,
            'scope' => $scope->allClasses ? 'all' : 'taught',
            'today' => now()->timezone((string) config('institution.timezone'))->toDateString(),
        ]);
    }

    public function store(AttendanceSessionRequest $request): RedirectResponse
    {
        $classBatch = ClassBatch::query()->findOrFail((int) $request->validated('class_batch_id'));
        Gate::authorize('create', [AttendanceSession::class, $classBatch]);

        $session = $this->attendance->create($classBatch, $request->details(), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => "Session {$session->title} created. Record the attendance below."]);

        return redirect()->route('attendance.sessions.show', $session);
    }

    public function show(Request $request, AttendanceSession $attendanceSession): Response
    {
        Gate::authorize('manage', $attendanceSession);
        $attendanceSession->load(['classBatch.academicPeriod', 'creator:id,name']);
        $roll = $this->ledger->rollCall($attendanceSession);
        $hasRecords = $roll['counts']['present'] + $roll['counts']['late'] + $roll['counts']['excused'] + $roll['counts']['absent'] > 0;

        return Inertia::render('staff/attendance/sessions/show', [
            'session' => [
                ...$this->details($attendanceSession),
                'createdBy' => $attendanceSession->creator->name,
            ],
            'rows' => $roll['rows'],
            'counts' => $roll['counts'],
            'statusOptions' => AttendanceStatus::options(),
            'can' => [
                'delete' => ! $hasRecords,
                'viewAllCandidates' => $request->user()->hasPermission(Permission::ViewAllCandidates),
            ],
            // The camera scanner of QR codes (owner request, 2026-10-02).
            'scanUrl' => route('attendance.sessions.scan', $attendanceSession),
            'today' => now()->timezone((string) config('institution.timezone'))->toDateString(),
        ]);
    }

    public function edit(AttendanceSession $attendanceSession): Response
    {
        Gate::authorize('manage', $attendanceSession);
        $attendanceSession->load('classBatch.academicPeriod');

        return Inertia::render('staff/attendance/sessions/edit', [
            'session' => $this->details($attendanceSession),
            'today' => now()->timezone((string) config('institution.timezone'))->toDateString(),
        ]);
    }

    public function update(AttendanceSessionRequest $request, AttendanceSession $attendanceSession): RedirectResponse
    {
        $this->attendance->update($attendanceSession, $request->details());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Session details saved.']);

        return redirect()->route('attendance.sessions.show', $attendanceSession);
    }

    public function destroy(AttendanceSession $attendanceSession): RedirectResponse
    {
        Gate::authorize('manage', $attendanceSession);
        $this->attendance->delete($attendanceSession);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Session {$attendanceSession->title} deleted."]);

        return redirect()->route('attendance.index');
    }

    public function recordAttendance(RecordAttendanceRequest $request, AttendanceSession $attendanceSession): RedirectResponse
    {
        $changed = $this->attendance->record($attendanceSession, $request->entries(), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => match ($changed) {
            0 => 'No attendance changed.',
            1 => 'Attendance of 1 candidate saved.',
            default => "Attendance of {$changed} candidates saved.",
        }]);

        return redirect()->route('attendance.sessions.show', $attendanceSession);
    }

    /**
     * @return array{id: int, title: string, heldOn: string, hours: float, notes: ?string, classBatch: array{id: int, name: string, period: string}}
     */
    private function details(AttendanceSession $session): array
    {
        return [
            'id' => $session->id,
            'title' => $session->title,
            'heldOn' => $session->held_on->toDateString(),
            'hours' => (float) $session->hours,
            'notes' => $session->notes,
            'classBatch' => [
                'id' => $session->classBatch->id,
                'name' => $session->classBatch->name,
                'period' => $session->classBatch->academicPeriod->name,
            ],
        ];
    }
}
