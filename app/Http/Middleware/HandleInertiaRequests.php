<?php

namespace App\Http\Middleware;

use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Props shared with every page.
     *
     * Permission codes are shared only to decide what navigation to show.
     * Every route and action is authorized again on the server.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'app' => [
                'name' => config('institution.system_name'),
                'shortName' => config('institution.short_name'),
                'organizationName' => config('institution.organization_name'),
                'logoUrl' => config('institution.logo_url'),
                'loginImageUrl' => config('institution.login_image_url'),
                'poweredBy' => filled(config('institution.powered_by_name'))
                    ? ['name' => config('institution.powered_by_name'), 'logoUrl' => config('institution.powered_by_logo_url') ?: null]
                    : null,
                'timezone' => config('institution.timezone'),
                'currency' => config('institution.currency'),
            ],
            'auth' => fn (): array => $this->authPayload($request->user()),
        ];
    }

    /**
     * @return array{user: array{id: int, name: string, username: string, role: array{code: string, name: string}}|null, permissions: list<string>}
     */
    private function authPayload(?User $user): array
    {
        if ($user === null) {
            return ['user' => null, 'permissions' => []];
        }

        $user->loadMissing('role.permissions');

        return [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->username,
                'role' => [
                    'code' => $user->role->code,
                    'name' => $user->role->name,
                ],
            ],
            'permissions' => $user->permissionCodes(),
        ];
    }
}
