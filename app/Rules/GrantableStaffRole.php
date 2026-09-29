<?php

namespace App\Rules;

use App\Enums\Permission;
use App\Models\Role;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The selected role must be a staff role whose permissions the acting user
 * already holds. Prevents privilege escalation through role assignment.
 */
final class GrantableStaffRole implements ValidationRule
{
    public function __construct(private readonly User $actor) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $role = Role::query()->with('permissions')->find($value);

        if ($role === null || ! $role->grants(Permission::AccessStaffArea)) {
            $fail('Select a valid staff role.');

            return;
        }

        if (! $this->actor->canGrantRole($role)) {
            $fail('You cannot assign a role with permissions beyond your own.');
        }
    }
}
