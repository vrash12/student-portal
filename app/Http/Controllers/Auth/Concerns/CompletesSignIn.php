<?php

namespace App\Http\Controllers\Auth\Concerns;

use App\Enums\AuditAction;
use App\Enums\Permission;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\UserAccountService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * The last part of every sign-in, after the password (and, for accounts with
 * two-step sign-in, the code): the session, the audit entry and where to go.
 */
trait CompletesSignIn
{
    /**
     * @param  array<string, mixed>  $auditValues
     */
    protected function completeSignIn(Request $request, User $user, AuditLogger $audit, UserAccountService $accounts, array $auditValues = []): RedirectResponse
    {
        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        // Candidates are signed in on one device at a time (owner request,
        // 2026-10-03): this sign-in ends their sessions on other devices.
        $endedSessions = $user->hasPermission(Permission::AccessExamPortal) && ! $user->hasPermission(Permission::AccessStaffArea)
            ? $accounts->endSessions($user, exceptSessionId: $request->session()->getId())
            : 0;

        User::withoutTimestamps(fn () => $user->forceFill(['last_login_at' => now()])->save());

        if ($endedSessions > 0) {
            $auditValues['other_sessions_ended'] = $endedSessions;
        }
        $audit->record(AuditAction::Login, $user, newValues: $auditValues, actor: $user);

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
}
