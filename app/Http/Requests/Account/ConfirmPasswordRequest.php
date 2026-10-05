<?php

namespace App\Http\Requests\Account;

use Illuminate\Foundation\Http\FormRequest;

/** The signed-in user's password, asked again before a security change to their own account. */
class ConfirmPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'max:128', 'current_password'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'current_password.required' => 'Enter your password.',
            'current_password.current_password' => 'The password is incorrect.',
        ];
    }
}
