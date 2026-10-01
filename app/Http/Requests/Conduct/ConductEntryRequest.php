<?php

namespace App\Http\Requests\Conduct;

use App\Http\Requests\Concerns\NormalizesTextInput;
use App\Models\Candidate;
use App\Models\ConductEntry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A new merit or demerit. The `can:conduct.manage` route middleware checks
 * the permission; authorize() adds the class scope (ConductPolicy), so a
 * candidate outside the user's classes is refused before validation.
 */
class ConductEntryRequest extends FormRequest
{
    use NormalizesTextInput;

    public function authorize(): bool
    {
        $candidate = $this->route('candidate');

        return $candidate instanceof Candidate && $this->user()->can('manage', [ConductEntry::class, $candidate]);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'points' => $this->trimmedInput('points'),
            'reason' => $this->trimmedInput('reason'),
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'conduct_type_id' => ['required', 'integer', Rule::exists('conduct_types', 'id')->where('is_active', true)],
            'points' => ['required', 'integer', 'min:1', 'max:100'],
            // Conduct is recorded for what has already happened.
            'occurred_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:'.self::today()],
            'reason' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'conduct_type_id.required' => 'Choose a merit or demerit type.',
            'conduct_type_id.integer' => 'Choose a merit or demerit type.',
            'conduct_type_id.exists' => 'Choose an active merit or demerit type.',
            'points.required' => 'Enter the points.',
            'points.integer' => 'Enter the points as a whole number from 1 to 100.',
            'points.min' => 'Enter the points as a whole number from 1 to 100.',
            'points.max' => 'Enter the points as a whole number from 1 to 100.',
            'occurred_on.required' => 'Enter the date it happened.',
            'occurred_on.date_format' => 'Enter the date it happened.',
            'occurred_on.after_or_equal' => 'Enter a date from the year 2000 onward.',
            'occurred_on.before_or_equal' => 'The date cannot be in the future.',
            'reason.required' => 'Describe what happened, for example "Led the platoon during the field exercise".',
        ];
    }

    /**
     * @return array{conduct_type_id: int, points: int, occurred_on: string, reason: string}
     */
    public function entryData(): array
    {
        return [
            'conduct_type_id' => (int) $this->validated('conduct_type_id'),
            'points' => (int) $this->validated('points'),
            'occurred_on' => (string) $this->validated('occurred_on'),
            'reason' => (string) $this->validated('reason'),
        ];
    }

    /** Today in the institution's timezone (Y-m-d). */
    public static function today(): string
    {
        return now()->timezone(config('institution.timezone'))->toDateString();
    }
}
