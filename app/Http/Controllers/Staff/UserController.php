<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\Users\StoreUserRequest;
use App\Http\Requests\Users\UpdateUserRequest;
use App\Models\Role;
use App\Models\User;
use App\Services\UserAccountService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
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
        $staffRoles = Role::query()->staff()->orderBy('id')->get(['id', 'code', 'name']);
        $filters = $this->filters($request, $staffRoles->pluck('code')->all());
        $actor = $request->user();

        $users = User::query()
            ->with('role.permissions')
            ->whereHas('role', fn (Builder $roles) => $roles->staff())
            ->when($filters['search'] !== '', function (Builder $query) use ($filters): void {
                $term = '%'.addcslashes($filters['search'], '%_\\').'%';
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
            'roles' => $this->assignableRoles($request->user()),
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
     * Staff roles the acting user is allowed to grant.
     *
     * @return list<array{id: int, name: string, description: ?string}>
     */
    private function assignableRoles(User $actor): array
    {
        return Role::query()
            ->staff()
            ->with('permissions')
            ->orderBy('id')
            ->get()
            ->filter(fn (Role $role): bool => $actor->canGrantRole($role))
            ->map(fn (Role $role): array => [
                'id' => $role->id,
                'name' => $role->name,
                'description' => $role->description,
            ])
            ->values()
            ->all();
    }

    /**
     * Query-string filters, normalized. Unknown values are ignored rather
     * than trusted.
     *
     * @param  list<string>  $roleCodes
     * @return array{search: string, role: string, status: string}
     */
    private function filters(Request $request, array $roleCodes): array
    {
        $search = Str::limit(trim((string) $request->query('search', '')), 100, '');
        $role = (string) $request->query('role', '');
        $status = (string) $request->query('status', '');

        return [
            'search' => $search,
            'role' => in_array($role, $roleCodes, true) ? $role : '',
            'status' => in_array($status, ['active', 'inactive'], true) ? $status : '',
        ];
    }
}
