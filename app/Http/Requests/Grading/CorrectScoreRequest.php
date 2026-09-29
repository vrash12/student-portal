<?php

namespace App\Http\Requests\Grading;

use App\Models\Assessment;
use App\Support\DecimalValue;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Correction of one finalized score. A reason is always required
 * (AGENTS.md §36). Class membership and the finalized state are re-checked
 * by ScoreRecordingService.
 */
class CorrectScoreRequest extends FormRequest
{
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
            'candidate_id' => ['required', 'integer', 'min:1'],
            'score' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', "max:{$maxScore}"],
            'comment' => ['nullable', 'string', 'max:500'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'expected_score' => ['nullable', 'numeric', 'decimal:0,2'],
            'expected_comment' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $maxScore = DecimalValue::display($this->assessment()->max_score);

        return [
            'score.numeric' => 'Enter the score as a number.',
            'score.decimal' => 'Use at most two decimal places.',
            'score.min' => "Enter a score from 0 to {$maxScore}.",
            'score.max' => "Enter a score from 0 to {$maxScore}.",
            'comment.max' => 'Use at most 500 characters.',
            'reason.required' => 'Explain why this finalized score is being corrected.',
            'reason.min' => 'Give a reason of at least 5 characters.',
            'reason.max' => 'Use at most 500 characters.',
        ];
    }

    public function scoreValue(): ?string
    {
        $score = $this->validated('score');

        return $score === null ? null : (string) $score;
    }

    public function commentValue(): ?string
    {
        $comment = $this->validated('comment');

        return $comment === null ? null : (string) $comment;
    }

    public function expectedScore(): ?string
    {
        $score = $this->validated('expected_score');

        return $score === null ? null : (string) $score;
    }

    public function expectedComment(): ?string
    {
        $comment = $this->validated('expected_comment');

        return $comment === null ? null : (string) $comment;
    }

    private function assessment(): Assessment
    {
        /** @var Assessment $assessment */
        $assessment = $this->route('assessment');

        return $assessment;
    }
}
