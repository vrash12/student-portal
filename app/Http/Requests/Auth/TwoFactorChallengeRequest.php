<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class TwoFactorChallengeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'code' => ['nullable', 'required_without:recovery_code', 'string', 'max:20'],
            'recovery_code' => ['nullable', 'required_without:code', 'string', 'max:30'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.required_without' => 'Enter the 6-digit code from your authenticator app.',
            'recovery_code.required_without' => 'Enter one of your recovery codes.',
        ];
    }

    public function usesRecoveryCode(): bool
    {
        return $this->filled('recovery_code');
    }
}
