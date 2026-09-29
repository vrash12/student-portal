<?php

namespace App\Services\QuestionBank;

use App\Models\Question;
use App\Models\QuestionChoice;
use App\Support\DecimalValue;

/**
 * The two shapes a question is sent to the browser in
 * (docs/question-bank-examination-contract.md):
 *
 * - staff(): for authorized staff pages (question bank, examination builder
 *   review). Includes correct answers and the explanation.
 * - forCandidate(): for candidate-facing pages. A whitelist that never
 *   includes correct answers, the explanation, or bank metadata.
 *
 * Never serialize Question or QuestionChoice models directly into a page.
 */
final class QuestionPresenter
{
    /**
     * Relations staff() reads. Eager load them for lists: ->with(QuestionPresenter::STAFF_RELATIONS).
     */
    public const STAFF_RELATIONS = ['subject:id,code,name', 'topic:id,name', 'choices'];

    /**
     * @return array{
     *     id: int,
     *     subject: array{id: int, code: string, name: string},
     *     topic: array{id: int, name: string}|null,
     *     type: array{value: string, label: string},
     *     prompt: string,
     *     points: string,
     *     explanation: string|null,
     *     isActive: bool,
     *     isLocked: bool,
     *     choices: list<array{id: int, position: int, label: string, text: string, isCorrect: bool}>
     * }
     */
    public function staff(Question $question): array
    {
        $question->loadMissing(self::STAFF_RELATIONS);

        return [
            'id' => $question->id,
            'subject' => [
                'id' => $question->subject->id,
                'code' => $question->subject->code,
                'name' => $question->subject->name,
            ],
            'topic' => $question->topic === null ? null : ['id' => $question->topic->id, 'name' => $question->topic->name],
            'type' => $question->type->toArray(),
            'prompt' => $question->prompt,
            'points' => DecimalValue::display($question->points),
            'explanation' => $question->explanation,
            'isActive' => $question->is_active,
            'isLocked' => $question->isLocked(),
            'choices' => $question->choices
                ->map(fn (QuestionChoice $choice): array => [
                    'id' => $choice->id,
                    'position' => $choice->position,
                    'label' => $choice->letter(),
                    'text' => $choice->text,
                    'isCorrect' => $choice->is_correct,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * Candidate-facing view: what a candidate needs to answer the question and
     * nothing else. Choices are in stored order; examinations may shuffle them.
     *
     * @return array{
     *     id: int,
     *     type: array{value: string, label: string},
     *     prompt: string,
     *     choices: list<array{id: int, text: string}>
     * }
     */
    public function forCandidate(Question $question): array
    {
        $question->loadMissing('choices');

        return [
            'id' => $question->id,
            'type' => $question->type->toArray(),
            'prompt' => $question->prompt,
            'choices' => $question->choices
                ->map(fn (QuestionChoice $choice): array => ['id' => $choice->id, 'text' => $choice->text])
                ->values()
                ->all(),
        ];
    }
}
