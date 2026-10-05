<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * Between the correct password and the code, the session remembers only
 * which account is signing in, until when, and how many codes were wrong.
 * Nobody is signed in meanwhile. After too many wrong codes, or when the
 * time is up, the password must be entered again.
 */
final class PendingTwoFactorSignIn
{
    private const SESSION_KEY = 'two_factor.pending';

    public const MAX_FAILURES = 5;

    public static function start(Request $request, User $user): void
    {
        $request->session()->put(self::SESSION_KEY, [
            'user_id' => $user->getKey(),
            'expires_at' => now()->addMinutes(max(1, (int) config('two_factor.challenge_minutes', 5)))->getTimestamp(),
            'failures' => 0,
        ]);
    }

    /** The account signing in, or null when there is none, the time is up, or it can no longer sign in this way. */
    public static function user(Request $request): ?User
    {
        $pending = $request->session()->get(self::SESSION_KEY);
        if (! is_array($pending) || ! isset($pending['user_id'], $pending['expires_at']) || (int) $pending['expires_at'] < now()->getTimestamp()) {
            return null;
        }

        $user = User::query()->find((int) $pending['user_id']);

        return $user !== null && $user->is_active && $user->hasTwoFactorEnabled() ? $user : null;
    }

    /** Counts a wrong code and returns how many there have been. */
    public static function recordFailure(Request $request): int
    {
        $pending = (array) $request->session()->get(self::SESSION_KEY, []);
        $pending['failures'] = (int) ($pending['failures'] ?? 0) + 1;
        $request->session()->put(self::SESSION_KEY, $pending);

        return $pending['failures'];
    }

    public static function forget(Request $request): void
    {
        $request->session()->forget(self::SESSION_KEY);
    }
}
