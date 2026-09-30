<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * A staff member whose password was set by an administrator must choose a
 * new one before using the staff area. Only the password page and signing
 * out stay available.
 */
class EnsurePasswordIsCurrent
{
    private const ALLOWED_ROUTES = ['account.password.edit', 'account.password.update', 'logout'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $user->password_change_required && ! $request->routeIs(...self::ALLOWED_ROUTES)) {
            if ($request->expectsJson()) {
                abort(403, 'Choose a new password before continuing.');
            }

            Inertia::flash('toast', ['type' => 'info', 'message' => 'Your password was set by an administrator. Choose your own password to continue.']);

            return redirect()->route('account.password.edit');
        }

        return $next($request);
    }
}
