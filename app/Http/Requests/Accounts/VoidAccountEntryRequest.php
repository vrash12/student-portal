<?php

namespace App\Http\Requests\Accounts;

use App\Http\Requests\Concerns\NormalizesTextInput;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Voiding a Statement of Account entry always records why. Authorization is
 * enforced by the `can:accounts.manage` route middleware.
 */
class VoidAccountEntryRequest extends FormRequest
{
    use NormalizesTextInput;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['reason' => $this->trimmedInput('reason')]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Give the reason for voiding this entry.',
            'reason.min' => 'Give the reason for voiding this entry (at least 5 characters).',
        ];
    }
}
