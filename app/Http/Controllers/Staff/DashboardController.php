<?php

namespace App\Http\Controllers\Staff;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
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
            'accountSummary' => $user->can('viewAny', User::class) ? $this->accountSummary() : null,
        ]);
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
