<?php

namespace App\Services\QuestionBank;

use App\Enums\QuestionType;
use App\Models\Question;
use App\Models\QuestionChoice;

/**
 * What a question asks and how it is answered: its type, prompt, and answer
 * choices in display order (position 1 is "A"). This is the part of a
 * question that can no longer change once it is locked.
 *
 * The named constructors normalize the text (line breaks as "\n", surrounding
 * whitespace removed; line breaks inside the prompt are kept) and build the
 * choices each type needs. Whether the content follows the rules (2–6
 * distinct choices with exactly one correct for multiple choice) is checked
 * by QuestionBankService before anything is stored.
 */
final readonly class QuestionContent
{
    /**
     * @param  list<array{text: string, is_correct: bool}>  $choices
     */
    private function __construct(
        public QuestionType $type,
        public string $prompt,
        public array $choices,
    ) {}

    /**
     * @param  list<array{text: string, is_correct: bool}>  $choices  in display order
     */
    public static function multipleChoice(string $prompt, array $choices): self
    {
        return new self(
            QuestionType::MultipleChoice,
            self::normalizeText($prompt),
            array_map(
                fn (array $choice): array => ['text' => self::normalizeText($choice['text']), 'is_correct' => $choice['is_correct']],
                array_values($choices),
            ),
        );
    }

    /**
     * Always the two choices "True" (position 1) and "False" (position 2).
     */
    public static function trueFalse(string $prompt, bool $answer): self
    {
        [$true, $false] = QuestionType::TRUE_FALSE_CHOICES;

        return new self(QuestionType::TrueFalse, self::normalizeText($prompt), [
            ['text' => $true, 'is_correct' => $answer],
            ['text' => $false, 'is_correct' => ! $answer],
        ]);
    }

    public static function essay(string $prompt): self
    {
        return new self(QuestionType::Essay, self::normalizeText($prompt), []);
    }

    /**
     * The content of a stored question, exactly as stored. Its choices must
     * be loaded.
     */
    public static function of(Question $question): self
    {
        return new self(
            $question->type,
            $question->prompt,
            $question->choices
                ->map(fn (QuestionChoice $choice): array => ['text' => $choice->text, 'is_correct' => $choice->is_correct])
                ->values()
                ->all(),
        );
    }

    /**
     * Line breaks as "\n" and no surrounding whitespace. Line breaks inside
     * the text are kept.
     */
    public static function normalizeText(string $text): string
    {
        return trim(str_replace(["\r\n", "\r"], "\n", $text));
    }

    /**
     * @return list<string>
     */
    public function choiceTexts(): array
    {
        return array_column($this->choices, 'text');
    }

    /**
     * Number of choices marked correct (exactly one in a valid objective question).
     */
    public function correctCount(): int
    {
        return count(array_filter($this->choices, fn (array $choice): bool => $choice['is_correct']));
    }

    /**
     * 1-based position of the (first) correct choice, or null when there is none.
     */
    public function correctPosition(): ?int
    {
        foreach ($this->choices as $index => $choice) {
            if ($choice['is_correct']) {
                return $index + 1;
            }
        }

        return null;
    }

    public function equals(self $other): bool
    {
        return $this->type === $other->type
            && $this->prompt === $other->prompt
            && $this->choices === $other->choices;
    }
}
