<?php

namespace App\Services;

use App\Models\Campus;
use App\Models\ExaminationAttempt;
use App\Models\User;
use App\Services\Monitoring\AcademicMonitoring;
use App\Services\Monitoring\MonitoringScope;
use App\Services\Monitoring\SubjectPerformance;
use App\Support\InstitutionDate;
use App\Support\ScoreBands;

final class ReportingService
{
    public const TYPES = [
        'candidate' => 'Candidate academic standing', 'class' => 'Class / batch performance',
        'subject' => 'Subject performance', 'at_risk' => 'Candidates needing improvement',
        'failing' => 'Failing candidates', 'examination' => 'Examination results',
        'quiz' => 'Quiz results', 'distribution' => 'Grade distribution',
    ];

    /**
     * A row entry for the charts only (ReportCharts), e.g. which examination
     * an attempt belongs to. Never displayed or searched; the controller
     * removes it before the rows reach the page.
     */
    public const CHART_KEY = '_chart';

    public function __construct(private readonly AcademicMonitoring $monitoring, private readonly SubjectPerformance $performance) {}

    public function generate(User $user, array $filters): array
    {
        $filters = array_intersect_key($filters, array_flip(['type', 'period', 'campus', 'class', 'subject', 'search', 'from', 'to']));
        // The user's scope, narrowed to one campus by the campus filter (accounts that see every campus only).
        $scope = MonitoringScope::for($user)->narrowedTo(isset($filters['campus']) && ctype_digit((string) $filters['campus']) ? (int) $filters['campus'] : null);
        $periods = $scope->periods();
        $periodId = (int) ($filters['period'] ?? $periods[0]['id'] ?? 0);
        abort_if($periodId && ! in_array($periodId, array_column($periods, 'id'), true), 403);
        $all = $scope->offerings($periodId)->with('classBatch', 'subject')->get();
        $offerings = $all->filter(fn ($offering) => (empty($filters['class']) || $offering->class_batch_id === (int) $filters['class']) && (empty($filters['subject']) || $offering->subject_id === (int) $filters['subject']))->values();
        $type = $filters['type'] ?? 'candidate';
        $filters = array_merge(['period' => $periodId ?: '', 'class' => '', 'subject' => '', 'search' => '', 'from' => '', 'to' => ''], $filters, ['type' => $type, 'campus' => $scope->campus->filterValue()]);
        $result = in_array($type, ['examination', 'quiz'], true)
            ? $this->examinations($offerings->modelKeys(), $type, $filters)
            : $this->academic($this->monitoring->evaluate($offerings), $type);
        // Search narrows authorized output, including aggregated report rows. It
        // matches the displayed values only, never the chart-only `_chart` entry.
        if (($filters['search'] ?? '') !== '') {
            $term = mb_strtolower($filters['search']);
            $result['rows'] = array_values(array_filter($result['rows'], fn ($row) => str_contains(mb_strtolower(implode(' ', array_diff_key($row, [self::CHART_KEY => true]))), $term)));
        }

        return $result + [
            'filters' => $filters, 'periods' => $periods, 'scope' => $scope->kind(),
            'campusOptions' => $scope->campus->filterOptions(),
            // The campus the report covers, for the PDF ("All campuses" when not narrowed).
            'campusLabel' => $scope->campus->campusId === null ? 'All campuses' : (string) Campus::query()->whereKey($scope->campus->campusId)->value('name'),
            'classes' => $all->pluck('classBatch')->unique('id')->sortBy('name')->map(fn ($row) => ['id' => $row->id, 'name' => $row->name])->values()->all(),
            'subjects' => $all->pluck('subject')->unique('id')->sortBy('name')->map(fn ($row) => ['id' => $row->id, 'name' => $row->name])->values()->all(),
            'generatedAt' => now()->toIso8601String(),
        ];
    }

