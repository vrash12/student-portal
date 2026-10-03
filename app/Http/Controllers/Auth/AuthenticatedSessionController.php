<?php

namespace App\Http\Controllers\Auth;

use App\Enums\AuditAction;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\UserAccountService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('auth/login');
    }

    public function store(LoginRequest $request, AuditLogger $audit, UserAccountService $accounts): RedirectResponse
    {
        $user = $request->authenticate($audit);

        $request->session()->regenerate();

        // Candidates are signed in on one device at a time (owner request,
        // 2026-10-03): this sign-in ends their sessions on other devices.
        $endedSessions = $user->hasPermission(Permission::AccessExamPortal) && ! $user->hasPermission(Permission::AccessStaffArea)
            ? $accounts->endSessions($user, exceptSessionId: $request->session()->getId())
            : 0;

        User::withoutTimestamps(fn () => $user->forceFill(['last_login_at' => now()])->save());

        $audit->record(AuditAction::Login, $user, newValues: $endedSessions > 0 ? ['other_sessions_ended' => $endedSessions] : [], actor: $user);

        return redirect()->to($this->intendedUrlFor($user, $request) ?? route('home'));
    }

    /**
     * The page the user tried to open before signing in, only when it is in
     * their own area: on a shared tablet, a staff page remembered from an
     * expired staff session must not send the next (candidate) user to an
     * access-denied page, and vice versa.
     */
    private function intendedUrlFor(User $user, Request $request): ?string
    {
        $intended = $request->session()->pull('url.intended');
        if (! is_string($intended) || parse_url($intended, PHP_URL_HOST) !== $request->getHost()) {
            return null;
        }

        $path = trim((string) parse_url($intended, PHP_URL_PATH), '/');
        $isPortal = $path === 'portal' || str_starts_with($path, 'portal/');

        return $user->hasPermission($isPortal ? Permission::AccessExamPortal : Permission::AccessStaffArea) ? $intended : null;
    }

    public function destroy(Request $request, AuditLogger $audit): RedirectResponse
    {
        $audit->record(AuditAction::Logout, $request->user());

        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
