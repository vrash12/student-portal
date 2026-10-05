<?php

namespace App\Services\Auth;

use App\Enums\AuditAction;
use App\Enums\TwoFactorRequirement;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Totp;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Two-step sign-in for staff accounts (owner request, 2026-10-05): after the
 * password, a 6-digit code from an authenticator app or hardware token
 * (Totp), or one of the account's single-use recovery codes.
 *
 * - Setup stores a new secret first; it is in use only after the user
 *   proves their app has it by entering a code (confirmSetup).
 * - A code is accepted once: the step of the last accepted code is kept and
 *   moved forward atomically, so a code seen over someone's shoulder or
 *   sent twice cannot sign in again.
 * - Recovery codes are kept only as SHA-256 hashes (they are random, 50
 *   bits each, so a slow hash adds nothing) and are shown once.
 * - Nothing here writes the secret or a code into the audit log.
 */
final class TwoFactorService
{
    /** Without look-alike characters (0/o, 1/l/i) so codes read from paper are typed right. */
    private const RECOVERY_ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';

    private const RECOVERY_LENGTH = 10;

    public function __construct(private readonly AuditLogger $audit) {}

    public function isRequired(User $user): bool
    {
        return TwoFactorRequirement::configured()->appliesTo($user);
    }

    /** Only staff accounts use two-step sign-in; candidates sign in on shared exam tablets. */
    public function canUse(User $user): bool
    {
        return $user->is_active && $user->isStaffAccount();
    }

