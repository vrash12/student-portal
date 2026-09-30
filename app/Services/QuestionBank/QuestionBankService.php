<?php

namespace App\Services\QuestionBank;

use App\Enums\AuditAction;
use App\Enums\QuestionType;
use App\Models\Question;
use App\Models\QuestionChoice;
use App\Models\QuestionTopic;
use App\Models\Subject;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\DecimalValue;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Creates and changes the questions of the question bank (Milestone 7,
 * docs/question-bank-examination-contract.md).
 *
 * Rules enforced here, whatever the caller (QuestionRequest checks the input
 * shape and gives the same messages earlier):
 * - multiple choice: 2–6 choices with distinct texts (trimmed, ignoring case)
 *   and exactly one correct; true / false: "True" and "False" with one
 *   correct; essay: no choices;
 * - a locked question (part of a published examination) keeps its type,
 *   prompt, and choices; its topic, points, and explanation can change;
 * - questions are never deleted, only deactivated.
 *
 * Edits and status changes lock the question row before reading locked_at and
 * is_active, so they are serialized with publication
 * (QuestionLocking::lockRowsForPublication) and with each other. Saves
 * without changes write nothing and are not audited. Audit entries hold
 * non-sensitive values only: never question text, choice text, correct
 * answers, or explanations. Authorization happens before these methods are
 * called (QuestionPolicy).
 */
final class QuestionBankService
{
    public const PROMPT_MAX_LENGTH = 5000;

    public const CHOICE_MAX_LENGTH = 1000;

    public const TOPIC_MAX_LENGTH = 100;

    public const EXPLANATION_MAX_LENGTH = 5000;

    public const POINTS_MIN = '0.01';

    public const POINTS_MAX = '100';

    public const LOCKED_CONTENT_MESSAGE = 'This question is part of a published examination, so its type, text, and answers can no longer change. Duplicate it to make a changed version.';

    public const DUPLICATE_CHOICE_MESSAGE = 'Each choice needs different text. Capital letters do not make choices different.';

    public const CORRECT_CHOICE_MESSAGE = 'Mark exactly one choice as the correct answer.';

    public const CHOICE_COUNT_MESSAGE = 'A multiple choice question needs '.QuestionType::MIN_CHOICES.' to '.QuestionType::MAX_CHOICES.' choices.';

    public const EMPTY_CHOICE_MESSAGE = 'Enter the text of this choice, or remove it.';

    public const TRUE_FALSE_MESSAGE = 'Select whether the statement is true or false.';

    public const POINTS_MESSAGE = 'Enter the points as a number from '.self::POINTS_MIN.' to '.self::POINTS_MAX.'.';

    public function __construct(private readonly AuditLogger $audit, private readonly QuestionMediaService $media) {}

    /**
     * @throws ValidationException
     */
    public function create(Subject $subject, QuestionData $data, User $actor): Question
    {
        $content = $data->content ?? throw new InvalidArgumentException('A new question needs its type, prompt, and choices.');
        $this->assertValid($data);

        return DB::transaction(function () use ($subject, $data, $content, $actor): Question {
            $question = new Question([
                'prompt' => $content->prompt,
                'points' => DecimalValue::normalize($data->points),
                'explanation' => $data->explanation,
            ]);
            $question->type = $content->type;
            $question->is_active = true;
            $question->subject()->associate($subject);
            $this->setTopic($question, $data->topic === null ? null : $this->resolveTopic($subject, $data->topic));
            $question->creator()->associate($actor);
            $question->save();

            $this->insertChoices($question, $content->choices);

            $this->audit->record(AuditAction::QuestionCreated, $question, newValues: $this->creationValues($question, $content), actor: $actor);

            return $question;
        });
    }

