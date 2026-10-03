<?php

namespace App\Http\Middleware;

use App\Enums\Permission;
use App\Models\User;
use App\Support\PublicAsset;
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
                // Local images carry a version tag so a replaced file shows at once (PublicAsset).
                'logoUrl' => PublicAsset::url(config('institution.logo_url')),
                'loginImageUrl' => PublicAsset::url(config('institution.login_image_url')),
                'loginCompactImageUrl' => config('institution.login_image_url') === null ? null : PublicAsset::url(config('institution.login_compact_image_url')),
                'login' => [
                    'headerTitle' => config('institution.login.header_title') ?? config('institution.organization_name'),
                    'headerSubtitle' => config('institution.login.header_subtitle') ?? config('institution.short_name'),
                    'cardTitle' => config('institution.login.card_title') ?? config('institution.system_name'),
                    'coreValues' => config('institution.login.core_values'),
                    'motto' => config('institution.login.motto'),
                    'tagline' => config('institution.login.tagline'),
                    'helpDesk' => config('institution.login.help_desk'),
                ],
                'poweredBy' => filled(config('institution.powered_by_name'))
                    ? ['name' => config('institution.powered_by_name'), 'logoUrl' => PublicAsset::url(config('institution.powered_by_logo_url') ?: null)]
                    : null,
                // Candidate portal pages that the institution may turn off.
                'portal' => [
                    'showFitness' => (bool) config('institution.portal.show_fitness'),
                ],
                'timezone' => config('institution.timezone'),
                'currency' => config('institution.currency'),
            ],
            'auth' => fn (): array => $this->authPayload($request->user()),
        ];
    }

    /**
     * @return array{user: array{id: int, name: string, username: string, role: array{code: string, name: string}, candidate?: array{firstName: string, photoUrl: ?string}|null}|null, permissions: list<string>}
     */
    private function authPayload(?User $user): array
    {
        if ($user === null) {
            return ['user' => null, 'permissions' => []];
        }

        $user->loadMissing('role.permissions');
        $payload = [
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'role' => [
                'code' => $user->role->code,
                'name' => $user->role->name,
            ],
        ];
        // The portal header shows the candidate's first name and picture
        // (owner request, 2026-10-03); only for candidate accounts.
        if ($user->hasPermission(Permission::AccessExamPortal)) {
            $candidate = $user->candidate()->first(['id', 'user_id', 'first_name', 'profile_photo_path']);
            $payload['candidate'] = $candidate === null ? null : [
                'firstName' => $candidate->first_name,
                'photoUrl' => $candidate->profile_photo_path === null ? null : route('portal.profile.photo'),
            ];
        }

        return [
            'user' => $payload,
            'permissions' => $user->permissionCodes(),
        ];
    }
}