    /**
     * Starts setup (again) with a new secret, not used for signing in until
     * confirmed. Refused while two-step sign-in is on.
     */
    public function beginSetup(User $user): string
    {
        if ($user->hasTwoFactorEnabled()) {
            throw new LogicException('Two-step sign-in is already on.');
        }

        $secret = Totp::generateSecret();
        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_step' => null,
        ])->save();

        return $secret;
    }

    /** The secret of a setup not yet confirmed, or null. */
    public function pendingSecret(User $user): ?string
    {
        return $user->hasTwoFactorEnabled() ? null : $user->two_factor_secret;
    }

    public function cancelSetup(User $user): void
    {
        if ($user->hasTwoFactorEnabled() || $user->two_factor_secret === null) {
            return;
        }

        $this->clear($user);
    }

    /**
     * Turns two-step sign-in on when the code matches the pending secret.
     *
     * @return list<string>|null the new recovery codes (shown once), or null when the code is wrong
     */
    public function confirmSetup(User $user, string $code, ?int $now = null): ?array
    {
        return DB::transaction(function () use ($user, $code, $now): ?array {
            $locked = $this->lock($user);
            $secret = $this->pendingSecret($locked);
            if ($secret === null) {
                return null;
            }

            $step = Totp::matchingStep($secret, $code, $now ?? time(), $this->window());
            if ($step === null) {
                return null;
            }

            $codes = $this->newRecoveryCodes();
            $locked->forceFill([
                'two_factor_confirmed_at' => now(),
                'two_factor_last_step' => $step,
                'two_factor_recovery_codes' => $this->hashAll($codes),
            ])->save();
            $user->setRawAttributes($locked->getAttributes(), true);

            $this->audit->record(AuditAction::TwoFactorEnabled, $user, newValues: ['recovery_codes_issued' => count($codes)], actor: $user);

            return $codes;
        });
    }

    /**
     * Replaces every recovery code; the old ones stop working.
     *
     * @return list<string>
     */
    public function regenerateRecoveryCodes(User $user): array
    {
        if (! $user->hasTwoFactorEnabled()) {
            throw new LogicException('Two-step sign-in is off.');
        }

        $codes = $this->newRecoveryCodes();
        $user->forceFill(['two_factor_recovery_codes' => $this->hashAll($codes)])->save();

        $this->audit->record(AuditAction::TwoFactorRecoveryCodesRegenerated, $user, newValues: ['recovery_codes_issued' => count($codes)], actor: $user);

        return $codes;
    }

    /** The user turns it off themselves (not allowed while it is required: see the controller). */
    public function disable(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $this->clear($user);
            $this->audit->record(AuditAction::TwoFactorDisabled, $user, actor: $user);
        });
    }

    /**
     * An administrator (or IT, from the command line, without an actor)
     * turns it off for someone who lost their phone and recovery codes. The
     * user signs in with the password only and, when two-step sign-in is
     * required for them, sets it up again before using the staff area.
     */
    public function reset(User $user, ?User $actor, string $reason): void
    {
        DB::transaction(function () use ($user, $actor, $reason): void {
            $this->clear($user);
            $this->audit->record(AuditAction::TwoFactorReset, $user, reason: $reason, actor: $actor);
        });
    }

    /** Accepts an authenticator code, each code only once. */
    public function verifyCode(User $user, string $code, ?int $now = null): bool
    {
        if (! $user->hasTwoFactorEnabled()) {
            return false;
        }

        $step = Totp::matchingStep((string) $user->two_factor_secret, $code, $now ?? time(), $this->window());
        if ($step === null) {
            return false;
        }

        // Atomic: of two requests carrying the same code, only one moves the step forward.
        $accepted = DB::table('users')
            ->where('id', $user->getKey())
            ->where(fn ($query) => $query->whereNull('two_factor_last_step')->orWhere('two_factor_last_step', '<', $step))
            ->update(['two_factor_last_step' => $step]) === 1;

        if ($accepted) {
            $user->setRawAttributes([...$user->getAttributes(), 'two_factor_last_step' => $step], true);
        }

        return $accepted;
    }

    /** Accepts one unused recovery code and crosses it off. */
    public function useRecoveryCode(User $user, string $code): bool
    {
        $normalized = self::normalizeRecoveryCode($code);
        if ($normalized === null || ! $user->hasTwoFactorEnabled()) {
            return false;
        }

        return DB::transaction(function () use ($user, $normalized): bool {
            $locked = $this->lock($user);
            $hashes = (array) ($locked->two_factor_recovery_codes ?? []);
            $given = hash('sha256', $normalized);

            $remaining = [];
            $found = false;
            foreach ($hashes as $hash) {
                if (! $found && hash_equals((string) $hash, $given)) {
                    $found = true;

                    continue;
                }
                $remaining[] = $hash;
            }

            if (! $found) {
                return false;
            }

            $locked->forceFill(['two_factor_recovery_codes' => $remaining])->save();
            $user->setRawAttributes($locked->getAttributes(), true);

            $this->audit->record(AuditAction::TwoFactorRecoveryCodeUsed, $user, newValues: ['recovery_codes_left' => count($remaining)], actor: $user);

            return true;
        });
    }

    public function recoveryCodesLeft(User $user): int
    {
        return $user->hasTwoFactorEnabled() ? count((array) ($user->two_factor_recovery_codes ?? [])) : 0;
    }

    /** Lowercase letters and digits without the dash and spaces, or null when it cannot be a recovery code. */
    public static function normalizeRecoveryCode(string $code): ?string
    {
        $value = strtolower(preg_replace('/[\s-]+/', '', $code) ?? '');

        return preg_match('/^['.self::RECOVERY_ALPHABET.']{'.self::RECOVERY_LENGTH.'}$/', $value) === 1 ? $value : null;
    }

    /** "Issuer: username" as the authenticator app shows it, and the QR code holding it. */
    public function provisioningUri(User $user, string $secret): string
    {
        return Totp::provisioningUri($secret, (string) config('two_factor.issuer'), $user->username);
    }

    /**
     * @return list<string> codes like "k7m2p-x9qrt"
     */
    private function newRecoveryCodes(): array
    {
        $count = max(1, (int) config('two_factor.recovery_codes', 8));
        $last = strlen(self::RECOVERY_ALPHABET) - 1;
        $codes = [];
        while (count($codes) < $count) {
            $code = '';
            for ($i = 0; $i < self::RECOVERY_LENGTH; $i++) {
                $code .= self::RECOVERY_ALPHABET[random_int(0, $last)];
            }
            $codes[$code] = substr($code, 0, 5).'-'.substr($code, 5);
        }

        return array_values($codes);
    }

    /**
     * @param  list<string>  $codes
     * @return list<string>
     */
    private function hashAll(array $codes): array
    {
        return array_map(fn (string $code): string => hash('sha256', (string) self::normalizeRecoveryCode($code)), $codes);
    }

    private function clear(User $user): void
    {
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_step' => null,
        ])->save();
    }

    private function lock(User $user): User
    {
        return User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
    }

    private function window(): int
    {
        return max(0, (int) config('two_factor.window', 1));
    }
}
