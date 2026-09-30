<?php

namespace App\Services\QuestionBank;

/**
 * Input for creating or editing a question (QuestionBankService).
 *
 * The topic is a name, not an id: an existing topic of the question's subject
 * is reused (ignoring case) and a new name creates the topic in that subject,
 * so a topic of another subject can never be attached. The content is null
 * when an edit leaves it out, which the edit form does for locked questions.
 *
 * Text is normalized on construction: surrounding whitespace is removed and
 * an empty topic or explanation becomes null.
 */
final readonly class QuestionData
{
    public ?string $topic;

    /** Decimal string with at most two decimals, e.g. "1" or "2.5". */
    public string $points;

    /** Staff-only notes: why an answer is correct, or essay grading guidance. */
    public ?string $explanation;

    public ?QuestionContent $content;

    public function __construct(?string $topic, string $points, ?string $explanation, ?QuestionContent $content)
    {
        $this->topic = self::textOrNull($topic);
        $this->points = trim($points);
        $this->explanation = self::textOrNull($explanation);
        $this->content = $content;
    }

    private static function textOrNull(?string $text): ?string
    {
        $normalized = $text === null ? '' : QuestionContent::normalizeText($text);

        return $normalized === '' ? null : $normalized;
    }
}
