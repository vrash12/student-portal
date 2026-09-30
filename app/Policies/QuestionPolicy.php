<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Question;
use App\Models\User;

/**
 * Question bank access (docs/question-bank-examination-contract.md): the
 * question_bank.manage permission and, for an existing question, teaching its
 * subject (User::teachesSubject: an active account with classes.teach that is
 * assigned to that subject in at least one class, in any academic period).
 *
 * Knowing a question id never grants access. Administrators do not hold the
 * permission by default, and access is never decided by role name. Whether a
 * change is allowed in the question's current state (locked or not) is
 * decided by QuestionBankService.
 */
class QuestionPolicy
{
    /**
     * Open the question bank. It only ever lists the subjects the user teaches.
     */
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission(Permission::ManageQuestionBank);
    }

    /**
     * Add questions. The subject must be one the user teaches, which
     * QuestionRequest validates against User::taughtSubjectIds().
     */
    public function create(User $actor): bool
    {
        return $actor->hasPermission(Permission::ManageQuestionBank);
    }

    /**
     * Preview a question, including its correct answer and explanation.
     */
    public function view(User $actor, Question $question): bool
    {
        return $this->managesSubjectOf($actor, $question);
    }

    /**
     * See a question's images and media: any active teaching staff of the
     * subject (question bank, examination review, and essay grading pages).
     */
    public function viewMedia(User $actor, Question $question): bool
    {
        return $actor->teachesSubject($question->subject_id);
    }

    public function update(User $actor, Question $question): bool
    {
        return $this->managesSubjectOf($actor, $question);
    }

    public function activate(User $actor, Question $question): bool
    {
        return $this->managesSubjectOf($actor, $question);
    }

    public function deactivate(User $actor, Question $question): bool
    {
        return $this->managesSubjectOf($actor, $question);
    }

    public function duplicate(User $actor, Question $question): bool
    {
        return $this->managesSubjectOf($actor, $question);
    }

    private function managesSubjectOf(User $actor, Question $question): bool
    {
        return $actor->hasPermission(Permission::ManageQuestionBank) && $actor->teachesSubject($question->subject_id);
    }
}
