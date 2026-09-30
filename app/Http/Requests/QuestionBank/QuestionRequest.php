<?php

namespace App\Http\Requests\QuestionBank;

use App\Enums\QuestionType;
use App\Models\Question;
use App\Models\Subject;
use App\Services\QuestionBank\QuestionBankService;
use App\Services\QuestionBank\QuestionContent;
use App\Services\QuestionBank\QuestionData;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A question from the create form (route without {question}) or the edit
 * form. Authorization is on the routes (QuestionPolicy). The subject of a new
 * question must be one the user teaches, checked here against
 * User::taughtSubjectIds(); it is never trusted from the client and cannot
 * change afterwards.
 *
 * Only the fields below are read. Anything else, such as locked_at,
 * is_active, created_by, or a topic id, is ignored: the topic is a name
 * resolved within the question's subject. Choices are read only for multiple
 * choice questions and the true / false answer only for true / false
 * questions. QuestionBankService enforces the question rules again, including
 * that a locked question's content cannot change.
 */
class QuestionRequest extends FormRequest
{
    private const UNREADABLE_CHOICES_MESSAGE = 'The answer choices could not be read. Reload the page and try again.';

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalizes line breaks and trims text. Only strings are changed;
     * anything else is left for the rules to reject.
     */
    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach (['topic', 'prompt', 'explanation'] as $key) {
            $value = $this->input($key);
            if (is_string($value)) {
                $normalized[$key] = QuestionContent::normalizeText($value);
            }
        }

        $choices = $this->input('choices');
        if (is_array($choices)) {
            $normalized['choices'] = array_map(
                fn (mixed $choice): mixed => is_array($choice) && is_string($choice['text'] ?? null)
                    ? [...$choice, 'text' => QuestionContent::normalizeText($choice['text'])]
                    : $choice,
                $choices,
            );
        }

        $this->merge($normalized);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $rules = [
            'topic' => ['nullable', 'string', 'max:'.QuestionBankService::TOPIC_MAX_LENGTH],
            'points' => ['required', 'numeric', 'decimal:0,2', 'min:'.QuestionBankService::POINTS_MIN, 'max:'.QuestionBankService::POINTS_MAX],
            'explanation' => ['nullable', 'string', 'max:'.QuestionBankService::EXPLANATION_MAX_LENGTH],
        ];

        if ($this->editedQuestion() === null) {
            $rules['subject_id'] = ['required', 'integer', Rule::in($this->user()->taughtSubjectIds())];
        }

        if (! $this->expectsContent()) {
            return $rules;
        }

        $onlyMultipleChoice = 'exclude_unless:type,'.QuestionType::MultipleChoice->value;
        $onlyTrueFalse = 'exclude_unless:type,'.QuestionType::TrueFalse->value;

