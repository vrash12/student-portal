<?php

namespace App\Http\Requests\Auth;

use App\Enums\AuditAction;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    private const MAX_ATTEMPTS = 5;

    private const DECAY_SECONDS = 60;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'username' => Str::lower(trim((string) $this->input('username'))),
        ]);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'username' => ['required', 'string', 'max:50'],
            'password' => ['required', 'string', 'max:128'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'username.required' => 'Enter your username.',
            'password.required' => 'Enter your password.',
        ];
    }

    /**
     * Attempt to authenticate the request's credentials.
     *
     * The error message does not reveal whether a username exists. A
     * deactivated account is only disclosed after a correct password.
     *
     * @throws ValidationException
     */
    public function authenticate(AuditLogger $audit): User
    {
        $this->ensureIsNotRateLimited();

        $username = $this->string('username')->value();
        $deactivated = false;

        $authenticated = Auth::attemptWhen(
            ['username' => $username, 'password' => $this->string('password')->value()],
            function (User $user) use (&$deactivated): bool {
                $deactivated = ! $user->is_active;

                return ! $deactivated;
            },
        );

        if (! $authenticated) {
            RateLimiter::hit($this->throttleKey(), self::DECAY_SECONDS);

            $audit->record(
                AuditAction::LoginFailed,
                User::query()->where('username', $username)->first(),
                newValues: ['username' => $username],
            );

            throw ValidationException::withMessages([
                'username' => $deactivated
                    ? 'This account has been deactivated. Contact an administrator.'
                    : 'The username or password is incorrect.',
            ]);
        }

        RateLimiter::clear($this->throttleKey());

        /** @var User $user */
        $user = Auth::user();

        return $user;
    }

    /**
     * @throws ValidationException
     */
    private function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), self::MAX_ATTEMPTS)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'username' => "Too many sign-in attempts. Try again in {$seconds} seconds.",
        ]);
    }

    private function throttleKey(): string
    {
        return Str::transliterate($this->string('username')->value().'|'.$this->ip());
    }
}
