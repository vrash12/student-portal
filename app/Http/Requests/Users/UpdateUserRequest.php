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
     * Guards that stop administrators from locking themselves out.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->user()->is($this->targetUser())) {
                    return;
                }

                if ($this->integer('role_id') !== $this->targetUser()->role_id) {
                    $validator->errors()->add('role_id', 'You cannot change your own role.');
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
