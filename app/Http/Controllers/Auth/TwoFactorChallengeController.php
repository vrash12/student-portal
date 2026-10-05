<?php

namespace App\Http\Controllers\Auth;

use App\Enums\AuditAction;
use App\Http\Controllers\Auth\Concerns\CompletesSignIn;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\TwoFactorChallengeRequest;
use App\Services\AuditLogger;
use App\Services\Auth\PendingTwoFactorSignIn;
use App\Services\Auth\TwoFactorService;
use App\Services\UserAccountService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The second step of signing in for accounts with two-step sign-in on: a
 * code from the authenticator app, or a recovery code.
 */
class TwoFactorChallengeController extends Controller
{
    use CompletesSignIn;

    /** Wrong codes per account per minute, on top of the limit per sign-in. */
    private const MAX_ATTEMPTS_PER_MINUTE = 5;

    /** A warning to make new recovery codes once this few are left. */
    private const FEW_RECOVERY_CODES = 2;

    public function create(Request $request): Response|RedirectResponse
    {
        $user = PendingTwoFactorSignIn::user($request);
        if ($user === null) {
            return $this->startAgain($request, 'Sign in again to continue.');
        }

        return Inertia::render('auth/two-factor-challenge', ['username' => $user->username]);
    }

    public function store(TwoFactorChallengeRequest $request, TwoFactorService $twoFactor, AuditLogger $audit, UserAccountService $accounts): RedirectResponse
    {
        $user = PendingTwoFactorSignIn::user($request);
        if ($user === null) {
            return $this->startAgain($request, 'The sign-in timed out. Enter your password again.');
        }

        $usesRecoveryCode = $request->usesRecoveryCode();
        $field = $usesRecoveryCode ? 'recovery_code' : 'code';
        $throttleKey = 'two-factor|'.$user->getKey();
        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_ATTEMPTS_PER_MINUTE)) {
            throw ValidationException::withMessages([
                $field => 'Too many incorrect codes. Try again in '.RateLimiter::availableIn($throttleKey).' seconds.',
            ]);
        }

        $accepted = $usesRecoveryCode
            ? $twoFactor->useRecoveryCode($user, $request->string('recovery_code')->value())
            : $twoFactor->verifyCode($user, $request->string('code')->value());

        if (! $accepted) {
            RateLimiter::hit($throttleKey, 60);
            $audit->record(AuditAction::TwoFactorFailed, $user, newValues: ['method' => $usesRecoveryCode ? 'recovery_code' : 'authenticator']);

            if (PendingTwoFactorSignIn::recordFailure($request) >= PendingTwoFactorSignIn::MAX_FAILURES) {
                return $this->startAgain($request, 'Too many incorrect codes. Enter your password again.', 'error');
            }

            throw ValidationException::withMessages([
                $field => $usesRecoveryCode
                    ? 'The recovery code is incorrect or was already used.'
                    : 'The code is incorrect or was already used. Enter the code your app shows now.',
            ]);
        }

        RateLimiter::clear($throttleKey);
        PendingTwoFactorSignIn::forget($request);

        if ($usesRecoveryCode) {
            $this->warnWhenFewRecoveryCodes($twoFactor->recoveryCodesLeft($user));
        }

        return $this->completeSignIn($request, $user, $audit, $accounts, ['two_factor' => $usesRecoveryCode ? 'recovery_code' : 'authenticator']);
    }

    private function warnWhenFewRecoveryCodes(int $left): void
    {
        if ($left > self::FEW_RECOVERY_CODES) {
            return;
        }

        Inertia::flash('toast', [
            'type' => 'warning',
            'message' => match ($left) {
                0 => 'That was your last recovery code. Make new ones under Two-Step Sign-In.',
                1 => 'Only 1 recovery code is left. Make new ones under Two-Step Sign-In.',
                default => "Only {$left} recovery codes are left. Make new ones under Two-Step Sign-In.",
            },
        ]);
    }

    private function startAgain(Request $request, string $message, string $type = 'info'): RedirectResponse
    {
        PendingTwoFactorSignIn::forget($request);
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return redirect()->route('login');
    }
}
