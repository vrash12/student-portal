<?php

namespace App\Services;

use App\Enums\ExaminationStatus;
use App\Models\Assessment;
use App\Models\Candidate;
use App\Models\Examination;
use App\Models\ExaminationAttempt;
use App\Services\Fitness\FitnessResults;
use App\Services\Grading\GradingThresholds;
use App\Services\Monitoring\CandidateProfileRecord;
use App\Services\Performance\QualificationEngine;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The candidate portal's pages, from the signed-in candidate's own records
 * only: Home (what to do now and one figure per section), Examinations
 * (open, scheduled and own results) and Grades (standing, subjects,
 * outstanding work and history). Rules come from the grade, examination,
 * fitness and qualification engines; nothing is calculated here.
 */
final class CandidateHomeService
{
    /** Open examinations shown on Home; the Examinations page lists them all. */
    private const HOME_OPEN = 4;

    private const HOME_UPCOMING = 3;

    private const PER_PAGE = 10;

    /** Released results drawn in the Examinations score chart, latest last. */
    private const TREND_RESULTS = 10;

    public function __construct(
        private readonly QualificationEngine $qualification,
        private readonly CandidateProfileRecord $records,
        private readonly FitnessResults $fitness,
    ) {}

    public function overview(Candidate $candidate): array
    {
        $academics = $this->records->academics($candidate);
        $available = $this->availableExams($candidate, self::HOME_OPEN);
        $upcoming = $this->upcomingExams($candidate, self::HOME_UPCOMING);

        return [
            'summary' => $this->summary($candidate, $academics),
            'available' => $available,
            'upcoming' => $upcoming,
            'performance' => $this->performance($candidate),
            // One figure per portal section, for the Home tiles.
            'sections' => [
                'grades' => ['overall' => $academics['overall'], 'subjectCount' => count($academics['subjects']), 'outstandingCount' => $this->outstanding($candidate)->total()],
                'examinations' => ['openCount' => $available->total(), 'upcomingCount' => $upcoming->total(), 'releasedCount' => $this->releasedResults($candidate)->count()],
                // Null as well when the portal does not show fitness (staff only by default).
                'fitness' => config('institution.portal.show_fitness') ? $this->latestFitness($candidate) : null,
            ],
        ];
    }

    public function examinations(Candidate $candidate): array
    {
        return [
            'summary' => $this->summary($candidate),
            'available' => $this->availableExams($candidate, self::PER_PAGE),
            'upcoming' => $this->upcomingExams($candidate, self::PER_PAGE),
            'results' => $this->records->examinationResults($candidate, true),
            // Released, graded scores, oldest first, for the chart.
            'scoreTrend' => $this->releasedResults($candidate)->take(self::TREND_RESULTS)->reverse()->values()->all(),
        ];
    }

