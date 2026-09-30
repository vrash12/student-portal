<?php

namespace App\Http\Requests\Users;

use App\Http\Requests\Concerns\NormalizesTextInput;
use App\Models\User;
use App\Rules\GrantableStaffRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Shared normalization and rules for creating and updating staff accounts.
 */
abstract class UserAccountRequest extends FormRequest
{
    use NormalizesTextInput;

    /**
     * The account being edited, or null when creating.
     */
    abstract protected function targetUser(): ?User;

    abstract protected function passwordIsRequired(): bool;

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => $this->trimmedInput('name'),
            'username' => $this->lowercaseInput('username'),
            'email' => $this->optionalInput('email', lowercase: true),
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $target = $this->targetUser();

        return [
            'name' => ['required', 'string', 'max:150'],
            'username' => [
                'required', 'string', 'min:3', 'max:50', 'regex:/^[a-z0-9._-]+$/',
                Rule::unique('users', 'username')->ignore($target),
            ],
            'email' => [
                'nullable', 'string', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($target),
            ],
            'role_id' => ['bail', 'required', 'integer', new GrantableStaffRole($this->user(), $target?->role)],
            'is_active' => ['required', 'boolean'],
            'password' => [
                $this->passwordIsRequired() ? 'required' : 'nullable',
                'string', 'confirmed', Password::defaults(),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'username.unique' => 'This username is already assigned to another account.',
            'username.regex' => 'Use only lowercase letters, numbers, periods, hyphens, and underscores.',
            'email.unique' => 'This email address is already assigned to another account.',
            'password.confirmed' => 'The password confirmation does not match.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'role_id' => 'role',
            'is_active' => 'status',
        ];
    }

    /**
     * @return array{name: string, username: string, email: ?string, password: ?string, role_id: int, is_active: bool}
     */
    public function accountData(): array
    {
        $password = $this->string('password')->value();
        $email = $this->input('email');

        return [
            'name' => $this->string('name')->value(),
            'username' => $this->string('username')->value(),
            'email' => is_string($email) ? $email : null,
            'password' => $password === '' ? null : $password,
            'role_id' => $this->integer('role_id'),
            'is_active' => $this->boolean('is_active'),
        ];
    }
}
