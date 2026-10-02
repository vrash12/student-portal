<?php

namespace App\Services\Grading;

use App\Enums\AssessmentStatus;
use App\Enums\AuditAction;
use App\Models\Assessment;
use App\Models\AssessmentCategory;
use App\Models\AssessmentScore;
use App\Models\AssessmentScoreRevision;
use App\Models\Candidate;
use App\Models\ClassSubject;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\DecimalValue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Assessments of a class subject and their lifecycle: Draft -> Finalized.
 *
 * Every state-changing method locks the class subject row and then the
 * assessment row, so finalization, edits, deletion, score recording,
 * grading-scheme edits, and subject removal never interleave. Authorization
 * happens before these methods are called (AssessmentPolicy /
 * ClassSubjectPolicy).
 */
final class AssessmentService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{title: string, assessment_category_id: int, max_score: string, assessed_on: ?string}  $data
     *
     * @throws ValidationException
     */
    public function create(ClassSubject $offering, array $data, User $actor): Assessment
    {
        return $this->guardUniqueTitle(fn (): Assessment => DB::transaction(function () use ($offering, $data, $actor): Assessment {
            // Serializes with grading-scheme edits and subject removal, which
            // lock the same row.
            ClassSubject::query()->whereKey($offering->getKey())->lockForUpdate()->firstOrFail();

            $assessment = new Assessment([
                'title' => $data['title'],
                'max_score' => DecimalValue::normalize($data['max_score']),
                'assessed_on' => $data['assessed_on'],
            ]);
            $assessment->classSubject()->associate($offering);
            $assessment->category()->associate($this->category($offering, $data['assessment_category_id']));
            $assessment->creator()->associate($actor);
            $assessment->status = AssessmentStatus::Draft;
            $assessment->save();

            $this->audit->record(AuditAction::AssessmentCreated, $assessment, newValues: $this->snapshot($assessment), actor: $actor);

            return $assessment;
        }));
    }

    /**
     * Draft assessments only. The maximum score cannot drop below a score
     * that is already recorded.
     *
     * @param  array{title: string, assessment_category_id: int, max_score: string, assessed_on: ?string}  $data
     *
     * @throws ValidationException
     */
    public function update(Assessment $assessment, array $data, User $actor): Assessment
    {
        return $this->guardUniqueTitle(fn (): Assessment => DB::transaction(function () use ($assessment, $data): Assessment {
            $locked = $this->lockDraft($assessment);
            $before = $this->snapshot($locked);

            $highest = $locked->scores()->max('score');
            if ($highest !== null && DecimalValue::toHundredths($data['max_score']) < DecimalValue::toHundredths($highest)) {
                throw ValidationException::withMessages([
                    'max_score' => sprintf(
                        'A recorded score of %s is higher than this maximum. Use a maximum score of at least %s, or change that score first.',
                        DecimalValue::display($highest),
                        DecimalValue::display($highest),
                    ),
                ]);
            }

            $locked->fill([
                'title' => $data['title'],
                'max_score' => DecimalValue::normalize($data['max_score']),
                'assessed_on' => $data['assessed_on'],
            ]);
            $locked->category()->associate($this->category($locked->classSubject, $data['assessment_category_id']));
            $locked->save();

            $this->audit->recordChanges(AuditAction::AssessmentUpdated, $locked, $before, $this->snapshot($locked));

            $assessment->setRawAttributes($locked->getAttributes(), sync: true);

            return $locked;
        }));
    }

    /**
     * Deletes a draft assessment together with its draft scores and their
     * revisions. The scores are kept in the audit entry so the deletion
     * remains traceable. Finalized assessments can never be deleted.
     *
     * @throws ValidationException
     */
    public function delete(Assessment $assessment, User $actor): void
    {
        DB::transaction(function () use ($assessment, $actor): void {
            $locked = $this->lockDraft($assessment);

            $scores = $locked->scores()
                ->with('candidate:id,candidate_number')
                ->get()
                ->sortBy(fn (AssessmentScore $score): string => $score->candidate->candidate_number)
                ->map(fn (AssessmentScore $score): array => [
                    'candidate' => $score->candidate->candidate_number,
                    'score' => DecimalValue::normalize($score->score),
                    'comment' => $score->comment,
                ])
                ->values()
                ->all();

            $this->audit->record(
                AuditAction::AssessmentDeleted,
                $locked,
                oldValues: [...$this->snapshot($locked), 'scores' => $scores],
                actor: $actor,
            );

            AssessmentScoreRevision::query()
                ->whereIn('assessment_score_id', $locked->scores()->select('id'))
                ->delete();
            $locked->scores()->delete();
            $locked->delete();
        });
    }

    /**
     * Makes the scores count toward grades. Candidates without a score stay
     * missing (their grade shows Missing Scores) until a correction records one.
     *
     * @throws ValidationException
     */
    public function finalize(Assessment $assessment, User $actor): void
    {
        DB::transaction(function () use ($assessment, $actor): void {
            $locked = $this->lockDraft($assessment);

            $recorded = $locked->scores()->whereNotNull('score')->count();
            if ($recorded === 0) {
                throw ValidationException::withMessages([
                    'assessment' => 'Record at least one score before finalizing this assessment.',
                ]);
            }

            $locked->loadMissing('classSubject');
            $withoutScore = Candidate::query()
                ->gradableIn($locked->classSubject->class_batch_id)
                ->whereDoesntHave('assessmentScores', fn ($scores) => $scores
                    ->where('assessment_id', $locked->id)
                    ->whereNotNull('score'))
                ->count();

            $locked->status = AssessmentStatus::Finalized;
            $locked->finalized_at = now();
            $locked->finalizer()->associate($actor);
            $locked->save();

            $this->audit->record(AuditAction::AssessmentFinalized, $locked, newValues: [
                'title' => $locked->title,
                'scores_recorded' => $recorded,
                'candidates_without_score' => $withoutScore,
            ], actor: $actor);

            $assessment->setRawAttributes($locked->getAttributes(), sync: true);
        });
    }

    /**
     * Locks the class subject and then the assessment row (the same order
     * everywhere, so concurrent requests cannot deadlock), and confirms the
     * assessment is still a draft. The class subject lock serializes these
     * changes with grading-scheme edits and subject removal.
     *
     * @throws ValidationException
     */
    private function lockDraft(Assessment $assessment): Assessment
    {
        ClassSubject::query()->whereKey($assessment->class_subject_id)->lockForUpdate()->firstOrFail();
        $locked = Assessment::query()->with('classSubject')->lockForUpdate()->findOrFail($assessment->getKey());

        if (! $locked->isDraft()) {
            throw ValidationException::withMessages([
                'assessment' => 'This assessment has been finalized. Finalized scores can only be changed through a correction.',
            ]);
        }

        return $locked;
    }

    /**
     * The category must belong to the same class subject. The composite
     * foreign key on assessments guarantees this in the database as well.
     *
     * @throws ValidationException
     */
    private function category(ClassSubject $offering, int $categoryId): AssessmentCategory
    {
        $category = $offering->assessmentCategories()->whereKey($categoryId)->first();

        if ($category === null) {
            throw ValidationException::withMessages(['assessment_category_id' => 'Select a grading component of this subject.']);
        }

        return $category;
    }

    /**
     * Two instructors saving the same title at once both pass validation; the
     * unique index decides, and the loser gets a normal validation message.
     *
     * @template TResult
     *
     * @param  callable(): TResult  $callback
     * @return TResult
     *
     * @throws ValidationException
     */
    private function guardUniqueTitle(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['title' => 'This subject already has an assessment with this title.']);
        }
    }

    /**
     * @return array{title: string, category: string, max_score: string|null, assessed_on: string|null}
     */
    private function snapshot(Assessment $assessment): array
    {
        $assessment->loadMissing('category');

        return [
            'title' => $assessment->title,
            'category' => $assessment->category->name,
            'max_score' => DecimalValue::normalize($assessment->max_score),
            'assessed_on' => $assessment->assessed_on?->toDateString(),
        ];
    }
}