    /**
     * Saves the edit form. A locked question keeps its content: content that
     * differs from what is stored is rejected. Only changed choices are
     * written (see syncChoices()).
     *
     * @return bool whether anything changed; a save without changes writes nothing
     *
     * @throws ValidationException
     */
    public function update(Question $question, QuestionData $data, User $actor): bool
    {
        $this->assertValid($data);

        return DB::transaction(function () use ($question, $data, $actor): bool {
            $locked = $this->lockWithChoices($question);
            $current = QuestionContent::of($locked);

            if ($locked->isLocked() && $data->content !== null && ! $data->content->equals($current)) {
                throw ValidationException::withMessages(['content' => self::LOCKED_CONTENT_MESSAGE]);
            }

            $content = $locked->isLocked() || $data->content === null ? $current : $data->content;
            $topic = $this->topicFor($locked, $data->topic);
            $points = DecimalValue::normalize($data->points);
            $changedContent = $this->changedContentFields($current, $content, $locked->explanation, $data->explanation);

            $unchanged = $changedContent === []
                && $content->type === $current->type
                && $topic?->id === $locked->question_topic_id
                && $points === DecimalValue::normalize($locked->points);

            if ($unchanged) {
                return false;
            }

            $before = $this->auditValues($locked);

            $locked->fill(['prompt' => $content->prompt, 'points' => $points, 'explanation' => $data->explanation]);
            $locked->type = $content->type;
            $this->setTopic($locked, $topic);
            $locked->updater()->associate($actor);
            // The choices may be the only change, so the row is always touched.
            $locked->updated_at = $locked->freshTimestamp();
            $locked->save();

            if ($content->choices !== $current->choices) {
                $this->syncChoices($locked, $content->choices);
            } elseif ($content->type !== QuestionType::MultipleChoice) {
                $this->media->removeChoiceImages($locked, 0);
            }

            $after = $this->auditValues($locked);
            if ($changedContent !== []) {
                // Which content fields changed, never their values.
                $after['changed'] = $changedContent;
            }
            $this->audit->recordChanges(AuditAction::QuestionUpdated, $locked, $before, $after);

            $question->setRawAttributes($locked->getAttributes(), sync: true);

            return true;
        });
    }

    /**
     * Makes the question available for new examinations again.
     *
     * @return bool false when it was already active (nothing is written)
     */
    public function activate(Question $question, User $actor): bool
    {
        return $this->changeStatus($question, true, $actor);
    }

    /**
     * Keeps the question out of new examinations. Examinations that already
     * include it keep it.
     *
     * @return bool false when it was already inactive (nothing is written)
     */
    public function deactivate(Question $question, User $actor): bool
    {
        return $this->changeStatus($question, false, $actor);
    }

    /**
     * An active, unlocked copy of the question (same subject, topic, type,
     * prompt, points, explanation, and choices) created by the actor, for
     * example to change a locked question. The original is not changed.
     */
    public function duplicate(Question $question, User $actor): Question
    {
        return DB::transaction(function () use ($question, $actor): Question {
            $source = Question::query()->with(['subject', 'topic', 'choices'])->findOrFail($question->getKey());
            $content = QuestionContent::of($source);

            $copy = new Question([
                'prompt' => $source->prompt,
                'points' => $source->points,
                'explanation' => $source->explanation,
            ]);
            $copy->type = $source->type;
            $copy->is_active = true;
            $copy->subject()->associate($source->subject);
            $this->setTopic($copy, $source->topic);
            $copy->creator()->associate($actor);
            $copy->save();

            $this->insertChoices($copy, $content->choices);
            $this->media->copyAll($source, $copy, $actor);

            $this->audit->record(AuditAction::QuestionCreated, $copy, newValues: [
                ...$this->creationValues($copy, $content),
                'duplicated_from' => $source->id,
            ], actor: $actor);

            return $copy;
        });
    }

    private function changeStatus(Question $question, bool $active, User $actor): bool
    {
        return DB::transaction(function () use ($question, $active, $actor): bool {
            // Serialized with publication, which checks is_active under the same lock.
            $locked = Question::query()->lockForUpdate()->findOrFail($question->getKey());
            $changed = $locked->is_active !== $active;

            if ($changed) {
                $locked->is_active = $active;
                // updated_at records content edits only (as with QuestionLocking);
                // status changes are recorded in the audit log.
                Question::withoutTimestamps(fn () => $locked->save());

                $this->audit->record(
                    $active ? AuditAction::QuestionActivated : AuditAction::QuestionDeactivated,
                    $locked,
                    oldValues: ['is_active' => ! $active],
                    newValues: ['is_active' => $active],
                    actor: $actor,
                );
            }

            $question->setRawAttributes($locked->getAttributes(), sync: true);

            return $changed;
        });
    }

