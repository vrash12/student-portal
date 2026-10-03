<?php

namespace App\Http\Requests\Users;

use App\Models\User;
use Illuminate\Validation\Validator;

class UpdateUserRequest extends UserAccountRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->targetUser());
    }

    protected function targetUser(): User
    {
        /** @var User $user */
        $user = $this->route('user');

        return $user;
    }

    protected function passwordIsRequired(): bool
    {
        return false;
    }

    /**
     * The role is chosen when the account is created and never changes
     * afterwards (owner decision, 2026-10-03): an instructor cannot be made an
     * administrator from the Edit page. A role sent anyway must be the current one.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['role_id' => ['nullable', 'integer']] + parent::rules();
    }

    /**
     * @return array{name: string, username: string, email: ?string, password: ?string, role_id: int, campus_id: ?int, is_active: bool}
     */
    public function accountData(): array
    {
        return ['role_id' => $this->targetUser()->role_id] + parent::accountData();
    }

    /**
     * The fixed role, and guards that stop administrators from locking themselves out.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            ...parent::after(),
            function (Validator $validator): void {
                if ($this->filled('role_id') && $this->integer('role_id') !== $this->targetUser()->role_id) {
                    $validator->errors()->add('role_id', 'The role of an existing account cannot be changed.');
                }

                $target = $this->targetUser();
                $newCampus = $this->input('campus_id') === null ? null : $this->integer('campus_id');
                if ($newCampus !== $target->campus_id) {
                    if ($this->user()->is($target)) {
                        $validator->errors()->add('campus_id', 'You cannot change your own campus.');
                    } elseif (! $this->user()->campusScope()->isInstitutionWide()) {
                        $validator->errors()->add('campus_id', 'Only an administrator of every campus can move an account to another campus.');
                    } elseif ($target->teachingAssignments()->exists()) {
                        // Instructors teach only on their campus: assignments would be left on the old one.
                        $validator->errors()->add('campus_id', "{$target->name} still teaches subjects on the current campus. Remove those assignments first.");
                    }
                }

                if (! $this->user()->is($target)) {
                    return;
                }

                if (! $this->boolean('is_active')) {
                    $validator->errors()->add('is_active', 'You cannot deactivate your own account.');
                }

                if ($this->filled('password')) {
                    $validator->errors()->add('password', 'Change your own password from the Account page.');
                }
            },
        ];
    }
}
