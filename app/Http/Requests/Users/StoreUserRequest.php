<?php

namespace App\Http\Requests\Users;

use App\Models\User;

class StoreUserRequest extends UserAccountRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', User::class);
    }

    protected function targetUser(): ?User
    {
        return null;
    }

    protected function passwordIsRequired(): bool
    {
        return true;
    }

    /**
     * @return array{name: string, username: string, email: ?string, password: string, role_id: int, campus_id: ?int, is_active: bool}
     */
    public function newAccountData(): array
    {
        $data = $this->accountData();

        return [...$data, 'password' => (string) $data['password']];
    }
}