    /**
     * The question row and its choices, locked in the caller's transaction.
     */
    private function lockWithChoices(Question $question): Question
    {
        return Question::query()
            ->with(['subject', 'topic', 'choices' => fn (HasMany $choices) => $choices->lockForUpdate()])
            ->lockForUpdate()
            ->findOrFail($question->getKey());
    }

    /**
     * The topic an edit asks for. The current topic written with other
     * capitals is still the current topic.
     */
    private function topicFor(Question $question, ?string $name): ?QuestionTopic
    {
        if ($name === null) {
            return null;
        }

        if ($question->topic !== null && mb_strtolower($question->topic->name) === mb_strtolower($name)) {
            return $question->topic;
        }

        return $this->resolveTopic($question->subject, $name);
    }

    /**
     * The subject's topic with this name, ignoring case (the column's
     * case-insensitive collation), created when it does not exist yet. The
     * unique index on (subject_id, name) decides between concurrent requests.
     */
    private function resolveTopic(Subject $subject, string $name): QuestionTopic
    {
        try {
            return $subject->questionTopics()->createOrFirst(['name' => $name]);
        } catch (UniqueConstraintViolationException) {
            // createOrFirst() looks the topic up with a plain read, which uses
            // this transaction's snapshot and can miss a topic that another
            // request created a moment ago. A locking read sees the committed row.
            return $subject->questionTopics()->where('name', $name)->sharedLock()->firstOrFail();
        }
    }

    private function setTopic(Question $question, ?QuestionTopic $topic): void
    {
        $question->topic()->associate($topic);
        // associate(null) forgets the relation; keep it set for the audit values.
        $question->setRelation('topic', $topic);
    }

    /**
     * @param  list<array{text: string, is_correct: bool}>  $choices
     */
    private function insertChoices(Question $question, array $choices): void
    {
        foreach ($choices as $index => $values) {
            $question->choices()->create([
                'position' => $index + 1,
                'text' => $values['text'],
                'is_correct' => $values['is_correct'],
            ]);
        }
    }

    /**
     * Makes the stored choices match the new ones by position: a kept
     * position keeps its row (and id), new positions are inserted, and removed
     * ones are deleted. The old correct choice is unmarked before the new one
     * is marked, because the unique correct_marker allows only one correct
     * choice at any moment.
     *
     * @param  list<array{text: string, is_correct: bool}>  $choices
     */
    private function syncChoices(Question $question, array $choices): void
    {
        $existing = $question->choices->keyBy('position');
        $count = count($choices);

        // Choice images exist only on multiple-choice questions, for kept choices.
        $this->media->removeChoiceImages($question, $question->type === QuestionType::MultipleChoice ? $count : 0);
        $question->choices()->where('position', '>', $count)->delete();

        foreach ($existing as $position => $choice) {
            if ($position <= $count && $choice->is_correct && ! $choices[$position - 1]['is_correct']) {
                $choice->is_correct = false;
                $choice->save();
            }
        }

        foreach ($choices as $index => $values) {
            $choice = $existing->get($index + 1) ?? new QuestionChoice(['position' => $index + 1]);
            $choice->fill(['text' => $values['text'], 'is_correct' => $values['is_correct']]);
            if (! $choice->exists) {
                $choice->question()->associate($question);
            }
            $choice->save();
        }

        $question->load('choices');
    }

    /**
     * Names of the content fields that differ. Their values are never audited.
     *
     * @return list<string>
     */
    private function changedContentFields(QuestionContent $before, QuestionContent $after, ?string $explanationBefore, ?string $explanationAfter): array
    {
        return array_keys(array_filter([
            'prompt' => $before->prompt !== $after->prompt,
            'choices' => $before->choiceTexts() !== $after->choiceTexts(),
            'correct_choice' => $before->correctPosition() !== $after->correctPosition(),
            'explanation' => $explanationBefore !== $explanationAfter,
        ]));
    }

