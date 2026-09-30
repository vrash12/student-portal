<?php

namespace App\Services\Examinations;

use App\Enums\QuestionType;
use App\Models\ExaminationAttempt;
use App\Models\Question;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ExaminationScoringService
{
    /** Build a confidential key using the same question IDs and points as delivery. */
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

    /** Caller holds the attempt lock; this also supports pre-M11 submitted attempts. */
    public function score(ExaminationAttempt $attempt): void
    {
        if ($attempt->status !== 'submitted' || $attempt->scored_at !== null) {
            return;
        }
        $attempt->passing_score ??= $attempt->examination->passing_score;
        $attempt->scoring_key ??= $this->snapshot($attempt->delivery ?? []);
        $objective = 0;
        $objectiveMax = 0;
        $total = 0;
        $pending = false;
        $scores = [];
        foreach ($attempt->scoring_key as $id => $key) {
            // Whole hundredths avoid floating-point drift while summing point values.
            $points = (int) round((float) $key['points'] * 100);
            $total += $points;
            if ($key['type'] === QuestionType::Essay->value) {
                $pending = true;
                $earned = null;
            } else {
                $objectiveMax += $points;
                $answer = $attempt->answers[$id]['value'] ?? null;
                $earned = is_numeric($answer) && (int) $answer === (int) $key['correct_choice_id'] ? $points : 0;
                $objective += $earned;
            }
            $scores[$id] = ['points' => $earned === null ? null : $earned / 100, 'max_points' => $points / 100, 'status' => $earned === null ? 'pending_review' : 'graded'];
        }
        $attempt->item_scores = $scores;
        $attempt->objective_points = $objective / 100;
        $attempt->objective_max_points = $objectiveMax / 100;
        $attempt->total_points = $total / 100;
        $attempt->earned_points = $pending ? null : $objective / 100;
        $attempt->percentage = $pending || $total === 0 ? null : round($objective * 100 / $total, 2);
        $attempt->passed = $attempt->percentage !== null && $attempt->passing_score !== null ? (float) $attempt->percentage >= (float) $attempt->passing_score : null;
        $attempt->result_status = $pending ? 'pending_review' : 'graded';
        $attempt->scored_at = now();
        $attempt->save();
    }

    /** Safe retry for historical submissions; never recalculates an existing score. */
    public function reconcile(ExaminationAttempt $attempt): ExaminationAttempt
    {
        return DB::transaction(function () use ($attempt) {
            $locked = ExaminationAttempt::whereKey($attempt->id)->lockForUpdate()->firstOrFail();
            if ($locked->scoring_key === null) {
                $locked->passing_score = $locked->examination->passing_score;
            }
            $this->score($locked);

            return $locked;
        });
    }
}
