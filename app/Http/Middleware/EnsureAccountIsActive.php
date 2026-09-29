<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signs out an authenticated user whose account was deactivated after they
 * signed in, so deactivation takes effect on their next request.
 */
class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ! $user->is_active) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            Inertia::flash('toast', [
                'type' => 'error',
                'message' => 'Your account has been deactivated. Contact an administrator.',
            ]);

            return redirect()->route('login');
        }

        return $next($request);
    }
}
