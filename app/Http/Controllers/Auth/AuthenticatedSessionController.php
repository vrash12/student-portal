<?php

namespace App\Http\Controllers\Auth;

use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use App\Services\AuditLogger;
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

    public function store(LoginRequest $request, AuditLogger $audit): RedirectResponse
    {
        $user = $request->authenticate($audit);

        $request->session()->regenerate();

        User::withoutTimestamps(fn () => $user->forceFill(['last_login_at' => now()])->save());

        $audit->record(AuditAction::Login, $user, actor: $user);

        return redirect()->intended(route('home'));
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
