<?php

namespace App\Http\Requests\Accounts;

use App\Enums\AccountEntryType;
use App\Http\Requests\Concerns\NormalizesTextInput;
use App\Models\AccountExpense;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create or update an expense that can be assigned to candidates.
 * Authorization is enforced by the `can:accounts.manage` route middleware;
 * AccountService refuses amount and category changes once it is assigned.
 */
class AccountExpenseRequest extends FormRequest
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
            'name' => $this->trimmedInput('name'),
            // Thousands separators are accepted as typed ("1,250.50").
            'amount' => is_string($amount) ? str_replace(',', '', $amount) : $amount,
            'due_on' => $this->optionalInput('due_on'),
            'description' => $this->optionalInput('description'),
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        /** @var AccountExpense|null $expense */
        $expense = $this->route('accountExpense');

        return [
            'name' => ['required', 'string', 'max:150', Rule::unique('account_expenses', 'name')->ignore($expense?->id)],
            // An expense is a charge, so it belongs to a category whose entries are charges.
            'account_category_id' => ['required', 'integer', Rule::exists('account_categories', 'id')
                ->where('entry_type', AccountEntryType::Charge->value)
                ->when($expense === null, fn ($rule) => $rule->where('is_active', true))],
            'amount' => ['required', 'string', 'regex:/^\d{1,10}(\.\d{1,2})?$/', 'not_regex:/^0+(\.0+)?$/'],
            'due_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:2100-12-31'],
            'description' => ['nullable', 'string', 'max:255'],
            'is_active' => [$expense === null ? 'prohibited' : 'required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Name the expense, for example "Uniform set" or "Meals, September".',
            'name.unique' => 'Another expense already uses this name.',
            'account_category_id.required' => 'Choose a category.',
            'account_category_id.exists' => 'Choose an active charge category.',
            'amount.required' => 'Enter the amount.',
            'amount.regex' => 'Enter an amount such as 1250 or 1250.50 (up to two decimal places).',
            'amount.not_regex' => 'The amount must be more than zero.',
            'due_on.date_format' => 'Enter the due date as a calendar date.',
        ];
    }

    /**
     * @return array{name: string, account_category_id: int, amount: string, due_on: ?string, description: ?string, is_active: bool}
     */
    public function expenseData(): array
    {
        return [
            'name' => (string) $this->validated('name'),
            'account_category_id' => (int) $this->validated('account_category_id'),
            'amount' => (string) $this->validated('amount'),
            'due_on' => $this->validated('due_on'),
            'description' => $this->validated('description'),
            'is_active' => $this->boolean('is_active', true),
        ];
    }
}
