<?php

namespace App\Services\Monitoring;

use App\Enums\ScoreRevisionKind;
use App\Models\Assessment;
use App\Models\AssessmentScore;
use App\Models\AssessmentScoreRevision;
use App\Models\Candidate;
use App\Models\ClassSubject;
use App\Services\Grading\GradeCalculationService;
use App\Services\Grading\SubjectGrade;
use App\Support\DecimalValue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * The candidate profile's academic details beyond subject grades: current
 * warnings, assessment results, and recent academic activity, always limited
 * to the class subjects the viewer may see. Only finalized assessments are
 * shown (drafts are work in progress), and never score comments.
 */
final class CandidateAcademicRecord
{
    private const RECENT_ACTIVITY_LIMIT = 8;

    public function __construct(private readonly GradeCalculationService $calculator) {}

    /**
     * One entry per subject that needs attention, most serious first.
     *
     * @param  list<array{offering: ClassSubject, grade: SubjectGrade}>  $subjects
     * @return list<array<string, mixed>>
     */
    public function warnings(array $subjects): array
    {
        return array_map(SubjectConcerns::present(...), SubjectConcerns::of($subjects));
    }

    /**
     * The candidate's result on every finalized assessment of the given class
     * subjects, grouped by subject, newest first. For a candidate who can no
     * longer be graded in the class (withdrawn), only assessments with a
     * recorded result are listed, so later assessments do not show as missing.
     *
     * @param  Collection<int, ClassSubject>  $offerings  with subject loaded
     * @return list<array{classSubjectId: int, subject: array{code: string, name: string}, assessments: list<array<string, mixed>>}>
     */
    public function assessmentResults(Candidate $candidate, Collection $offerings, bool $monitored): array
    {
        if ($offerings->isEmpty()) {
            return [];
        }

        // Newest first by the date shown, which for undated assessments is the
        // finalization day in the institution's timezone (not the UTC day).
        $assessments = $this->finalizedAssessments($offerings->modelKeys())->get()
            ->sortByDesc(fn (Assessment $assessment): array => [
                $this->displayDate($assessment) ?? '',
                $assessment->finalized_at?->getTimestamp() ?? 0,
                $assessment->id,
            ])
            ->values();
        $scores = $this->scoresOf($candidate, $assessments);

        return $offerings
            ->map(function (ClassSubject $offering) use ($assessments, $scores, $monitored): array {
                $rows = $assessments
                    ->where('class_subject_id', $offering->id)
                    ->filter(fn (Assessment $assessment): bool => $monitored || $scores->has($assessment->id))
                    ->map(fn (Assessment $assessment): array => $this->result($assessment, $scores->get($assessment->id)))
                    ->values()
                    ->all();

                return [
                    'classSubjectId' => $offering->id,
                    'subject' => ['code' => $offering->subject->code, 'name' => $offering->subject->name],
                    'assessments' => $rows,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * The latest finalized results and corrections of finalized scores in
     * the given class subjects, newest first.
     *
     * @param  list<int>  $classSubjectIds
     * @return list<array<string, mixed>>
     */
    public function recentActivity(Candidate $candidate, array $classSubjectIds, bool $monitored): array
    {
        if ($classSubjectIds === []) {
            return [];
        }

        $finalized = $this->finalizedAssessments($classSubjectIds)
            ->reorder()
            ->orderByDesc('finalized_at')
            ->orderByDesc('id')
            ->limit(self::RECENT_ACTIVITY_LIMIT)
            ->get();
        $scores = $this->scoresOf($candidate, $finalized);

        $results = $finalized
            ->filter(fn (Assessment $assessment): bool => $monitored || $scores->has($assessment->id))
            ->map(fn (Assessment $assessment): array => [
                'type' => 'finalized',
                'id' => "finalized-{$assessment->id}",
                'at' => $assessment->finalized_at?->toIso8601String(),
                ...$this->result($assessment, $scores->get($assessment->id)),
            ]);

        $corrections = AssessmentScoreRevision::query()
            ->where('kind', ScoreRevisionKind::Corrected->value)
            ->whereHas('assessmentScore', fn (Builder $scores) => $scores
                ->where('candidate_id', $candidate->id)
                ->whereHas('assessment', fn (Builder $assessments) => $assessments->whereIn('class_subject_id', $classSubjectIds)))
            ->with(['assessmentScore.assessment.classSubject.subject:id,code,name', 'changer:id,name'])
            ->latest('id')
            ->limit(self::RECENT_ACTIVITY_LIMIT)
            ->get()
            ->map(fn (AssessmentScoreRevision $revision): array => [
                'type' => 'corrected',
                'id' => "corrected-{$revision->id}",
                'at' => $revision->created_at?->toIso8601String(),
                'assessment' => ['id' => $revision->assessmentScore->assessment->id, 'title' => $revision->assessmentScore->assessment->title],
                'subject' => $revision->assessmentScore->assessment->classSubject->subject->name,
                'previousScore' => $revision->previous_score === null ? null : DecimalValue::display($revision->previous_score),
                'newScore' => $revision->new_score === null ? null : DecimalValue::display($revision->new_score),
                'maxScore' => DecimalValue::display($revision->assessmentScore->assessment->max_score),
                'reason' => $revision->reason,
                'changedBy' => $revision->changer->name,
            ]);

        // Newest first. Timestamps have one-second precision, so ties put
        // corrections first (a correction always follows its finalization).
        $entries = $results->concat($corrections)->values()->all();
        usort($entries, fn (array $a, array $b): int => [$b['at'] ?? '', $b['type'] === 'corrected'] <=> [$a['at'] ?? '', $a['type'] === 'corrected']);

        return array_slice($entries, 0, self::RECENT_ACTIVITY_LIMIT);
    }

    /**
     * Finalized assessments, newest first: by the assessment date, or the
     * finalization date for undated ones (portable across MySQL and MariaDB).
     *
     * @param  list<int>  $classSubjectIds
     * @return Builder<Assessment>
     */
    private function finalizedAssessments(array $classSubjectIds): Builder
    {
        return Assessment::query()
            ->finalized()
            ->whereIn('class_subject_id', $classSubjectIds)
            ->with(['classSubject.subject:id,code,name', 'category:id,name'])
            ->orderByRaw('coalesce(`assessed_on`, date(`finalized_at`)) desc')
            ->orderByDesc('finalized_at')
            ->orderByDesc('id');
    }

    /**
     * The assessment date, or for undated assessments the finalization day in
     * the institution's timezone.
     */
    private function displayDate(Assessment $assessment): ?string
    {
        return $assessment->assessed_on?->toDateString()
            ?? $assessment->finalized_at?->copy()->setTimezone((string) config('institution.timezone'))->toDateString();
    }

    /**
     * @param  Collection<int, Assessment>  $assessments
     * @return Collection<int, AssessmentScore> keyed by assessment id
     */
    private function scoresOf(Candidate $candidate, Collection $assessments): Collection
    {
        if ($assessments->isEmpty()) {
            return new Collection;
        }

        return AssessmentScore::query()
            ->where('candidate_id', $candidate->id)
            ->whereIn('assessment_id', $assessments->modelKeys())
            ->get(['id', 'assessment_id', 'score'])
            ->keyBy('assessment_id');
    }

    /**
     * @return array{assessment: array{id: int, title: string}, subject: string, category: string, date: string|null, maxScore: string, score: string|null, percentage: float|null}
     */
    private function result(Assessment $assessment, ?AssessmentScore $score): array
    {
        $value = $score?->score;

        return [
            'assessment' => ['id' => $assessment->id, 'title' => $assessment->title],
            'subject' => $assessment->classSubject->subject->name,
            'category' => $assessment->category->name,
            'date' => $this->displayDate($assessment),
            'maxScore' => DecimalValue::display($assessment->max_score),
            'score' => $value === null ? null : DecimalValue::display($value),
            'percentage' => $value === null ? null : $this->calculator->scorePercentage((float) $value, (float) $assessment->max_score),
        ];
    }
}
