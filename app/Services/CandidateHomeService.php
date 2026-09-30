<?php

namespace App\Services;

use App\Enums\ExaminationStatus;
use App\Models\Assessment;
use App\Models\Candidate;
use App\Models\Examination;
use App\Models\ExaminationAttempt;
use App\Services\Monitoring\CandidateProfileRecord;
use Illuminate\Database\Eloquent\Builder;

final class CandidateHomeService
{
    public function overview(Candidate $candidate): array
    {
        $candidate->loadMissing('classBatch.academicPeriod');
        $eligible = $candidate->class_batch_id !== null && $candidate->isGradableIn($candidate->class_batch_id);
        $now = now();
        $exams = Examination::query()->select(['id', 'class_subject_id', 'title', 'kind', 'duration_minutes', 'opens_at', 'closes_at', 'attempt_limit'])
            ->where('status', ExaminationStatus::Published)
            ->whereHas('classSubject', fn (Builder $query) => $query->where('class_batch_id', $eligible ? $candidate->class_batch_id : 0))
            ->where(fn (Builder $query) => $query->whereNull('closes_at')->orWhere('closes_at', '>', $now))
            ->with('classSubject.subject:id,name')
            ->withCount(['attempts as attempts_used' => fn (Builder $query) => $query->where('candidate_id', $candidate->id)])
            ->withMax(['attempts as resume_id' => fn (Builder $query) => $query->where('candidate_id', $candidate->id)->where('status', 'in_progress')->where('expires_at', '>', $now)], 'id');
        $available = (clone $exams)->where(fn (Builder $query) => $query->whereNull('opens_at')->orWhere('opens_at', '<=', $now))
            ->orderByRaw('closes_at is null')->orderBy('closes_at')->orderBy('id')
            ->paginate(8, ['*'], 'available_page')->withQueryString()->through($this->exam(...));
        $upcoming = (clone $exams)->where('opens_at', '>', $now)->orderBy('opens_at')->orderBy('id')
            ->paginate(6, ['*'], 'upcoming_page')->withQueryString()->through($this->exam(...));
        $outstanding = Assessment::query()->finalized()
            ->whereHas('classSubject', fn (Builder $query) => $query->where('class_batch_id', $eligible ? $candidate->class_batch_id : 0))
            ->whereDoesntHave('scores', fn (Builder $query) => $query->where('candidate_id', $candidate->id)->whereNotNull('score'))
            ->with(['classSubject.subject:id,name', 'category:id,name'])
            ->orderByDesc('finalized_at')->orderByDesc('id')->paginate(8, ['*'], 'outstanding_page')->withQueryString()
            ->through(fn (Assessment $assessment): array => [
                'id' => $assessment->id, 'title' => $assessment->title,
                'subject' => $assessment->classSubject->subject->name, 'category' => $assessment->category->name,
                'date' => $assessment->assessed_on?->toDateString(),
            ]);
        $recent = ExaminationAttempt::query()->where('candidate_id', $candidate->id)
            ->where('status', 'submitted')->where('result_status', 'graded')
            ->whereHas('examination', fn (Builder $query) => $query->where('release_results', true))
            ->select(['id', 'examination_id', 'attempt_number', 'submitted_at', 'percentage', 'passed'])
            ->with(['examination:id,class_subject_id,title,kind', 'examination.classSubject.subject:id,name'])
            ->orderByDesc('submitted_at')->orderByDesc('id')->limit(6)->get()
            ->map(fn (ExaminationAttempt $attempt): array => [
                'id' => $attempt->id, 'title' => $attempt->examination->title,
                'subject' => $attempt->examination->classSubject->subject->name,
                'kind' => $attempt->examination->kind->label(), 'attemptNumber' => $attempt->attempt_number,
                'submittedAt' => $attempt->submitted_at?->toIso8601String(),
                'percentage' => $attempt->percentage === null ? null : (float) $attempt->percentage, 'passed' => $attempt->passed,
            ])->all();
        $academics = app(CandidateProfileRecord::class)->academics($candidate);

        return [
            'summary' => ['name' => $candidate->full_name, 'number' => $candidate->candidate_number,
                'className' => $candidate->classBatch?->name, 'period' => $candidate->classBatch?->academicPeriod->name,
                'subjectCount' => count($academics['subjects']), 'overall' => $academics['overall'], 'eligible' => $eligible],
            'available' => $available, 'upcoming' => $upcoming, 'outstanding' => $outstanding, 'recentResults' => $recent,
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