    /**
     * @throws ValidationException
     */
    private function assertValid(QuestionData $data): void
    {
        $errors = $data->content === null ? [] : $this->contentErrors($data->content);

        if ($data->topic !== null && mb_strlen($data->topic) > self::TOPIC_MAX_LENGTH) {
            $errors['topic'] = 'Use at most '.self::TOPIC_MAX_LENGTH.' characters for the topic.';
        }

        $points = is_numeric($data->points) ? DecimalValue::toHundredths($data->points) : null;
        if ($points === null || $points < DecimalValue::toHundredths(self::POINTS_MIN) || $points > DecimalValue::toHundredths(self::POINTS_MAX)) {
            $errors['points'] = self::POINTS_MESSAGE;
        }

        if ($data->explanation !== null && mb_strlen($data->explanation) > self::EXPLANATION_MAX_LENGTH) {
            $errors['explanation'] = 'Use at most '.number_format(self::EXPLANATION_MAX_LENGTH).' characters.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @return array<string, string>
     */
    private function contentErrors(QuestionContent $content): array
    {
        $errors = [];

        if ($content->prompt === '') {
            $errors['prompt'] = 'Enter the question.';
        } elseif (mb_strlen($content->prompt) > self::PROMPT_MAX_LENGTH) {
            $errors['prompt'] = 'Use at most '.number_format(self::PROMPT_MAX_LENGTH).' characters.';
        }

        return [...$errors, ...match ($content->type) {
            QuestionType::MultipleChoice => $this->multipleChoiceErrors($content),
            QuestionType::TrueFalse => $content->choiceTexts() === QuestionType::TRUE_FALSE_CHOICES && $content->correctCount() === 1
                ? []
                : ['correct_answer' => self::TRUE_FALSE_MESSAGE],
            QuestionType::Essay => $content->choices === [] ? [] : ['choices' => 'Essay questions have no answer choices.'],
        }];
    }

    /**
     * @return array<string, string>
     */
    private function multipleChoiceErrors(QuestionContent $content): array
    {
        $errors = [];
        $count = count($content->choices);

        if ($count < QuestionType::MIN_CHOICES || $count > QuestionType::MAX_CHOICES) {
            $errors['choices'] = self::CHOICE_COUNT_MESSAGE;
        }

        $firstIndexByText = [];
        foreach ($content->choiceTexts() as $index => $text) {
            if ($text === '') {
                $errors["choices.{$index}.text"] = self::EMPTY_CHOICE_MESSAGE;

                continue;
            }

            if (mb_strlen($text) > self::CHOICE_MAX_LENGTH) {
                $errors["choices.{$index}.text"] = 'Use at most '.number_format(self::CHOICE_MAX_LENGTH).' characters.';

                continue;
            }

            $key = mb_strtolower($text);
            if (array_key_exists($key, $firstIndexByText)) {
                $errors["choices.{$firstIndexByText[$key]}.text"] = self::DUPLICATE_CHOICE_MESSAGE;
                $errors["choices.{$index}.text"] = self::DUPLICATE_CHOICE_MESSAGE;
            } else {
                $firstIndexByText[$key] = $index;
            }
        }

        if (! isset($errors['choices']) && $content->correctCount() !== 1) {
            $errors['choices'] = self::CORRECT_CHOICE_MESSAGE;
        }

        return $errors;
    }

    /**
     * Values audited when a question is created or duplicated.
     *
     * @return array{subject_id: int, topic: string|null, type: string, points: string|null, is_active: bool, choice_count: int}
     */
    private function creationValues(Question $question, QuestionContent $content): array
    {
        return [
            'subject_id' => $question->subject_id,
            'topic' => $question->topic?->name,
            'type' => $question->type->value,
            'points' => DecimalValue::normalize($question->points),
            'is_active' => $question->is_active,
            'choice_count' => count($content->choices),
        ];
    }

    /**
     * Non-sensitive values compared by the update audit.
     *
     * @return array{topic: string|null, type: string, points: string|null, is_active: bool}
     */
    private function auditValues(Question $question): array
    {
        return [
            'topic' => $question->topic?->name,
            'type' => $question->type->value,
            'points' => DecimalValue::normalize($question->points),
            'is_active' => $question->is_active,
        ];
    }
}