        return [
            ...$rules,
            'type' => ['required', 'string', Rule::enum(QuestionType::class)],
            'prompt' => ['required', 'string', 'max:'.QuestionBankService::PROMPT_MAX_LENGTH],
            'choices' => [$onlyMultipleChoice, 'required', 'array', 'list', 'min:'.QuestionType::MIN_CHOICES, 'max:'.QuestionType::MAX_CHOICES],
            'choices.*' => [$onlyMultipleChoice, 'required', 'array:text,is_correct'],
            'choices.*.text' => [$onlyMultipleChoice, 'required', 'string', 'max:'.QuestionBankService::CHOICE_MAX_LENGTH],
            'choices.*.is_correct' => [$onlyMultipleChoice, 'sometimes', 'boolean'],
            'correct_answer' => [$onlyTrueFalse, 'required', 'string', Rule::in(['true', 'false'])],
        ];
    }

    /**
     * Multiple choice: distinct choice texts (ignoring case) and exactly one
     * correct choice, with the error next to the choice that needs fixing.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->expectsContent() || $this->input('type') !== QuestionType::MultipleChoice->value) {
                    return;
                }

                $choices = $this->input('choices');
                if (! is_array($choices) || ! array_is_list($choices)) {
                    return;
                }

                $this->addDuplicateChoiceErrors($validator, $choices);

                $correct = array_filter($choices, fn (mixed $choice): bool => is_array($choice) && self::isMarkedCorrect($choice));
                if (count($correct) !== 1) {
                    $validator->errors()->add('choices', QuestionBankService::CORRECT_CHOICE_MESSAGE);
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $choiceText = 'Use at most '.number_format(QuestionBankService::CHOICE_MAX_LENGTH).' characters.';
        $longText = 'Use at most '.number_format(QuestionBankService::PROMPT_MAX_LENGTH).' characters.';

        return [
            'subject_id.required' => 'Select the subject this question belongs to.',
            'subject_id.integer' => 'Select one of the subjects you teach.',
            'subject_id.in' => 'Select one of the subjects you teach.',
            'topic.string' => 'Enter the topic as text.',
            'topic.max' => 'Use at most '.QuestionBankService::TOPIC_MAX_LENGTH.' characters for the topic.',
            'type.required' => 'Select a question type.',
            'type.string' => 'Select Multiple Choice, True / False, or Essay.',
            'type.enum' => 'Select Multiple Choice, True / False, or Essay.',
            'prompt.required' => 'Enter the question.',
            'prompt.string' => 'Enter the question as text.',
            'prompt.max' => $longText,
            'points.required' => 'Enter the points, for example 1.',
            'points.numeric' => 'Enter the points as a number, for example 1 or 2.5.',
            'points.decimal' => 'Use at most two decimal places.',
            'points.min' => QuestionBankService::POINTS_MESSAGE,
            'points.max' => QuestionBankService::POINTS_MESSAGE,
            'explanation.string' => 'Enter the explanation as text.',
            'explanation.max' => 'Use at most '.number_format(QuestionBankService::EXPLANATION_MAX_LENGTH).' characters.',
            'choices.required' => QuestionBankService::CHOICE_COUNT_MESSAGE,
            'choices.array' => self::UNREADABLE_CHOICES_MESSAGE,
            'choices.list' => self::UNREADABLE_CHOICES_MESSAGE,
            'choices.min' => 'Add at least '.QuestionType::MIN_CHOICES.' choices.',
            'choices.max' => 'Use at most '.QuestionType::MAX_CHOICES.' choices.',
            'choices.*.required' => self::UNREADABLE_CHOICES_MESSAGE,
            'choices.*.array' => self::UNREADABLE_CHOICES_MESSAGE,
            'choices.*.text.required' => QuestionBankService::EMPTY_CHOICE_MESSAGE,
            'choices.*.text.string' => 'Enter the choice as text.',
            'choices.*.text.max' => $choiceText,
            'choices.*.is_correct.boolean' => self::UNREADABLE_CHOICES_MESSAGE,
            'correct_answer.required' => QuestionBankService::TRUE_FALSE_MESSAGE,
            'correct_answer.string' => QuestionBankService::TRUE_FALSE_MESSAGE,
            'correct_answer.in' => QuestionBankService::TRUE_FALSE_MESSAGE,
        ];
    }

    public function questionData(): QuestionData
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        return new QuestionData(
            topic: self::stringOrNull($validated['topic'] ?? null),
            points: (string) $validated['points'],
            explanation: self::stringOrNull($validated['explanation'] ?? null),
            content: array_key_exists('type', $validated) ? $this->content($validated) : null,
        );
    }

    /**
     * The subject of a new question: one the user teaches (validated above).
     */
    public function subject(): Subject
    {
        return Subject::query()->findOrFail((int) $this->validated('subject_id'));
    }

    /**
     * New and unlocked questions are always saved with their content. The
     * edit form leaves the content out for a locked question; a request that
     * still sends it has it validated, and QuestionBankService rejects it if
     * it differs from what is stored.
     */
    private function expectsContent(): bool
    {
        $question = $this->editedQuestion();

        return $question === null
            || ! $question->isLocked()
            || $this->hasAny(['type', 'prompt', 'choices', 'correct_answer']);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function content(array $validated): QuestionContent
    {
        $prompt = (string) $validated['prompt'];

        return match (QuestionType::from((string) $validated['type'])) {
            QuestionType::MultipleChoice => QuestionContent::multipleChoice($prompt, array_map(
                fn (array $choice): array => ['text' => (string) $choice['text'], 'is_correct' => self::isMarkedCorrect($choice)],
                array_values($validated['choices']),
            )),
            QuestionType::TrueFalse => QuestionContent::trueFalse($prompt, $validated['correct_answer'] === 'true'),
            QuestionType::Essay => QuestionContent::essay($prompt),
        };
    }

    /**
     * @param  list<mixed>  $choices
     */
    private function addDuplicateChoiceErrors(Validator $validator, array $choices): void
    {
        $indexesByText = [];
        foreach ($choices as $index => $choice) {
            $text = is_array($choice) ? ($choice['text'] ?? null) : null;
            if (is_string($text) && $text !== '') {
                $indexesByText[mb_strtolower($text)][] = $index;
            }
        }

        foreach ($indexesByText as $indexes) {
            if (count($indexes) < 2) {
                continue;
            }

            foreach ($indexes as $index) {
                if (! $validator->errors()->has("choices.{$index}.text")) {
                    $validator->errors()->add("choices.{$index}.text", QuestionBankService::DUPLICATE_CHOICE_MESSAGE);
                }
            }
        }
    }

    /**
     * @param  array<mixed>  $choice
     */
    private static function isMarkedCorrect(array $choice): bool
    {
        return in_array($choice['is_correct'] ?? false, [true, 1, '1'], true);
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function editedQuestion(): ?Question
    {
        $question = $this->route('question');

        return $question instanceof Question ? $question : null;
    }
}
