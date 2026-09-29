<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\Users\StoreUserRequest;
use App\Http\Requests\Users\UpdateUserRequest;
use App\Models\Role;
use App\Models\User;
use App\Services\UserAccountService;
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
    private const PER_PAGE = 15;

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

        $users = User::query()
            ->with('role.permissions')
            ->whereHas('role', fn (Builder $roles) => $roles->staff())
            ->when($filters['search'] !== '', function (Builder $query) use ($filters): void {
                $term = QueryFilters::likeTerm($filters['search']);
                $query->where(fn (Builder $match) => $match
                    ->where('name', 'like', $term)
                    ->orWhere('username', 'like', $term));
            })
            ->when($filters['role'] !== '', fn (Builder $query) => $query->whereRelation('role', 'code', $filters['role']))
            ->when($filters['status'] !== '', fn (Builder $query) => $query->where('is_active', $filters['status'] === 'active'))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->username,
                'role' => $user->role->name,
                'isActive' => $user->is_active,
                'lastLoginAt' => $user->last_login_at?->toIso8601String(),
                'canEdit' => $actor->can('update', $user),
            ]);

        return Inertia::render('staff/users/index', [
            'users' => $users,
            'filters' => $filters,
            'roles' => $staffRoles->map(fn (Role $role): array => ['code' => $role->code, 'name' => $role->name])->all(),
            'canCreate' => $actor->can('create', User::class),
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('staff/users/create', [
            'roles' => $this->assignableRoles($request->user()),
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
        return Inertia::render('staff/users/edit', [
            'account' => [
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->username,
                'email' => $user->email,
                'roleId' => $user->role_id,
                'isActive' => $user->is_active,
                'lastLoginAt' => $user->last_login_at?->toIso8601String(),
                'createdAt' => $user->created_at?->toIso8601String(),
            ],
            'roles' => $this->assignableRoles($request->user(), keepRoleId: $user->role_id),
            'isOwnAccount' => $request->user()->is($user),
        ]);
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $this->accounts->update($user, $request->accountData());

        Inertia::flash('toast', ['type' => 'success', 'message' => "Account updated for {$user->name}."]);

        return redirect()->route('users.index');
    }

    /**
     * Staff roles the acting user may assign, plus the account's current role
     * when editing (keeping a role is always allowed).
     *
     * @return list<array{id: int, name: string, description: ?string}>
     */
    private function assignableRoles(User $actor, ?int $keepRoleId = null): array
    {
        return Role::query()
            ->staff()
            ->orderByDesc('rank')
            ->get()
            ->filter(fn (Role $role): bool => $role->id === $keepRoleId || $actor->canAssignRole($role))
            ->map(fn (Role $role): array => [
                'id' => $role->id,
                'name' => $role->name,
                'description' => $role->description,
            ])
            ->values()
            ->all();
    }
}
