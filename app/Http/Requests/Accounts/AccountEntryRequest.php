<?php

namespace App\Http\Requests\Accounts;

use App\Enums\AccountEntryType;
use App\Http\Requests\Concerns\NormalizesTextInput;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A new Statement of Account entry. Authorization is enforced by the
 * `can:accounts.manage` route middleware.
 */
class AccountEntryRequest extends FormRequest
{
    use NormalizesTextInput;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $amount = $this->trimmedInput('amount');

        $this->merge([
            // Thousands separators are accepted as typed ("1,250.50").
            'amount' => is_string($amount) ? str_replace(',', '', $amount) : $amount,
            'description' => $this->trimmedInput('description'),
            'reference' => $this->optionalInput('reference'),
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'account_category_id' => ['required', 'integer', Rule::exists('account_categories', 'id')->where('is_active', true)],
            'entry_type' => ['required', Rule::enum(AccountEntryType::class)],
            'amount' => ['required', 'string', 'regex:/^\d{1,10}(\.\d{1,2})?$/', 'not_regex:/^0+(\.0+)?$/'],
            'posted_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:2100-12-31'],
            'description' => ['required', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'account_category_id.required' => 'Choose a category.',
            'account_category_id.exists' => 'Choose an active category.',
            'amount.required' => 'Enter the amount.',
            'amount.regex' => 'Enter an amount such as 1250 or 1250.50 (up to two decimal places).',
            'amount.not_regex' => 'The amount must be more than zero.',
            'posted_on.date_format' => 'Enter the date of the entry.',
            'description.required' => 'Describe the entry, for example "Uniform set" or "September meal allowance".',
        ];
    }

    /**
     * @return array{account_category_id: int, entry_type: string, amount: string, posted_on: string, description: string, reference: ?string}
     */
    public function entryData(): array
    {
        return [
            'account_category_id' => (int) $this->validated('account_category_id'),
            'entry_type' => (string) $this->validated('entry_type'),
            'amount' => (string) $this->validated('amount'),
            'posted_on' => (string) $this->validated('posted_on'),
            'description' => (string) $this->validated('description'),
            'reference' => $this->validated('reference'),
        ];
    }
}
