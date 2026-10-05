<?php

namespace App\Http\Requests\Users;

use App\Http\Requests\Concerns\NormalizesTextInput;
use App\Models\Role;
use App\Models\User;
use App\Rules\GrantableStaffRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

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

        // An edit that does not mention the campus keeps the current one.
        if ($this->exists('campus_id')) {
            $campusId = $this->input('campus_id');
            $this->merge(['campus_id' => $campusId === '' ? null : $campusId]);
        } else {
            $this->merge(['campus_id' => $this->targetUser()?->campus_id]);
        }

        // A campus administrator's new staff are on their campus.
        $actorCampus = $this->user()->campusScope();
        if ($this->targetUser() === null && ! $actorCampus->isInstitutionWide() && $this->input('campus_id') === null) {
            $this->merge(['campus_id' => $actorCampus->campusId]);
        }

        // A new teaching account goes to the only campus there is to choose (as classes and candidates do).
        if ($this->targetUser() === null && $this->input('campus_id') === null && $this->roleTeaches((int) $this->input('role_id'))) {
            $assignable = $actorCampus->assignableIds();
            if (count($assignable) === 1) {
                $this->merge(['campus_id' => $assignable[0]]);
            }
        }
    }

    private function roleTeaches(int $roleId): bool
    {
        return $roleId > 0 && (bool) Role::query()->with('permissions')->find($roleId)?->requiresCampus();
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
            // The campus the account is limited to (an active campus the actor
            // manages, or the one it is already on), or none for every campus.
            'campus_id' => ['nullable', 'integer', Rule::in([...$this->user()->campusScope()->assignableIds(), ...($target?->campus_id === null ? [] : [$target->campus_id])])],
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
            'campus_id' => 'campus',
            'is_active' => 'status',
        ];
    }

    /**
     * Campus rules (owner decision 2026-10-03): instructors always belong to
     * one campus; "every campus" is only for administrator roles and only an
     * account that sees every campus may give it.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->hasAny(['role_id', 'campus_id'])) {
                    return;
                }

                $campusId = $this->input('campus_id');
                $role = Role::query()->with('permissions')->find($this->targetUser()?->role_id ?? $this->integer('role_id'));

                if ($campusId === null && $role?->requiresCampus()) {
                    $validator->errors()->add('campus_id', 'Instructors and dietitians belong to one campus. Choose their campus.');
                }

                if ($campusId === null && ! $this->user()->campusScope()->isInstitutionWide()) {
                    $validator->errors()->add('campus_id', 'Accounts you create are on your campus.');
                }
            },
        ];
    }

    /**
     * @return array{name: string, username: string, email: ?string, password: ?string, role_id: int, campus_id: ?int, is_active: bool}
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
            'campus_id' => $this->input('campus_id') === null ? null : $this->integer('campus_id'),
            'is_active' => $this->boolean('is_active'),
        ];
    }
}
