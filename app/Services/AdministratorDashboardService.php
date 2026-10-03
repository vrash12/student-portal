<?php

namespace App\Services;

use App\Enums\AssessmentStatus;
use App\Models\AcademicPeriod;
use App\Models\Assessment;
use App\Models\Candidate;
use App\Models\ClassSubject;
use App\Models\Examination;
use App\Models\InstructorAssignment;
use App\Services\Grading\GradingThresholds;
use App\Services\Monitoring\AcademicMonitoring;
use App\Services\Monitoring\MonitoredCandidate;
use App\Services\Monitoring\SubjectPerformance;
use App\Support\CampusScope;
use App\Support\ScoreBands;
use Illuminate\Support\Collection;

final class AdministratorDashboardService
{
    public function __construct(private readonly AcademicMonitoring $monitoring, private readonly SubjectPerformance $performance) {}

    /**
     * The active period's overview for the campus scope (one campus, or every
     * campus; App\Support\CampusScope).
     *
     * @return array<string, mixed>
     */
    public function overview(?CampusScope $campus = null): array
    {
        $campus ??= CampusScope::everyCampus();
        $period = AcademicPeriod::query()->active()->first();
        if ($period === null) {
            return ['period' => null, 'totalCandidates' => 0, 'recentExaminations' => [], 'recentActivity' => [], 'subjectPerformance' => [], 'gradeDistribution' => [], 'thresholds' => null, 'instructors' => []];
        }
        $periodId = $period->id;
        // Class names, with the campus code when the overview spans several campuses.
        $classLabels = $campus->classLabels($periodId);
        $classLabel = fn (ClassSubject $offering): string => $classLabels[$offering->class_batch_id] ?? $offering->classBatch->name;
        // Grades of every class subject in the period, evaluated once by the grade engine for both charts.
        $monitored = $this->monitoring->evaluate($campus->constrain(ClassSubject::whereHas('classBatch', fn ($query) => $query->where('academic_period_id', $periodId)), 'class_subjects.campus_id')->get());

        return [
            'period' => ['id' => $period->id, 'name' => $period->name],
            'totalCandidates' => $campus->constrain(Candidate::whereHas('classBatch', fn ($query) => $query->where('academic_period_id', $periodId)), 'candidates.campus_id')->where('status', '!=', 'withdrawn')->count(),
            'recentExaminations' => $campus->constrainByOffering(Examination::query(), 'examinations.class_subject_id')->with('classSubject.subject:id,name', 'classSubject.classBatch:id,name')->withCount(['attempts as submitted_count' => fn ($query) => $query->where('status', 'submitted')])->whereHas('classSubject.classBatch', fn ($query) => $query->where('academic_period_id', $periodId))->latest()->limit(5)->get()->map(fn (Examination $exam): array => ['id' => $exam->id, 'title' => $exam->title, 'subject' => $exam->classSubject->subject->name, 'classBatch' => $classLabel($exam->classSubject), 'status' => $exam->lifecycle(), 'submittedCount' => (int) $exam->submitted_count])->all(),
            'recentActivity' => $campus->constrainByOffering(Assessment::query(), 'assessments.class_subject_id')->with('classSubject.subject:id,name', 'classSubject.classBatch:id,name')->withCount(['scores as scored_count' => fn ($query) => $query->whereNotNull('score')])->where('status', AssessmentStatus::Finalized)->whereHas('classSubject.classBatch', fn ($query) => $query->where('academic_period_id', $periodId))->latest('finalized_at')->limit(8)->get()->map(fn (Assessment $assessment): array => ['id' => $assessment->id, 'title' => $assessment->title, 'subject' => $assessment->classSubject->subject->name, 'classBatch' => $classLabel($assessment->classSubject), 'finalizedAt' => $assessment->finalized_at?->toIso8601String(), 'scoredCount' => (int) $assessment->scored_count])->all(),
            'subjectPerformance' => array_map(
                fn (array $row): array => [...$row, 'classBatch' => $classLabels[$row['classId']] ?? $row['classBatch']],
                array_slice($this->performance->summarize($monitored), 0, 12),
            ),
            'gradeDistribution' => $this->gradeDistribution($monitored),
            'thresholds' => GradingThresholds::forPeriod($period)?->toArray(),
            'instructors' => $this->instructors($periodId, $campus, $classLabels),
        ];
    }

    /**
     * Current subject grades of the period by range, as chart columns.
     *
     * @param  list<MonitoredCandidate>  $monitored
     * @return list<array{label: string, value: int, muted: bool}>
     */
    private function gradeDistribution(array $monitored): array
    {
        $grades = collect($monitored)->flatMap(fn (MonitoredCandidate $candidate): array => array_map(fn (array $subject): ?float => $subject['grade']->grade, $candidate->subjects));

        return $grades->isEmpty() ? [] : ScoreBands::columns(ScoreBands::count($grades));
    }

    /**
     * @param  array<int, string>  $classLabels  CampusScope::classLabels()
     * @return list<array<string, mixed>>
     */
    private function instructors(int $periodId, CampusScope $campus, array $classLabels): array
    {
        return $campus->constrain(InstructorAssignment::query(), 'instructor_assignments.campus_id')->with('instructor:id,name', 'classSubject.classBatch:id,name', 'classSubject.subject:id,name')->whereHas('classSubject.classBatch', fn ($query) => $query->where('academic_period_id', $periodId))->get()->groupBy('instructor_id')->map(function (Collection $assignments) use ($classLabels): array {
            $first = $assignments->first();
            $classes = $assignments->map(fn (InstructorAssignment $assignment): string => $classLabels[$assignment->classSubject->class_batch_id] ?? $assignment->classSubject->classBatch->name);

            return ['id' => $first->instructor->id, 'name' => $first->instructor->name, 'classes' => $classes->unique()->sort()->values()->all(), 'subjects' => $assignments->pluck('classSubject.subject.name')->unique()->sort()->values()->all()];
        })->sortBy('name')->values()->all();
    }
}
