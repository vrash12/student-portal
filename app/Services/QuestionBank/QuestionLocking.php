<?php

namespace App\Services\QuestionBank;

use App\Models\Question;
use Illuminate\Database\Eloquent\Collection;

/**
 * The only way other modules change question rows (contract with the
 * examination builder, docs/question-bank-examination-contract.md).
 *
 * Publishing an examination locks its questions: from then on their type,
 * prompt, and choices (including the correct answer) can no longer change,
 * so a published examination always shows and scores its questions as they
 * were published. Locks are permanent. Topic, points, explanation, and the
 * active flag stay editable.
 */
final class QuestionLocking
{
    /**
     * Loads the questions with row locks, in the caller's transaction, so no
     * edit or deactivation can run between the caller's checks and lock().
     * Choices are loaded too.
     *
     * @param  list<int>  $questionIds
     * @return Collection<int, Question> keyed by question id
     */
    public function lockRowsForPublication(array $questionIds): Collection
    {
        if ($questionIds === []) {
            return new Collection;
        }

        return Question::query()
            ->whereKey($questionIds)
            ->with('choices')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
    }

    /**
     * Marks the questions as used in a published examination. Call inside the
     * publishing transaction, after lockRowsForPublication() and the checks.
     * Already locked questions keep their original lock time. Does not change
     * updated_at, which records content edits only.
     *
     * @param  list<int>  $questionIds
     */
    public function lock(array $questionIds): void
    {
        if ($questionIds === []) {
            return;
        }

        Question::query()
            ->toBase()
            ->whereIn('id', $questionIds)
            ->whereNull('locked_at')
            ->update(['locked_at' => now()]);
    }
}
