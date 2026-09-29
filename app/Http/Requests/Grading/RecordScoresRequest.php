<?php

namespace App\Http\Requests\Grading;

use App\Models\Assessment;
use App\Support\DecimalValue;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Changed rows of a draft score sheet, keyed by candidate id. Each row also
 * carries the values the user last saw, so edits by another user are
 * detected instead of silently overwritten. Class membership, the draft
 * state, and the maximum score are re-checked by ScoreRecordingService.
 */
class RecordScoresRequest extends FormRequest
{
    /** Upper bound on rows per save; far above any realistic class size. */
    public const MAX_ENTRIES = 1000;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $maxScore = DecimalValue::display($this->assessment()->max_score);

        return [
            'entries' => ['required', 'array', 'min:1', 'max:'.self::MAX_ENTRIES],
            'entries.*' => ['required', 'array:score,comment,expected_score,expected_comment'],
            'entries.*.score' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', "max:{$maxScore}"],
            'entries.*.comment' => ['nullable', 'string', 'max:500'],
            'entries.*.expected_score' => ['nullable', 'numeric', 'decimal:0,2'],
            'entries.*.expected_comment' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                foreach (array_keys((array) $this->input('entries', [])) as $key) {
                    if (! is_int($key) || $key <= 0) {
                        $validator->errors()->add('entries', 'The score sheet is out of date. Reload the page and try again.');

                        return;
                    }
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $maxScore = DecimalValue::display($this->assessment()->max_score);

        return [
            'entries.required' => 'There are no changes to save.',
            'entries.max' => 'Too many rows in one save. Save in smaller parts.',
            'entries.*.score.numeric' => 'Enter the score as a number.',
            'entries.*.score.decimal' => 'Use at most two decimal places.',
            'entries.*.score.min' => "Enter a score from 0 to {$maxScore}.",
            'entries.*.score.max' => "Enter a score from 0 to {$maxScore}.",
            'entries.*.comment.max' => 'Use at most 500 characters.',
        ];
    }

    /**
     * @return array<int, array{score: ?string, comment: ?string, expected_score: ?string, expected_comment: ?string}>
     */
    public function entries(): array
    {
        $entries = [];
        foreach ($this->validated('entries') as $candidateId => $entry) {
            $entries[(int) $candidateId] = [
                'score' => self::stringOrNull($entry['score'] ?? null),
                'comment' => self::stringOrNull($entry['comment'] ?? null),
                'expected_score' => self::stringOrNull($entry['expected_score'] ?? null),
                'expected_comment' => self::stringOrNull($entry['expected_comment'] ?? null),
            ];
        }

        return $entries;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    private function assessment(): Assessment
    {
        /** @var Assessment $assessment */
        $assessment = $this->route('assessment');

        return $assessment;
    }
}