    /**
     * Each report returns its columns, the rows, and `numericColumns`: display
     * metadata naming the columns the page right-aligns (UI_UX_DESIGN.md §31).
     */
    private function academic(array $entries, string $type): array
    {
        if ($type === 'subject') {
            return ['columns' => ['classBatch' => 'Class', 'subject' => 'Subject', 'candidateCount' => 'Candidates', 'average' => 'Mean current grade', 'provisionalCount' => 'Provisional', 'passing' => 'Passing', 'at_risk' => 'Needs Improvement', 'failing' => 'Failing', 'incomplete' => 'Incomplete', 'none' => 'No standing'],
                'numericColumns' => ['candidateCount', 'average', 'provisionalCount', 'passing', 'at_risk', 'failing', 'incomplete', 'none'],
                'rows' => array_map(fn ($row) => array_diff_key($row, array_flip(['id', 'subjectId', 'classId', 'gradedCount'])), $this->performance->summarize($entries))];
        }
        if ($type === 'class') {
            $rows = collect($entries)->groupBy(fn ($entry) => $entry->candidate->class_batch_id)->map(function ($group) {
                return ['classBatch' => $group->first()->candidate->classBatch->name] + $this->monitoring->counts($group->all());
            })->values()->all();

            return ['columns' => ['classBatch' => 'Class', 'monitored' => 'Candidates', 'passing' => 'Passing', 'atRisk' => 'Needs Improvement', 'failing' => 'Failing', 'incomplete' => 'Incomplete', 'noStanding' => 'No standing'],
                'numericColumns' => ['monitored', 'passing', 'atRisk', 'failing', 'incomplete', 'noStanding'], 'rows' => $rows];
        }
        if ($type === 'distribution') {
            $grades = collect($entries)->flatMap(fn ($entry) => array_map(fn ($subject) => $subject['grade']->grade, $entry->subjects));

            return ['columns' => ['band' => 'Grade range (descriptive only)', 'count' => 'Candidate-subject records'], 'numericColumns' => ['count'], 'rows' => ScoreBands::count($grades)];
        }
        $rows = [];
        foreach ($entries as $entry) {
            if (in_array($type, ['at_risk', 'failing'], true) && $entry->standingKey() !== $type) {
                continue;
            }
            $rows[] = ['number' => $entry->candidate->candidate_number, 'candidate' => $entry->candidate->full_name, 'classBatch' => $entry->candidate->classBatch->name,
                'standing' => $entry->overall->standing?->label() ?? 'No standing', 'lowest' => $entry->lowestGrade(), 'missing' => $entry->missingScores(),
                'provisional' => collect($entry->subjects)->contains(fn ($subject) => $subject['grade']->isProvisional()) ? 'Yes' : 'No'];
        }

        return ['columns' => ['number' => 'Candidate no.', 'candidate' => 'Candidate', 'classBatch' => 'Class', 'standing' => 'Overall standing', 'lowest' => 'Lowest subject grade', 'missing' => 'Missing scores', 'provisional' => 'Provisional grades'],
            'numericColumns' => ['lowest', 'missing'], 'rows' => $rows];
    }

    private function examinations(array $offeringIds, string $kind, array $filters): array
    {
        $attempts = ExaminationAttempt::query()->select(['id', 'candidate_id', 'examination_id', 'attempt_number', 'submitted_at', 'status', 'result_status', 'earned_points', 'total_points', 'percentage', 'passed'])
            ->with('candidate', 'examination.classSubject.subject', 'examination.classSubject.classBatch')
            ->whereHas('examination', fn ($query) => $query->whereIn('class_subject_id', $offeringIds)->where('kind', $kind))
            ->whereIn('status', ['submitted', 'expired'])
            ->when($filters['from'], fn ($query, $date) => $query->where('submitted_at', '>=', InstitutionDate::boundary($date)))
            ->when($filters['to'], fn ($query, $date) => $query->where('submitted_at', '<', InstitutionDate::boundary($date, true)))
            ->orderByDesc('submitted_at')->orderByDesc('id')->get();

        return ['columns' => ['candidate' => 'Candidate', 'number' => 'Candidate no.', 'classBatch' => 'Class', 'subject' => 'Subject', 'examination' => 'Title', 'attempt' => 'Attempt', 'submitted' => 'Submitted', 'status' => 'Result status', 'score' => 'Score', 'percentage' => 'Percent', 'result' => 'Outcome'],
            'numericColumns' => ['attempt', 'score', 'percentage'],
            'rows' => $attempts->map(fn ($attempt) => [
                'candidate' => $attempt->candidate->full_name, 'number' => $attempt->candidate->candidate_number,
                'classBatch' => $attempt->examination->classSubject->classBatch->name, 'subject' => $attempt->examination->classSubject->subject->name,
                'examination' => $attempt->examination->title, 'attempt' => $attempt->attempt_number, 'submitted' => $attempt->submitted_at?->timezone(config('institution.timezone'))->format('Y-m-d H:i'),
                'status' => $attempt->result_status === 'pending_review' ? 'Awaiting essay grading' : ($attempt->result_status === 'graded' ? 'Graded' : ucfirst($attempt->status)),
                'score' => $attempt->result_status === 'graded' ? $attempt->earned_points.' / '.$attempt->total_points : null,
                'percentage' => $attempt->result_status === 'graded' ? $attempt->percentage : null,
                'result' => $attempt->passed === null ? 'Pending / not configured' : ($attempt->passed ? 'Passed' : 'Failed'),
                self::CHART_KEY => ['examination' => $attempt->examination_id, 'passed' => $attempt->passed, 'resultStatus' => $attempt->result_status],
            ])->all()];
    }
}
