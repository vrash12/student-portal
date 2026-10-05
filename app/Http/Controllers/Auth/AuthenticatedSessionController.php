<?php

namespace App\Http\Controllers\Auth;

use App\Enums\AuditAction;
use App\Http\Controllers\Auth\Concerns\CompletesSignIn;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Services\AuditLogger;
use App\Services\Auth\PendingTwoFactorSignIn;
use App\Services\UserAccountService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    use CompletesSignIn;

    public function create(): Response
    {
        return Inertia::render('auth/login');
    }

    public function store(LoginRequest $request, AuditLogger $audit, UserAccountService $accounts): RedirectResponse
    {
        $user = $request->authenticate($audit);

        // Two-step sign-in: the password was right; the code comes next.
        if ($user->hasTwoFactorEnabled()) {
            $request->session()->regenerate();
            PendingTwoFactorSignIn::start($request, $user);

            return redirect()->route('two-factor.challenge');
        }

        return $this->completeSignIn($request, $user, $audit, $accounts);
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
