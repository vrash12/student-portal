<?php

namespace App\Http\Controllers\Staff;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\AcademicPeriod;
use App\Models\Role;
use App\Models\User;
use App\Services\AdministratorDashboardService;
use App\Services\Attendance\AttendanceLedger;
use App\Services\Attendance\AttendanceScope;
use App\Services\Backups\BackupManager;
use App\Services\Grading\GradingThresholds;
use App\Services\Monitoring\AcademicMonitoring;
use App\Services\Monitoring\MonitoredCandidate;
use App\Services\Monitoring\MonitoringPresenter;
use App\Services\Monitoring\MonitoringScope;
use App\Services\Performance\QualificationOverview;
use App\Services\TeachingOverview;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * One dashboard for all staff. Sections are included by permission, never by
 * role name: teaching staff see their own assignments, administrators see
 * institution-wide information. Monitoring panels also need
 * academic_monitoring.view, the permission of the page they link to.
 */
class DashboardController extends Controller
{
    private const ATTENTION_LIMIT = 5;

    public function __construct(
        private readonly AcademicMonitoring $monitoring,
        private readonly MonitoringPresenter $presenter,
    ) {}

    public function __invoke(Request $request, TeachingOverview $teaching, AdministratorDashboardService $administratorDashboard, QualificationOverview $qualification, AttendanceLedger $attendance): Response
    {
        $user = $request->user();
        $canMonitor = $user->hasPermission(Permission::ViewAcademicMonitoring);
        $showAcademicOverview = $canMonitor && $user->hasPermission(Permission::ViewAllCandidates);
        $showAcademicAlerts = $canMonitor && $user->canTeach();
        // The qualification of every candidate: the permission of /qualification.
        $showQualification = $user->hasPermission(Permission::ViewPerformance);

        return Inertia::render('staff/dashboard', [
            'teaching' => $user->canTeach() ? $teaching->dashboard($user) : null,
            'showAcademicOverview' => $showAcademicOverview,
            // Institution-wide standings of the active period (null: no active period).
            'academicOverview' => $showAcademicOverview ? $this->monitoringSummary(MonitoringScope::for($user), $user) : null,
            'showAcademicAlerts' => $showAcademicAlerts,
            // Standings over the subjects the user teaches in the active period.
            'academicAlerts' => $showAcademicAlerts ? $this->monitoringSummary(MonitoringScope::teaching($user), $user, withSubjects: true) : null,
            'thresholdSetup' => $user->hasPermission(Permission::ConfigureGrading) ? $this->missingThresholds() : null,
            'accountSummary' => $user->can('viewAny', User::class) ? $this->accountSummary() : null,
            'administratorOverview' => $showAcademicOverview ? $administratorDashboard->overview() : null,
            'showQualification' => $showQualification,
            // Backup problems, for whoever looks after backups (the Admin).
            'backupWarnings' => $user->hasPermission(Permission::ManageBackups) ? app(BackupManager::class)->status()['warnings'] : [],
            // Qualification across the active period's classes (null: no active period).
            'qualificationOverview' => $showQualification ? $qualification->activePeriod() : null,
            'canConfigurePerformance' => $user->hasPermission(Permission::ConfigurePerformance),
            // Attendance of the active period in the user's attendance scope (null: nothing recorded or no scope).
            'attendanceTrend' => $this->attendanceTrend($user, $attendance),
        ]);
    }

    /**
     * Attendance per training day of the active period's classes in the
     * user's attendance scope (every class, or the classes they teach), with
     * the records by status. Null without that scope, without an active
     * period, or before anything is recorded.
     *
     * @return array<string, mixed>|null
     */
    private function attendanceTrend(User $user, AttendanceLedger $attendance): ?array
    {
        $scope = AttendanceScope::for($user);
        $period = $scope->isEmpty() ? null : AcademicPeriod::query()->active()->first();
        if ($period === null) {
            return null;
        }

        $classIds = $scope->classes()->where('academic_period_id', $period->id)->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $days = $attendance->dailyRates($classIds);
        if ($days === []) {
            return null;
        }

        return [
            'period' => ['id' => $period->id, 'name' => $period->name],
            // "all": every class; "taught": only the classes the user teaches.
            'scope' => $scope->allClasses ? 'all' : 'taught',
            'days' => $days,
            'totals' => $attendance->statusTotals($classIds),
        ];
    }

    /**
     * Counts and the candidates requiring attention in the active period,
     * with only the fields a dashboard shows; with `$withSubjects`, also the
     * standing counts and mean grade of each class subject in scope.
     *
     * @return array<string, mixed>|null
     */
    private function monitoringSummary(MonitoringScope $scope, User $viewer, bool $withSubjects = false): ?array
    {
        $summary = $this->monitoring->activePeriodSummary($scope, self::ATTENTION_LIMIT, $withSubjects);
        if ($summary === null) {
            return null;
        }

        if ($withSubjects) {
            $summary['subjects'] = array_map(fn (array $row): array => [
                'classSubjectId' => $row['id'],
                'classId' => $row['classId'],
                'subject' => $row['subject'],
                'classBatch' => $row['classBatch'],
                'average' => $row['average'],
                'counts' => [
                    'passing' => $row['passing'], 'atRisk' => $row['at_risk'], 'failing' => $row['failing'],
                    'incomplete' => $row['incomplete'], 'noStanding' => $row['none'],
                ],
            ], $summary['subjects']);
        }

        $taught = $viewer->canTeach()
            ? $viewer->teachingAssignments()->pluck('class_subject_id')->map(fn (mixed $id): int => (int) $id)->all()
            : [];

        return [
            ...$summary,
            'requiringAttention' => array_map(
                fn (MonitoredCandidate $entry): array => $this->presenter->summary($entry, $taught),
                $summary['requiringAttention'],
            ),
        ];
    }

    /**
     * The active period when it has no passing and warning grades yet, so
     * users who can set them are pointed to the page. Null otherwise.
     *
     * @return array{periodId: int, periodName: string}|null
     */
    private function missingThresholds(): ?array
    {
        $period = AcademicPeriod::query()->active()->first();

        return $period === null || GradingThresholds::forPeriod($period) !== null
            ? null
            : ['periodId' => $period->id, 'periodName' => $period->name];
    }

    /**
     * Active accounts per role, from the database.
     *
     * @return list<array{code: string, name: string, activeUsers: int}>
     */
    private function accountSummary(): array
    {
        return Role::query()
            ->withCount(['users as active_users_count' => fn (Builder $users) => $users->where('is_active', true)])
            ->orderByDesc('rank')
            ->get()
            ->map(fn (Role $role): array => [
                'code' => $role->code,
                'name' => $role->name,
                'activeUsers' => (int) $role->active_users_count,
            ])
            ->values()
            ->all();
    }
}
