<?php

namespace App\Http\Middleware;

use App\Services\Auth\TwoFactorService;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * A staff member for whom two-step sign-in is required
 * (config two_factor.required_for) must turn it on before using the staff
 * area. Only its page, the password page and signing out stay available.
 */
class EnsureTwoFactorIsEnabled
{
    private const ALLOWED_ROUTES = ['account.two-factor.*', 'account.password.*', 'logout'];

    public function __construct(private readonly TwoFactorService $twoFactor) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ! $user->hasTwoFactorEnabled() && ! $request->routeIs(...self::ALLOWED_ROUTES) && $this->twoFactor->isRequired($user)) {
            if ($request->expectsJson()) {
                abort(403, 'Turn on two-step sign-in before continuing.');
            }

            Inertia::flash('toast', ['type' => 'info', 'message' => 'Two-step sign-in is required for your account. Set it up to continue.']);

            return redirect()->route('account.two-factor.show');
        }

        return $next($request);
    }
}
