<?php

namespace App\Services\QuestionBank;

use App\Models\Question;
use App\Models\QuestionChoice;
use App\Models\QuestionMedia;
use App\Support\DecimalValue;
use Illuminate\Support\Str;

/**
 * The shapes a question is sent to the browser in
 * (docs/question-bank-examination-contract.md):
 *
 * - staff(): for authorized staff pages (question bank, examination builder
 *   review). Includes correct answers and the explanation.
 * - summary(): list rows for authorized staff. A prompt excerpt and bank
 *   metadata; never choices, correct answers, or the explanation.
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
    public const STAFF_RELATIONS = ['subject:id,code,name', 'topic:id,name', 'choices.image', 'media'];

    /**
     * Relations summary() reads. Eager load them for lists: ->with(QuestionPresenter::SUMMARY_RELATIONS).
     */
    public const SUMMARY_RELATIONS = ['subject:id,code,name', 'topic:id,name'];

    /**
     * Approximate length of the prompt excerpt in summary().
     */
    public const EXCERPT_LENGTH = 200;

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
                    'image' => $choice->image === null ? null : $this->staffMedia($choice->image),
                ])
                ->values()
                ->all(),
            // Served only to staff who teach the subject (/question-media/{id}).
            'media' => $question->media
                ->map(fn (QuestionMedia $media): array => $this->staffMedia($media))
                ->values()
                ->all(),
        ];
    }

    /**
     * List row for staff lists (the question bank): enough to recognize a
     * question, without its choices, correct answer, or explanation.
     *
     * @return array{
     *     id: int,
     *     subject: array{id: int, code: string, name: string},
     *     topic: array{id: int, name: string}|null,
     *     type: array{value: string, label: string},
     *     excerpt: string,
     *     points: string,
     *     isActive: bool,
     *     isLocked: bool
     * }
     */
    public function summary(Question $question): array
    {
        $question->loadMissing(self::SUMMARY_RELATIONS);

        return [
            'id' => $question->id,
            'subject' => [
                'id' => $question->subject->id,
                'code' => $question->subject->code,
                'name' => $question->subject->name,
            ],
            'topic' => $question->topic === null ? null : ['id' => $question->topic->id, 'name' => $question->topic->name],
            'type' => $question->type->toArray(),
            'excerpt' => self::excerpt($question->prompt),
            'points' => DecimalValue::display($question->points),
            'isActive' => $question->is_active,
            'isLocked' => $question->isLocked(),
        ];
    }

    /**
     * The beginning of a prompt on one line: line breaks and repeated
     * whitespace collapsed, cut at about EXCERPT_LENGTH characters (at a word
     * boundary when one is close). The text is kept as written; it is
     * escaped when rendered.
     */
    public static function excerpt(string $prompt): string
    {
        $oneLine = trim((string) preg_replace('/\s+/u', ' ', $prompt));

        if (mb_strlen($oneLine) <= self::EXCERPT_LENGTH) {
            return $oneLine;
        }

        $cut = mb_substr($oneLine, 0, self::EXCERPT_LENGTH);
        $lastSpace = mb_strrpos($cut, ' ');
        if ($lastSpace !== false && $lastSpace >= self::EXCERPT_LENGTH - 30) {
            $cut = mb_substr($cut, 0, $lastSpace);
        }

        return Str::of($cut)->rtrim(' ,.;:')->append('…')->toString();
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
        $question->loadMissing(['choices.image', 'media']);

        return [
            'id' => $question->id,
            'type' => $question->type->toArray(),
            'prompt' => $question->prompt,
            'choices' => $question->choices
                ->map(fn (QuestionChoice $choice): array => ['id' => $choice->id, 'text' => $choice->text, 'image' => $choice->image === null ? null : $this->mediaView($choice->image)])
                ->values()
                ->all(),
            // No URL or file name: the candidate page builds the attempt-scoped URL
            // (/portal/attempts/{attempt}/media/{id}), which checks the attempt.
            'media' => $question->media->map(fn (QuestionMedia $media): array => $this->mediaView($media))->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function staffMedia(QuestionMedia $media): array
    {
        return [...$this->mediaView($media), 'url' => route('question-media.show', $media, false), 'originalName' => $media->original_name];
    }

    /**
     * @return array{id: int, kind: string, description: string, mimeType: string, width: int|null, height: int|null}
     */
    private function mediaView(QuestionMedia $media): array
    {
        return [
            'id' => $media->id,
            'kind' => $media->kind,
            'description' => $media->description,
            'mimeType' => $media->mime_type,
            'width' => $media->width,
            'height' => $media->height,
        ];
    }
}
