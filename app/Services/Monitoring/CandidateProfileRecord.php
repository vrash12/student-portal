<?php

namespace App\Services\Monitoring;

use App\Models\Assessment;
use App\Models\Candidate;
use App\Models\ClassBatch;
use App\Models\ClassSubject;
use App\Models\ExaminationAttempt;
use App\Services\Grading\GradeCalculationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/** Own-record presentation; authorization and staff offering scope come from the caller. */
final class CandidateProfileRecord
{
    public function __construct(private readonly GradeCalculationService $grades) {}

    public function academics(Candidate $candidate): array
    {
        $offerings = ClassSubject::query()->where('class_batch_id', $candidate->class_batch_id ?? 0)
            ->with(['subject', 'instructors:id,name'])->get()->sortBy('subject.name')->values();
        $grades = $this->grades->forCandidate($candidate, $offerings->modelKeys());

        return [
            'overall' => $this->grades->overallStanding($grades)->toArray(),
            'subjects' => $offerings->map(fn (ClassSubject $offering): array => [
                'id' => $offering->id,
                'code' => $offering->subject->code,
                'name' => $offering->subject->name,
                'instructors' => $offering->instructors->pluck('name')->sort()->values()->all(),
                'result' => $grades[$offering->id]->toArray(),
            ])->all(),
        ];
    }

    /** Finalized current assessments and the candidate's recorded historical results. */
    public function assessmentHistory(Candidate $candidate, int $perPage = 15, ?int $page = null): LengthAwarePaginator
    {
        return Assessment::query()->finalized()
            ->where(function (Builder $query) use ($candidate): void {
                $query->whereHas('scores', fn (Builder $scores) => $scores->where('candidate_id', $candidate->id));
                if ($candidate->class_batch_id !== null && $candidate->isGradableIn($candidate->class_batch_id)) {
                    $query->orWhereHas('classSubject', fn (Builder $offerings) => $offerings->where('class_batch_id', $candidate->class_batch_id));
                }
            })
            ->with([
                'classSubject.subject:id,code,name', 'classSubject.classBatch:id,name,academic_period_id',
                'classSubject.classBatch.academicPeriod:id,name,starts_on,ends_on', 'category:id,name',
                'scores' => fn ($query) => $query->where('candidate_id', $candidate->id)->select(['id', 'assessment_id', 'score']),
            ])
            ->orderByDesc('finalized_at')->orderByDesc('id')
            ->paginate($perPage, ['*'], 'assessments_page', $page)->withQueryString()
            ->through(function (Assessment $assessment): array {
                $score = $assessment->scores->first()?->score;

                return [
                    'id' => $assessment->id,
                    'title' => $assessment->title,
                    'subject' => $assessment->classSubject->subject->name,
                    'className' => $assessment->classSubject->classBatch->name,
                    'period' => self::period($assessment->classSubject->classBatch),
                    'subjectCode' => $assessment->classSubject->subject->code,
                    'category' => $assessment->category->name,
                    'date' => $assessment->assessed_on?->toDateString()
                        ?? $assessment->finalized_at?->copy()->setTimezone(config('institution.timezone'))->toDateString(),
                    'score' => $score,
                    'maxScore' => $assessment->max_score,
                    'percentage' => $score === null ? null : $this->grades->scorePercentage((float) $score, (float) $assessment->max_score),
                ];
            });
    }

    /** @param list<int>|null $offeringIds Null for the owner or an administrator; restricted IDs for instructors. */
    public function examinationResults(Candidate $candidate, bool $portal, ?array $offeringIds = null, int $perPage = 15, ?int $page = null): LengthAwarePaginator
    {
        return ExaminationAttempt::query()->where('candidate_id', $candidate->id)
            ->when($offeringIds !== null, fn (Builder $query) => $query->whereHas('examination', fn (Builder $exams) => $exams->whereIn('class_subject_id', $offeringIds)))
            ->select(['id', 'examination_id', 'attempt_number', 'status', 'result_status', 'started_at', 'submitted_at', 'earned_points', 'total_points', 'percentage', 'passed'])
            ->with(['examination:id,class_subject_id,title,kind,release_results', 'examination.classSubject.subject:id,code,name', 'examination.classSubject.classBatch:id,name,academic_period_id', 'examination.classSubject.classBatch.academicPeriod:id,name,starts_on,ends_on'])
            ->orderByDesc('id')->paginate($perPage, ['*'], 'exams_page', $page)->withQueryString()
            ->through(function (ExaminationAttempt $attempt) use ($portal): array {
                $released = ! $portal || $attempt->examination->release_results;
                $graded = $released && $attempt->result_status === 'graded' && $attempt->status === 'submitted';

                return [
                    'id' => $attempt->id,
                    'title' => $attempt->examination->title,
                    'kind' => $attempt->examination->kind->label(),
                    'subject' => $attempt->examination->classSubject->subject->name,
                    'className' => $attempt->examination->classSubject->classBatch->name,
                    'period' => self::period($attempt->examination->classSubject->classBatch),
                    'subjectCode' => $attempt->examination->classSubject->subject->code,
                    'attemptNumber' => $attempt->attempt_number,
                    'status' => str_replace('_', ' ', ucfirst($attempt->status)),
                    'submittedAt' => $attempt->submitted_at?->toIso8601String(),
                    'resultLabel' => match ($attempt->status) {
                        'in_progress' => 'In progress',
                        'submitted' => ! $released ? 'Not released' : ($graded ? 'Graded' : 'Awaiting review'),
                        default => 'No submitted result',
                    },
                    'score' => $graded ? $attempt->earned_points : null,
                    'maxScore' => $graded ? $attempt->total_points : null,
                    'percentage' => $graded && $attempt->percentage !== null ? (float) $attempt->percentage : null,
                    'passed' => $graded ? $attempt->passed : null,
                ];
            });
    }

    /**
     * The academic period (semester) a record belongs to, through its class.
     *
     * @return array{id: int, name: string, startsOn: ?string, endsOn: ?string}
     */
    private static function period(ClassBatch $class): array
    {
        $period = $class->academicPeriod;

        return ['id' => $period->id, 'name' => $period->name, 'startsOn' => $period->starts_on?->toDateString(), 'endsOn' => $period->ends_on?->toDateString()];
    }
}
