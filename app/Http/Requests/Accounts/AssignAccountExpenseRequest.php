<?php

namespace App\Http\Requests\Accounts;

use App\Enums\CandidateStatus;
use App\Models\Candidate;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Assigns an expense to a whole class (its candidates who are not
 * withdrawn, resolved by the server) or to chosen candidates. Authorization
 * is enforced by the `can:accounts.manage` route middleware.
 */
class AssignAccountExpenseRequest extends FormRequest
{
    public const MODE_CLASS = 'class';

    public const MODE_CANDIDATES = 'candidates';

    /** Most candidates chosen one by one in a single assignment. */
    public const MAX_CANDIDATES = 500;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'mode' => ['required', Rule::in([self::MODE_CLASS, self::MODE_CANDIDATES])],
            'class_batch_id' => ['exclude_unless:mode,'.self::MODE_CLASS, 'required', 'integer', Rule::exists('class_batches', 'id')],
            'candidate_ids' => ['exclude_unless:mode,'.self::MODE_CANDIDATES, 'required', 'array', 'min:1', 'max:'.self::MAX_CANDIDATES],
            'candidate_ids.*' => ['integer', 'distinct', Rule::exists('candidates', 'id')],
            'posted_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:2100-12-31'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'mode.required' => 'Choose whether to assign the expense to a whole class or to chosen candidates.',
            'class_batch_id.required' => 'Choose the class.',
            'class_batch_id.exists' => 'Choose a class from the list.',
            'candidate_ids.required' => 'Choose at least one candidate.',
            'candidate_ids.min' => 'Choose at least one candidate.',
            'candidate_ids.max' => 'Choose at most '.self::MAX_CANDIDATES.' candidates at a time, or assign the expense to a whole class.',
            'candidate_ids.*.exists' => 'One of the chosen candidates no longer exists. Reload the page and choose again.',
            'posted_on.required' => 'Enter the date of the charge.',
            'posted_on.date_format' => 'Enter the date of the charge as a calendar date.',
        ];
    }

    /**
     * The candidates to charge, in candidate-number order. A whole class
     * leaves out withdrawn candidates; chosen candidates are taken as chosen.
     *
     * @return Collection<int, Candidate>
     */
    public function candidates(): Collection
    {
        $query = Candidate::query()->orderBy('candidate_number')->orderBy('id');

        if ($this->validated('mode') === self::MODE_CLASS) {
            return $query
                ->where('class_batch_id', (int) $this->validated('class_batch_id'))
                ->where('status', '!=', CandidateStatus::Withdrawn->value)
                ->get();
        }

        return $query->whereKey(array_map('intval', (array) $this->validated('candidate_ids')))->get();
    }
}
