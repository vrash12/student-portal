<?php

namespace App\Services\Examinations;

use App\Enums\QuestionType;
use App\Models\ExaminationAttempt;
use App\Models\Question;
use App\Support\DecimalValue;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ExaminationScoringService
{
    /** The key is confidential and never belongs in candidate delivery or audit data. */
    public function snapshot(array $delivery): array
    {
        $questions = Question::with('choices')->whereIn('id', array_column(array_column($delivery, 'question'), 'id'))->get()->keyBy('id');
        $key = [];
        foreach ($delivery as $item) {
            $question = $questions->get($item['question']['id']);
            $type = QuestionType::from($item['question']['type']['value']);
            $correct = $question?->choices->where('is_correct', true);
            if (! $question || $question->type !== $type || ($type->isObjective() && ($correct->count() !== 1 || ! in_array($correct->first()->id, array_column($item['question']['choices'], 'id'), true)))) {
                throw ValidationException::withMessages(['examination' => 'This examination requires instructor review before it can be scored.']);
            }
            $key[$item['id']] = ['type' => $type->value, 'points' => $item['points'], 'correct_choice_id' => $type->isObjective() ? $correct->first()->id : null];
        }

        return $key;
    }

    /** Caller holds the attempt lock. Existing scores remain immutable on retries. */
    public function score(ExaminationAttempt $attempt): void
    {
        if ($attempt->status !== 'submitted' || $attempt->scored_at !== null) {
            return;
        }
        if ($attempt->scoring_key === null) {
            $attempt->passing_score = $attempt->examination->passing_score;
            $attempt->scoring_key = $this->snapshot($attempt->delivery ?? []);
        }
        $this->recalculate($attempt);
    }

    public function reconcile(ExaminationAttempt $attempt): ExaminationAttempt
    {
        return DB::transaction(function () use ($attempt) {
            $locked = ExaminationAttempt::whereKey($attempt->id)->lockForUpdate()->firstOrFail();
            $this->score($locked);

            return $locked;
        });
    }

    /** Single authoritative calculation for submission and authorized essay grading. */
    public function recalculate(ExaminationAttempt $attempt): void
    {
        abort_unless($attempt->status === 'submitted' && ! empty($attempt->scoring_key), 422, 'A submitted question snapshot is required.');
        $grades = $attempt->essayGrades()->get()->keyBy('examination_question_id');
        $objective = $objectiveMaximum = $total = $earnedTotal = 0;
        $pending = false;
        $scores = [];
        foreach ($attempt->scoring_key as $itemId => $key) {
            // Sum integer hundredths, rounding only when deriving the percentage.
            $points = DecimalValue::toHundredths($key['points']);
            $total += $points;
            if ($key['type'] === QuestionType::Essay->value) {
                $grade = $grades->get($itemId);
                $earned = $grade === null ? null : DecimalValue::toHundredths($grade->score);
                $pending = $pending || $grade === null;
            } else {
                $answer = $attempt->answers[$itemId]['value'] ?? null;
                $earned = is_int($answer) && $answer === (int) $key['correct_choice_id'] ? $points : 0;
                $objectiveMaximum += $points;
                $objective += $earned;
            }
            $earnedTotal += $earned ?? 0;
            $scores[$itemId] = ['points' => $earned === null ? null : $earned / 100, 'max_points' => $points / 100, 'status' => $earned === null ? 'pending_review' : 'graded'];
        }
        $attempt->objective_points = $objective / 100;
        $attempt->objective_max_points = $objectiveMaximum / 100;
        $attempt->total_points = $total / 100;
        $attempt->item_scores = $scores;
        $attempt->earned_points = $pending ? null : $earnedTotal / 100;
        $attempt->percentage = $pending || $total === 0 ? null : round($earnedTotal * 100 / $total, 2);
        $attempt->passed = $attempt->percentage !== null && $attempt->passing_score !== null ? (float) $attempt->percentage >= (float) $attempt->passing_score : null;
        $attempt->result_status = $pending ? 'pending_review' : 'graded';
        $attempt->scored_at = now();
        $attempt->save();
    }
}
