<?php

namespace App\Services;

use App\Enums\AssessmentStatus;
use App\Models\AcademicPeriod;
use App\Models\Assessment;
use App\Models\Candidate;
use App\Models\ClassSubject;
use App\Models\Examination;
use App\Models\InstructorAssignment;
use App\Services\Monitoring\AcademicMonitoring;
use App\Services\Monitoring\SubjectPerformance;
use Illuminate\Support\Collection;

final class AdministratorDashboardService
{
    public function __construct(private readonly AcademicMonitoring $monitoring, private readonly SubjectPerformance $performance) {}

    /** @return array<string, mixed> */
    public function overview(): array
    {
        $period = AcademicPeriod::query()->active()->first();
        if ($period === null) {
            return ['period' => null, 'totalCandidates' => 0, 'recentExaminations' => [], 'recentActivity' => [], 'subjectPerformance' => [], 'instructors' => []];
        }
        $periodId = $period->id;

        return [
            'period' => ['id' => $period->id, 'name' => $period->name],
            'totalCandidates' => Candidate::whereHas('classBatch', fn ($query) => $query->where('academic_period_id', $periodId))->where('status', '!=', 'withdrawn')->count(),
            'recentExaminations' => Examination::with('classSubject.subject:id,name', 'classSubject.classBatch:id,name')->withCount(['attempts as submitted_count' => fn ($query) => $query->where('status', 'submitted')])->whereHas('classSubject.classBatch', fn ($query) => $query->where('academic_period_id', $periodId))->latest()->limit(5)->get()->map(fn (Examination $exam): array => ['id' => $exam->id, 'title' => $exam->title, 'subject' => $exam->classSubject->subject->name, 'classBatch' => $exam->classSubject->classBatch->name, 'status' => $exam->lifecycle(), 'submittedCount' => (int) $exam->submitted_count])->all(),
            'recentActivity' => Assessment::with('classSubject.subject:id,name', 'classSubject.classBatch:id,name')->withCount(['scores as scored_count' => fn ($query) => $query->whereNotNull('score')])->where('status', AssessmentStatus::Finalized)->whereHas('classSubject.classBatch', fn ($query) => $query->where('academic_period_id', $periodId))->latest('finalized_at')->limit(8)->get()->map(fn (Assessment $assessment): array => ['id' => $assessment->id, 'title' => $assessment->title, 'subject' => $assessment->classSubject->subject->name, 'classBatch' => $assessment->classSubject->classBatch->name, 'finalizedAt' => $assessment->finalized_at?->toIso8601String(), 'scoredCount' => (int) $assessment->scored_count])->all(),
            'subjectPerformance' => $this->subjectPerformance($periodId),
            'instructors' => $this->instructors($periodId),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function subjectPerformance(int $periodId): array
    {
        $offerings = ClassSubject::whereHas('classBatch', fn ($query) => $query->where('academic_period_id', $periodId))->get();

        return array_slice($this->performance->summarize($this->monitoring->evaluate($offerings)), 0, 12);
    }

    /** @return list<array<string, mixed>> */
    private function instructors(int $periodId): array
    {
        return InstructorAssignment::with('instructor:id,name', 'classSubject.classBatch:id,name', 'classSubject.subject:id,name')->whereHas('classSubject.classBatch', fn ($query) => $query->where('academic_period_id', $periodId))->get()->groupBy('instructor_id')->map(function (Collection $assignments): array {
            $first = $assignments->first();

            return ['id' => $first->instructor->id, 'name' => $first->instructor->name, 'classes' => $assignments->pluck('classSubject.classBatch.name')->unique()->sort()->values()->all(), 'subjects' => $assignments->pluck('classSubject.subject.name')->unique()->sort()->values()->all()];
        })->sortBy('name')->values()->all();
    }
}
