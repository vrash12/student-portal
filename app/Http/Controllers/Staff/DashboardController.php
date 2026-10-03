<?php

namespace App\Http\Controllers\Staff;

use App\Enums\Permission;
use App\Enums\SystemRole;
use App\Http\Controllers\Controller;
use App\Models\AcademicPeriod;
use App\Models\Candidate;
use App\Models\ClassSubject;
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
use App\Support\CampusScope;
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
        // The user's campus, or every campus narrowed by the campus filter (CampusScope).
        $campus = $user->campusScope()->filteredBy($request);

        return Inertia::render('staff/dashboard', [
            'teaching' => $user->canTeach() ? $teaching->dashboard($user) : null,
            // Accounts that see every campus may look at one campus at a time.
            'campusFilter' => ['value' => $campus->filterValue(), 'options' => $campus->filterOptions()],
            'showAcademicOverview' => $showAcademicOverview,
            // Standings of the active period on the campus scope (null: no active period).
            'academicOverview' => $showAcademicOverview ? $this->monitoringSummary(MonitoringScope::for($user)->filteredBy($request), $user, campus: $campus) : null,
            'showAcademicAlerts' => $showAcademicAlerts,
            // Standings over the subjects the user teaches in the active period.
            'academicAlerts' => $showAcademicAlerts ? $this->monitoringSummary(MonitoringScope::teaching($user), $user, withSubjects: true) : null,
            // Passing grades apply to every campus: only accounts that see every campus set them.
            'thresholdSetup' => $user->hasPermission(Permission::ConfigureGrading) && $campus->isInstitutionWide() ? $this->missingThresholds() : null,
            // Subjects of the active period without weights: instructors cannot grade them yet.
            'missingWeights' => $user->hasPermission(Permission::ConfigureGrading) ? $this->missingWeights($campus) : null,
            'accountSummary' => $user->can('viewAny', User::class) ? $this->accountSummary($campus) : null,
            'administratorOverview' => $showAcademicOverview ? $administratorDashboard->overview($campus) : null,
            'showQualification' => $showQualification,
            // Backup problems, for whoever looks after backups (the Admin).
            'backupWarnings' => $user->hasPermission(Permission::ManageBackups) ? app(BackupManager::class)->status()['warnings'] : [],
            // Qualification across the active period's classes (null: no active period).
            'qualificationOverview' => $showQualification ? $qualification->activePeriod($campus) : null,
            'canConfigurePerformance' => $user->hasPermission(Permission::ConfigurePerformance),
            // Attendance of the active period in the user's attendance scope (null: nothing recorded or no scope).
            'attendanceTrend' => $this->attendanceTrend($user, $attendance, $request),
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
    private function attendanceTrend(User $user, AttendanceLedger $attendance, Request $request): ?array
    {
        $scope = AttendanceScope::for($user)->filteredBy($request);
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
    private function monitoringSummary(MonitoringScope $scope, User $viewer, bool $withSubjects = false, ?CampusScope $campus = null): ?array
    {
        $summary = $this->monitoring->activePeriodSummary($scope, self::ATTENTION_LIMIT, $withSubjects);
        if ($summary === null) {
            return null;
        }

        // Class names with the campus code when the overview spans several campuses.
        $classLabels = $campus?->classLabels($summary['period']['id']) ?? [];

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
            'requiringAttention' => array_map(function (MonitoredCandidate $entry) use ($taught, $classLabels): array {
                $row = $this->presenter->summary($entry, $taught);
                $row['classBatch']['name'] = $classLabels[$row['classBatch']['id']] ?? $row['classBatch']['name'];

                return $row;
            }, $summary['requiringAttention']),
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
     * How many subjects of the active period's classes have no weights yet
     * (instructors cannot create assessments in them). Null when there is no
     * active period or every subject has weights.
     *
     * @return array{count: int, periodId: int, periodName: string}|null
     */
    private function missingWeights(CampusScope $campus): ?array
    {
        $period = AcademicPeriod::query()->active()->first();
        if ($period === null) {
            return null;
        }

        $count = $campus->constrain(ClassSubject::query(), 'class_subjects.campus_id')
            ->whereHas('classBatch', fn (Builder $classes) => $classes->where('academic_period_id', $period->id))
            ->whereDoesntHave('assessmentCategories')
            ->count();

        return $count === 0 ? null : ['count' => $count, 'periodId' => $period->id, 'periodName' => $period->name];
    }

    /**
     * Active accounts per role on the campus scope: staff of the campus and
     * candidates of the campus. Accounts that see every campus are counted
     * only when the scope is every campus.
     *
     * @return list<array{code: string, name: string, activeUsers: int}>
     */
    private function accountSummary(CampusScope $campus): array
    {
        return Role::query()
            ->withCount(['users as active_users_count' => fn (Builder $users) => $users
                ->where('is_active', true)
                ->when($campus->campusId !== null, fn (Builder $inCampus) => $inCampus->where(fn (Builder $either) => $either
                    ->where('users.campus_id', $campus->campusId)
                    ->orWhereIn('users.id', Candidate::query()->select('user_id')->where('campus_id', $campus->campusId))))])
            ->orderByDesc('rank')
            ->get()
            // Retired roles (Finance Officer) are left out once nobody has them.
            ->filter(fn (Role $role): bool => SystemRole::tryFrom($role->code) !== null || (int) $role->active_users_count > 0)
            ->map(fn (Role $role): array => [
                'code' => $role->code,
                'name' => $role->name,
                'activeUsers' => (int) $role->active_users_count,
            ])
            ->values()
            ->all();
    }
}
