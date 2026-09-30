<?php

namespace App\Services\Examinations;

use App\Enums\AuditAction;
use App\Enums\ExaminationStatus;
use App\Models\Assessment;
use App\Models\Candidate;
use App\Models\ClassSubject;
use App\Models\Examination;
use App\Models\ExaminationAttempt;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Grading\AssessmentService;
use App\Services\Grading\ScoreRecordingService;
use App\Support\DecimalValue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class ExaminationGradebookService
{
    public function authorize(User $actor, Examination $exam): void
    {
        Gate::forUser($actor)->authorize('view', $exam);
        Gate::forUser($actor)->authorize('recordGrades', $exam->classSubject);
    }

    /** Metadata and scores only; no answer content or scoring keys. */
    public function preview(User $actor, Examination $exam, string $rule, bool $lock = false): array
    {
        $this->authorize($actor, $exam);
        abort_unless(in_array($rule, ['highest', 'latest'], true), 422);
        $exam->loadMissing(['classSubject.subject', 'classSubject.classBatch']);
        $candidates = Candidate::query()->gradableIn($exam->classSubject->class_batch_id)
            ->orderBy('id')->when($lock, fn ($query) => $query->lockForUpdate())->get();
        $attempts = ExaminationAttempt::query()->where('examination_id', $exam->id)->orderBy('id')
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->get(['id', 'candidate_id', 'attempt_number', 'status', 'result_status', 'earned_points', 'total_points', 'percentage', 'updated_at']);
        $categories = $exam->classSubject->assessmentCategories()->orderBy('position')->get(['id', 'name', 'weight']);
        $posted = Assessment::where('source_examination_id', $exam->id)->first(['id', 'title']);
        $maximum = DecimalValue::normalize($exam->examinationQuestions()->sum('points'));
        $byCandidate = $attempts->groupBy('candidate_id');
        $rows = $candidates->map(function (Candidate $candidate) use ($byCandidate, $rule): array {
            $submitted = ($byCandidate->get($candidate->id) ?? collect())->where('status', 'submitted');
            // Latest means latest submitted attempt, not latest completed grading.
            $selected = $rule === 'highest'
                ? $submitted->sortByDesc(fn ($attempt) => [(float) $attempt->earned_points, $attempt->attempt_number])->first()
                : $submitted->sortByDesc('attempt_number')->first();
            $ready = $selected?->result_status === 'graded' && $selected?->earned_points !== null;

            return [
                'candidateId' => $candidate->id, 'candidateNumber' => $candidate->candidate_number,
                'name' => $candidate->full_name, 'attemptId' => $selected?->id, 'attemptNumber' => $selected?->attempt_number,
                'score' => $ready ? $selected->earned_points : null,
                'maximum' => $ready ? $selected->total_points : null,
                'status' => $selected === null ? 'No submitted result' : ($ready ? 'Ready' : 'Awaiting grading'),
            ];
        })->values()->all();
        $issues = [];
        if ((float) $maximum > 9999.99) {
            $issues[] = 'This examination exceeds the gradebook maximum of 9,999.99 points.';
        }
        if ($exam->status === ExaminationStatus::Draft || ($exam->status === ExaminationStatus::Published && ($exam->closes_at === null || $exam->closes_at->isFuture()))) {
            $issues[] = 'The examination must have ended or been archived before posting.';
        }
        if (! $exam->release_results) {
            $issues[] = 'Release examination results first. Posted grades will be visible to candidates.';
        }
        if ($attempts->contains('status', 'in_progress')) {
            $issues[] = 'Wait for all attempts to finish or expire.';
        }
        if ($attempts->whereIn('candidate_id', $candidates->modelKeys())->where('status', 'submitted')->contains(fn ($attempt) => $attempt->result_status !== 'graded' || $attempt->earned_points === null)) {
            $issues[] = 'Complete grading for all submitted attempts of candidates in this class.';
        }
        if ($categories->isEmpty()) {
            $issues[] = 'Configure grading categories for this subject first.';
        }
        $ready = collect($rows)->whereNotNull('score');
        if ($ready->isEmpty()) {
            $issues[] = 'There are no graded results to post for the current class roster.';
        }
        if ((float) $maximum <= 0 || $ready->contains(fn ($row) => DecimalValue::normalize($row['maximum']) !== $maximum || (float) $row['score'] < 0 || (float) $row['score'] > (float) $maximum)) {
            $issues[] = 'Result point totals do not match this examination. Review scoring before posting.';
        }
        $state = ['exam' => $exam->id, 'rule' => $rule, 'maximum' => $maximum, 'rows' => $rows,
            'categories' => $categories->toArray(), 'attempts' => $attempts->toArray(), 'issues' => $issues];

        return [
            'exam' => ['id' => $exam->id, 'title' => $exam->title, 'subject' => $exam->classSubject->subject->name, 'className' => $exam->classSubject->classBatch->name],
            'categories' => $categories->toArray(), 'rows' => $rows, 'maximum' => $maximum, 'rule' => $rule,
            'readyCount' => $ready->count(), 'missingCount' => count($rows) - $ready->count(),
            'excludedCount' => $attempts->whereNotIn('candidate_id', $candidates->modelKeys())->pluck('candidate_id')->unique()->count(),
            'issues' => $issues, 'posted' => $posted?->toArray(),
            'reviewToken' => hash_hmac('sha256', json_encode($state, JSON_THROW_ON_ERROR), config('app.key')),
        ];
    }

    public function post(User $actor, Examination $exam, array $data): Assessment
    {
        $this->authorize($actor, $exam);

        return DB::transaction(function () use ($actor, $exam, $data): Assessment {
            // Candidate starts also lock candidate before examination. Preserve that order.
            Candidate::query()->gradableIn($exam->classSubject->class_batch_id)->orderBy('id')->lockForUpdate()->get(['id']);
            ClassSubject::whereKey($exam->class_subject_id)->lockForUpdate()->firstOrFail();
            $exam = Examination::whereKey($exam->id)->lockForUpdate()->firstOrFail();
            $this->authorize($actor, $exam);
            $existing = Assessment::where('source_examination_id', $exam->id)->first();
            if ($existing !== null) {
                return $existing; // Retries cannot create another gradebook contribution.
            }
            $preview = $this->preview($actor, $exam, $data['attempt_rule'], true);
            if ($preview['issues'] !== []) {
                throw ValidationException::withMessages(['posting' => implode(' ', $preview['issues'])]);
            }
            if (! hash_equals($preview['reviewToken'], $data['review_token'])) {
                throw ValidationException::withMessages(['posting' => 'Results, roster or grading settings changed. Reload this preview and review again.']);
            }
            $assessment = app(AssessmentService::class)->create($exam->classSubject, [
                'title' => $data['title'], 'assessment_category_id' => (int) $data['assessment_category_id'],
                'max_score' => $preview['maximum'], 'assessed_on' => now()->timezone(config('institution.timezone'))->toDateString(),
            ], $actor);
            $assessment->source_examination_id = $exam->id;
            $assessment->exam_attempt_rule = $data['attempt_rule'];
            $assessment->save();
            $entries = [];
            foreach ($preview['rows'] as $row) {
                if ($row['score'] !== null) {
                    $entries[$row['candidateId']] = ['score' => $row['score'], 'comment' => null, 'expected_score' => null, 'expected_comment' => null];
                }
            }
            app(ScoreRecordingService::class)->recordDraftScores($assessment, $entries, $actor);
            foreach ($preview['rows'] as $row) {
                if ($row['score'] !== null) {
                    $assessment->scores()->where('candidate_id', $row['candidateId'])->update(['source_examination_attempt_id' => $row['attemptId']]);
                }
            }
            app(AssessmentService::class)->finalize($assessment, $actor);
            app(AuditLogger::class)->record(AuditAction::ExaminationPosted, $exam, newValues: [
                'assessment_id' => $assessment->id, 'category_id' => (int) $data['assessment_category_id'],
                'attempt_rule' => $data['attempt_rule'], 'scores_posted' => count($entries), 'missing_scores' => $preview['missingCount'],
            ], actor: $actor);

            return $assessment;
        });
    }
}
