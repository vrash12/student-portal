<?php

namespace App\Http\Controllers\Staff;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\AcademicPeriod;
use App\Models\Role;
use App\Models\User;
use App\Services\Grading\GradingThresholds;
use App\Services\Monitoring\AcademicMonitoring;
use App\Services\Monitoring\MonitoredCandidate;
use App\Services\Monitoring\MonitoringPresenter;
use App\Services\Monitoring\MonitoringScope;
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

    public function __invoke(Request $request, TeachingOverview $teaching): Response
    {
        $user = $request->user();
        $canMonitor = $user->hasPermission(Permission::ViewAcademicMonitoring);
        $showAcademicOverview = $canMonitor && $user->hasPermission(Permission::ViewAllCandidates);
        $showAcademicAlerts = $canMonitor && $user->canTeach();

        return Inertia::render('staff/dashboard', [
            'teaching' => $user->canTeach() ? $teaching->dashboard($user) : null,
            'showAcademicOverview' => $showAcademicOverview,
            // Institution-wide standings of the active period (null: no active period).
            'academicOverview' => $showAcademicOverview ? $this->monitoringSummary(MonitoringScope::for($user), $user) : null,
            'showAcademicAlerts' => $showAcademicAlerts,
            // Standings over the subjects the user teaches in the active period.
            'academicAlerts' => $showAcademicAlerts ? $this->monitoringSummary(MonitoringScope::teaching($user), $user) : null,
            'thresholdSetup' => $user->hasPermission(Permission::ConfigureGrading) ? $this->missingThresholds() : null,
            'accountSummary' => $user->can('viewAny', User::class) ? $this->accountSummary() : null,
        ]);
    }

    /**
     * Counts and the candidates requiring attention in the active period,
     * with only the fields a dashboard shows.
     *
     * @return array<string, mixed>|null
     */
    private function monitoringSummary(MonitoringScope $scope, User $viewer): ?array
    {
        $summary = $this->monitoring->activePeriodSummary($scope, self::ATTENTION_LIMIT);
        if ($summary === null) {
            return null;
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
