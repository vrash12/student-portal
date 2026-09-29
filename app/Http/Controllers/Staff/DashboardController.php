<?php

namespace App\Http\Controllers\Staff;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\AcademicPeriod;
use App\Models\Role;
use App\Models\User;
use App\Services\Grading\GradingThresholds;
use App\Services\TeachingOverview;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * One dashboard for all staff. Sections are included by permission, never by
 * role name: teaching staff see their own assignments, administrators see
 * institution-wide information.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request, TeachingOverview $teaching): Response
    {
        $user = $request->user();

        return Inertia::render('staff/dashboard', [
            'teaching' => $user->canTeach() ? $teaching->dashboard($user) : null,
            'showAcademicOverview' => $user->hasPermission(Permission::ViewAllCandidates),
            'thresholdSetup' => $user->hasPermission(Permission::ConfigureGrading) ? $this->missingThresholds() : null,
            'accountSummary' => $user->can('viewAny', User::class) ? $this->accountSummary() : null,
        ]);
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
