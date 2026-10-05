<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\Users\ResetTwoFactorRequest;
use App\Models\User;
use App\Services\Auth\TwoFactorService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * An administrator turns off another staff member's two-step sign-in, for
 * a lost phone without recovery codes. Same rule as editing the account
 * (UserPolicy::update: lower-ranked roles, own campus), never one's own:
 * that is done on the Two-Step Sign-In page with the password.
 */
class UserTwoFactorController extends Controller
{
    public function destroy(ResetTwoFactorRequest $request, User $user, TwoFactorService $twoFactor): RedirectResponse
    {
        if ($user->hasTwoFactorEnabled() || $user->two_factor_secret !== null) {
            $twoFactor->reset($user, $request->user(), $request->string('reason')->trim()->value());
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => "Two-step sign-in reset for {$user->name}. They sign in with their password and set it up again."]);

        return redirect()->route('users.edit', $user);
    }
}
