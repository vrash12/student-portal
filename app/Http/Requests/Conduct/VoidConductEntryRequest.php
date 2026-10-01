<?php

namespace App\Http\Requests\Conduct;

use App\Http\Requests\Concerns\NormalizesTextInput;
use App\Models\ConductEntry;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Voiding a merit or demerit always records why. The `can:conduct.manage`
 * route middleware checks the permission; authorize() adds the class scope
 * of the entry's candidate (ConductPolicy).
 */
class VoidConductEntryRequest extends FormRequest
{
    use NormalizesTextInput;

    public function authorize(): bool
    {
        $entry = $this->route('conductEntry');

        return $entry instanceof ConductEntry && $this->user()->can('void', $entry);
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
