<?php

namespace App\Services\Grading;

use App\Enums\CandidateStatus;
use App\Enums\ScoreRevisionKind;
use App\Models\Assessment;
use App\Models\AssessmentCategory;
use App\Models\AssessmentScore;
use App\Models\AssessmentScoreRevision;
use App\Models\Candidate;
use App\Models\ClassSubject;
use App\Support\DecimalValue;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read model for grading pages: a class subject's scheme, assessments,
 * candidate grades, and an assessment's score sheet and change history.
 * All numbers come from the database and GradeCalculationService.
 */
final class Gradebook
{
    public function __construct(private readonly GradeCalculationService $calculator) {}

    /**
     * Context shown in headers and breadcrumbs of grading pages.
     *
     * @return array{id: int, classBatch: array{id: int, name: string}, subject: array{code: string, name: string}, period: array{name: string, isActive: bool}}
     */
    public function offering(ClassSubject $offering): array
    {
        $offering->loadMissing(['classBatch.academicPeriod', 'subject']);

        return [
            'id' => $offering->id,
            'classBatch' => ['id' => $offering->classBatch->id, 'name' => $offering->classBatch->name],
            'subject' => ['code' => $offering->subject->code, 'name' => $offering->subject->name],
            'period' => [
                'name' => $offering->classBatch->academicPeriod->name,
                'isActive' => $offering->classBatch->academicPeriod->is_active,
            ],
        ];
    }

    /**
     * @return list<array{id: int, name: string, weight: string, assessmentCount: int}>
     */
    public function scheme(ClassSubject $offering): array
    {
        return $offering->assessmentCategories()
            ->withCount('assessments')
            ->get()
            ->map(fn (AssessmentCategory $category): array => [
                'id' => $category->id,
                'name' => $category->name,
                'weight' => DecimalValue::display($category->weight),
                'assessmentCount' => (int) $category->assessments_count,
            ])
            ->values()
            ->all();
    }

    /**
     * Candidates who can currently be graded in the class.
     */
    public function gradableCount(ClassSubject $offering): int
    {
        return Candidate::query()->gradableIn($offering->class_batch_id)->count();
    }

    /**
     * @return list<array{id: int, title: string, category: array{id: int, name: string}, maxScore: string, assessedOn: string|null, status: array{value: string, label: string, tone: string}, scoredCount: int}>
     */
    public function assessments(ClassSubject $offering): array
    {
        $classBatchId = $offering->class_batch_id;

        return $offering->assessments()
            ->with('category:id,name')
            // Counted over the candidates who can currently be graded, the
            // same population as gradableCount and the score sheet.
            ->withCount(['scores as scored_count' => fn (Builder $scores) => $scores
                ->whereNotNull('score')
                ->whereHas('candidate', fn (Builder $candidates) => $candidates->gradableIn($classBatchId))])
            // Undated assessments last, then by date and title.
            ->orderByRaw('`assessed_on` is null')
            ->orderBy('assessed_on')
            ->orderBy('title')
            ->get()
            ->map(fn (Assessment $assessment): array => [
                'id' => $assessment->id,
                'title' => $assessment->title,
                'category' => ['id' => $assessment->category->id, 'name' => $assessment->category->name],
                'maxScore' => DecimalValue::display($assessment->max_score),
                'assessedOn' => $assessment->assessed_on?->toDateString(),
                'status' => $assessment->status->toArray(),
                'scoredCount' => (int) $assessment->scored_count,
            ])
            ->values()
            ->all();
    }

    /**
     * Calculated grades of the gradable candidates, one page at a time.
     */
    public function candidateGrades(ClassSubject $offering, string $search, int $perPage): LengthAwarePaginator
    {
        $page = Candidate::query()
            ->gradableIn($offering->class_batch_id)
            ->when($search !== '', fn (Builder $query) => $query->matching($search))
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->orderBy('id')
            ->paginate($perPage)
            ->withQueryString();

        $grades = $this->calculator->forOffering($offering, $page->getCollection()->modelKeys());

        return $page->through(fn (Candidate $candidate): array => [
            'candidate' => $this->candidate($candidate),
            'result' => $grades[$candidate->id]->toArray(),
        ]);
    }

    /**
     * Every candidate who can be graded, plus anyone else who already has a
     * recorded score (for example after moving to another class), read-only.
     *
     * @return list<array{candidate: array{id: int, candidateNumber: string, name: string, status: array{value: string, label: string, tone: string}}, score: string|null, comment: string|null, percentage: float|null, gradable: bool}>
     */
    public function scoreSheet(Assessment $assessment): array
    {
        $classBatchId = $assessment->classSubject->class_batch_id;
        $maxScore = (float) $assessment->max_score;

        $scores = $assessment->scores()->get(['id', 'candidate_id', 'score', 'comment'])->keyBy('candidate_id');

        return Candidate::query()
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $gradable) => $gradable->gradableIn($classBatchId))
                ->orWhereIn('id', $scores->keys()->all()))
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->orderBy('id')
            ->get()
            ->map(function (Candidate $candidate) use ($scores, $classBatchId, $maxScore): array {
                /** @var AssessmentScore|null $score */
                $score = $scores->get($candidate->id);
                $value = $score?->score;

                return [
                    'candidate' => $this->candidate($candidate),
                    'score' => $value === null ? null : DecimalValue::display($value),
                    'comment' => $score?->comment,
                    'percentage' => $value === null ? null : $this->calculator->scorePercentage((float) $value, $maxScore),
                    'gradable' => $candidate->isGradableIn($classBatchId),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Changes made after each candidate's first recorded value, newest first.
     *
     * @return array{entries: list<array<string, mixed>>, total: int}
     */
    public function history(Assessment $assessment, int $limit): array
    {
        $changes = AssessmentScoreRevision::query()
            ->whereIn('kind', [ScoreRevisionKind::Updated->value, ScoreRevisionKind::Corrected->value])
            ->whereHas('assessmentScore', fn (Builder $scores) => $scores->where('assessment_id', $assessment->id));

        $total = (clone $changes)->count();

        $entries = $changes
            ->with(['assessmentScore.candidate', 'changer:id,name'])
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (AssessmentScoreRevision $revision): array => [
                'id' => $revision->id,
                'kind' => ['value' => $revision->kind->value, 'label' => $revision->kind->label()],
                'candidate' => [
                    'candidateNumber' => $revision->assessmentScore->candidate->candidate_number,
                    'name' => $revision->assessmentScore->candidate->full_name,
                ],
                'previousScore' => $revision->previous_score === null ? null : DecimalValue::display($revision->previous_score),
                'newScore' => $revision->new_score === null ? null : DecimalValue::display($revision->new_score),
                'comment' => $revision->comment,
                'reason' => $revision->reason,
                'changedBy' => $revision->changer->name,
                'changedAt' => $revision->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        return ['entries' => $entries, 'total' => $total];
    }

    /**
     * @return array{id: int, candidateNumber: string, name: string, status: array{value: string, label: string, tone: string}}
     */
    private function candidate(Candidate $candidate): array
    {
        return [
            'id' => $candidate->id,
            'candidateNumber' => $candidate->candidate_number,
            'name' => $candidate->full_name,
            'status' => self::status($candidate->status),
        ];
    }

    /**
     * @return array{value: string, label: string, tone: string}
     */
    private static function status(CandidateStatus $status): array
    {
        return ['value' => $status->value, 'label' => $status->label(), 'tone' => $status->tone()];
    }
}
