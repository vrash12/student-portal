<?php

namespace App\Http\Controllers\Staff;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Users\StoreUserRequest;
use App\Http\Requests\Users\UpdateUserRequest;
use App\Models\Role;
use App\Models\User;
use App\Services\UserAccountService;
use App\Support\ListCharts;
use App\Support\QueryFilters;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Staff account management. Candidate accounts are managed with candidate
 * records instead.
 */
class UserController extends Controller
{
    private const PER_PAGE = 10;

    public function __construct(private readonly UserAccountService $accounts) {}

    public function index(Request $request): Response
    {
        $staffRoles = Role::query()->staff()->orderByDesc('rank')->get(['id', 'code', 'name']);
        $filters = [
            'search' => QueryFilters::search($request),
            'role' => QueryFilters::oneOf($request, 'role', $staffRoles->pluck('code')->all()),
            'status' => QueryFilters::oneOf($request, 'status', ['active', 'inactive']),
        ];
        $actor = $request->user();
        // The user's campus, or every campus narrowed by the campus filter (CampusScope).
        $campus = $actor->campusScope()->filteredBy($request);
        $filters['campus'] = $campus->filterValue();

        $query = $campus->constrain(User::query(), 'users.campus_id')
            ->with(['role.permissions', 'campus'])
            ->whereHas('role', fn (Builder $roles) => $roles->staff())
            ->when($filters['search'] !== '', function (Builder $query) use ($filters): void {
                $term = QueryFilters::likeTerm($filters['search']);
                $query->where(fn (Builder $match) => $match
                    ->where('name', 'like', $term)
                    ->orWhere('username', 'like', $term));
            })
            ->when($filters['role'] !== '', fn (Builder $query) => $query->whereRelation('role', 'code', $filters['role']))
            ->when($filters['status'] !== '', fn (Builder $query) => $query->where('is_active', $filters['status'] === 'active'));
        $roleNames = $staffRoles->pluck('name', 'id');
        $charts = [
            ListCharts::pie('Staff by Role', 'Matching staff accounts in each role.',
                ListCharts::countBy($query, 'role_id', fn (mixed $value): string => (string) ($roleNames[$value] ?? 'Other role')), 'account', 'accounts'),
            ListCharts::pie('Account Status', 'Active and inactive matching accounts.',
                ListCharts::countBy($query, 'is_active', fn (mixed $value): string => (bool) $value ? 'Active' : 'Inactive',
                    tone: fn (mixed $value): string => (bool) $value ? 'passing' : 'none'), 'account', 'accounts'),
        ];

        $users = $query
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->username,
                'role' => $user->role->name,
                // Null: the account sees every campus.
                'campus' => $user->campus?->summary(),
                'isActive' => $user->is_active,
                'lastLoginAt' => $user->last_login_at?->toIso8601String(),
                'canEdit' => $actor->can('update', $user),
            ]);

        return Inertia::render('staff/users/index', [
            'users' => $users,
            'charts' => $charts,
            'filters' => $filters,
            'campusOptions' => $campus->filterOptions(),
            'roles' => $staffRoles->map(fn (Role $role): array => ['code' => $role->code, 'name' => $role->name])->all(),
            'canCreate' => $actor->can('create', User::class),
        ]);
    }

    public function create(Request $request): Response
    {
        $actor = $request->user();

        return Inertia::render('staff/users/create', [
            'roles' => $this->assignableRoles($actor),
            ...$this->campusChoices($actor),
        ]);
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        $user = $this->accounts->create($request->newAccountData());

        Inertia::flash('toast', ['type' => 'success', 'message' => "Account created for {$user->name}."]);

        return redirect()->route('users.index');
    }

    public function edit(Request $request, User $user): Response
    {
        $actor = $request->user();
        $user->loadMissing('campus');
        $campusChoices = $this->campusChoices($actor);
        // The campus the account is on stays listed even if it was deactivated.
        if ($user->campus !== null && ! in_array($user->campus_id, array_column($campusChoices['campusOptions'], 'id'), true)) {
            $campusChoices['campusOptions'][] = [...$user->campus->summary(), 'isActive' => $user->campus->is_active];
        }

        return Inertia::render('staff/users/edit', [
            'account' => [
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->username,
                'email' => $user->email,
                'roleId' => $user->role_id,
                'campusId' => $user->campus_id,
                'isActive' => $user->is_active,
                'lastLoginAt' => $user->last_login_at?->toIso8601String(),
                'createdAt' => $user->created_at?->toIso8601String(),
            ],
            // The role is fixed once the account exists; the form shows it, not a choice.
            'roles' => [['id' => $user->role->id, 'name' => $user->role->name, 'description' => $user->role->description, 'requiresCampus' => $user->role->grants(Permission::TeachClasses)]],
            'isOwnAccount' => $actor->is($user),
            ...$campusChoices,
            // Only an administrator of every campus moves another account between campuses.
            'canChangeCampus' => ! $actor->is($user) && $actor->campusScope()->isInstitutionWide(),
        ]);
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $this->accounts->update($user, $request->accountData());

        Inertia::flash('toast', ['type' => 'success', 'message' => "Account updated for {$user->name}."]);

        return redirect()->route('users.index');
    }

    /**
     * Staff roles the acting user may give a new account. Teaching roles
     * require a campus (instructors teach on one campus).
     *
     * @return list<array{id: int, name: string, description: ?string, requiresCampus: bool}>
     */
    private function assignableRoles(User $actor): array
    {
        return Role::query()
            ->staff()
            ->with('permissions')
            ->orderByDesc('rank')
            ->get()
            ->filter(fn (Role $role): bool => $actor->canAssignRole($role))
            ->map(fn (Role $role): array => [
                'id' => $role->id,
                'name' => $role->name,
                'description' => $role->description,
                'requiresCampus' => $role->grants(Permission::TeachClasses),
            ])
            ->values()
            ->all();
    }

    /**
     * Campuses a new or edited account may be placed on, and whether
     * "every campus" can be chosen (only by accounts that see every campus).
     *
     * @return array{campusOptions: list<array{id: int, name: string, code: string, isActive: bool}>, canChooseEveryCampus: bool}
     */
    private function campusChoices(User $actor): array
    {
        $scope = $actor->campusScope();

        return ['campusOptions' => $scope->assignableOptions(), 'canChooseEveryCampus' => $scope->isInstitutionWide()];
    }
}
