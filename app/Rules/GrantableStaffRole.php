<?php

namespace App\Rules;

use App\Enums\Permission;
use App\Models\Role;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The selected role must be a staff role ranked below the acting user's own
 * role. Keeping an account's current role is always allowed, so editing a
 * profile does not require re-assigning it.
 */
final class GrantableStaffRole implements ValidationRule
{
    public function __construct(
        private readonly User $actor,
        private readonly ?Role $currentRole = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->currentRole !== null && (int) $value === $this->currentRole->id) {
            return;
        }

        $role = Role::query()->with('permissions')->find($value);

        if ($role === null || ! $role->grants(Permission::AccessStaffArea)) {
            $fail('Select a valid staff role.');

            return;
        }

        if (! $this->actor->canAssignRole($role)) {
            $fail('You can only assign roles ranked below your own.');
        }
    }
}
