<?php

namespace App\Http\Requests\QuestionBank;

use App\Models\Examination;

/**
 * A new question written from an examination's Questions step (owner
 * request, 2026-10-03). Same fields and rules as the Question Bank's create
 * form, except the subject: it is always the examination's subject, never
 * taken from the client. Authorization is on the route (the examination's
 * instructor, with question_bank.manage); ExaminationService checks it again.
 */
class ExaminationQuestionRequest extends QuestionRequest
{
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        /** @var Examination $examination */
        $examination = $this->route('examination');
        $this->merge(['subject_id' => $examination->classSubject()->value('subject_id')]);
    }
}