    public function grades(Candidate $candidate): array
    {
        $academics = $this->records->academics($candidate);
        $period = $candidate->classBatch?->academicPeriod;

        return [
            'summary' => $this->summary($candidate, $academics),
            'academics' => $academics,
            'thresholds' => $period === null ? null : GradingThresholds::forPeriod($period)?->toArray(),
            'outstanding' => $this->outstanding($candidate),
            'assessmentHistory' => $this->records->assessmentHistory($candidate),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $academics
     * @return array<string, mixed>
     */
    private function summary(Candidate $candidate, ?array $academics = null): array
    {
        $candidate->loadMissing('classBatch.academicPeriod');

        return [
            'name' => $candidate->full_name, 'number' => $candidate->candidate_number,
            'className' => $candidate->classBatch?->name, 'period' => $candidate->classBatch?->academicPeriod->name,
            'eligible' => $this->eligible($candidate),
            ...($academics === null ? [] : ['subjectCount' => count($academics['subjects']), 'overall' => $academics['overall']]),
        ];
    }

    private function eligible(Candidate $candidate): bool
    {
        return $candidate->class_batch_id !== null && $candidate->isGradableIn($candidate->class_batch_id);
    }

    /** Published examinations of the candidate's class that have not closed. */
    private function exams(Candidate $candidate): Builder
    {
        $now = now();

        return Examination::query()->select(['id', 'class_subject_id', 'title', 'kind', 'duration_minutes', 'opens_at', 'closes_at', 'attempt_limit'])
            ->where('status', ExaminationStatus::Published)
            ->whereHas('classSubject', fn (Builder $query) => $query->where('class_batch_id', $this->eligible($candidate) ? $candidate->class_batch_id : 0))
            ->where(fn (Builder $query) => $query->whereNull('closes_at')->orWhere('closes_at', '>', $now))
            ->with('classSubject.subject:id,name')
            ->withCount(['attempts as attempts_used' => fn (Builder $query) => $query->where('candidate_id', $candidate->id)])
            ->withMax(['attempts as resume_id' => fn (Builder $query) => $query->where('candidate_id', $candidate->id)->where('status', 'in_progress')->where('expires_at', '>', $now)], 'id');
    }

    private function availableExams(Candidate $candidate, int $perPage): LengthAwarePaginator
    {
        return $this->exams($candidate)->where(fn (Builder $query) => $query->whereNull('opens_at')->orWhere('opens_at', '<=', now()))
            ->orderByRaw('closes_at is null')->orderBy('closes_at')->orderBy('id')
            ->paginate($perPage, ['*'], 'available_page')->withQueryString()->through($this->exam(...));
    }

    private function upcomingExams(Candidate $candidate, int $perPage): LengthAwarePaginator
    {
        return $this->exams($candidate)->where('opens_at', '>', now())->orderBy('opens_at')->orderBy('id')
            ->paginate($perPage, ['*'], 'upcoming_page')->withQueryString()->through($this->exam(...));
    }

    /** Finalized assessments of the candidate's class with no recorded score. */
    private function outstanding(Candidate $candidate): LengthAwarePaginator
    {
        return Assessment::query()->finalized()
            ->whereHas('classSubject', fn (Builder $query) => $query->where('class_batch_id', $this->eligible($candidate) ? $candidate->class_batch_id : 0))
            ->whereDoesntHave('scores', fn (Builder $query) => $query->where('candidate_id', $candidate->id)->whereNotNull('score'))
            ->with(['classSubject.subject:id,name', 'category:id,name'])
            ->orderByDesc('finalized_at')->orderByDesc('id')->paginate(self::PER_PAGE, ['*'], 'outstanding_page')->withQueryString()
            ->through(fn (Assessment $assessment): array => [
                'id' => $assessment->id, 'title' => $assessment->title,
                'subject' => $assessment->classSubject->subject->name, 'category' => $assessment->category->name,
                'date' => $assessment->assessed_on?->toDateString(),
            ]);
    }

    /**
     * Graded submissions whose results the instructor released, newest first.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function releasedResults(Candidate $candidate): Collection
    {
        return ExaminationAttempt::query()->where('candidate_id', $candidate->id)
            ->where('status', 'submitted')->where('result_status', 'graded')
            ->whereHas('examination', fn (Builder $query) => $query->where('release_results', true))
            ->select(['id', 'examination_id', 'attempt_number', 'submitted_at', 'percentage', 'passed'])
            ->with(['examination:id,class_subject_id,title,kind', 'examination.classSubject.subject:id,name'])
            ->orderByDesc('submitted_at')->orderByDesc('id')->get()
            ->map(fn (ExaminationAttempt $attempt): array => [
                'id' => $attempt->id, 'title' => $attempt->examination->title,
                'subject' => $attempt->examination->classSubject->subject->name,
                'kind' => $attempt->examination->kind->label(), 'attemptNumber' => $attempt->attempt_number,
                'submittedAt' => $attempt->submitted_at?->toIso8601String(),
                'percentage' => $attempt->percentage === null ? null : (float) $attempt->percentage, 'passed' => $attempt->passed,
            ]);
    }

    /**
     * The candidate's latest fitness test, for the Home tile; null when none.
     *
     * @return array{title: string, testedOn: string, status: array<string, string>, points: ?float}|null
     */
    private function latestFitness(Candidate $candidate): ?array
    {
        $latest = $this->fitness->history($candidate, 1)[0] ?? null;

        return $latest === null ? null : [
            'title' => $latest['title'],
            'testedOn' => $latest['testedOn'],
            'status' => $latest['outcome']['status'],
            'points' => $latest['outcome']['points'],
        ];
    }

    /**
     * The qualification status for the "My Performance" card, or null
     * without a class. Never the class rank (staff only).
     *
     * @return array{configured: bool, status: array{value: string, label: string, tone: string}, reasons: list<string>, pending: list<string>}|null
     */
    private function performance(Candidate $candidate): ?array
    {
        $qualification = $this->qualification->forCandidate($candidate, withRank: false);
        if ($qualification === null) {
            return null;
        }

        return [
            // Without active areas the status is Pending until administrators configure them.
            'configured' => $qualification->areas !== [],
            ...$qualification->qualification->toArray(),
        ];
    }

    private function exam(Examination $exam): array
    {
        return [
            'id' => $exam->id, 'title' => $exam->title, 'kind' => $exam->kind->label(),
            'subject' => $exam->classSubject->subject->name, 'durationMinutes' => $exam->duration_minutes,
            'opensAt' => $exam->opens_at?->toIso8601String(), 'closesAt' => $exam->closes_at?->toIso8601String(),
            'attemptsUsed' => (int) $exam->attempts_used, 'attemptLimit' => $exam->attempt_limit,
            'resumeId' => $exam->resume_id === null ? null : (int) $exam->resume_id,
        ];
    }
}
