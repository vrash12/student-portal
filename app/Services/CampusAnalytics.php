<?php

namespace App\Services;

use App\Enums\CandidateStatus;
use App\Enums\ExaminationStatus;
use App\Models\AcademicPeriod;
use App\Models\Campus;
use App\Models\Candidate;
use App\Models\ClassBatch;
use App\Models\ClassSubject;
use App\Models\Examination;
use App\Models\ExaminationAttempt;
use App\Models\FitnessTest;
use App\Models\User;
use App\Services\Attendance\AttendanceLedger;
use App\Services\Conduct\ConductLedger;
use App\Services\Fitness\FitnessResults;
use App\Services\Monitoring\AcademicMonitoring;
use App\Services\Monitoring\MonitoredCandidate;
use App\Services\Performance\QualificationOverview;
use App\Support\CampusScope;
use Illuminate\Support\Collection;

/**
 * Analytics of one campus in the active academic year (owner request
 * 2026-10-05): candidates, academic standing (overall and by class),
 * qualification, attendance, merits and demerits, military fitness and
 * examinations, for the Campuses page (comparison) and a campus's Analytics
 * page. Nothing is calculated here: every figure comes from the services the
 * dashboard and records use (grade engine, qualification engine, attendance
 * and conduct ledgers, fitness results), so the numbers always agree.
 */
final class CampusAnalytics
{
    public function __construct(
        private readonly AcademicMonitoring $monitoring,
        private readonly QualificationOverview $qualification,
        private readonly AttendanceLedger $attendance,
        private readonly ConductLedger $conduct,
        private readonly FitnessResults $fitness,
        private readonly AdministratorDashboardService $dashboard,
    ) {}

    public function activePeriod(): ?AcademicPeriod
    {
        return AcademicPeriod::query()->active()->first();
    }

    /**
     * The key figures of a campus, as compared on the Campuses page.
     *
     * @return array<string, mixed>
     */
    public function summary(Campus $campus, ?AcademicPeriod $period): array
    {
        return $this->figures($campus, $period)['summary'];
    }

    /**
     * Everything shown on the campus's Analytics page.
     *
     * @return array<string, mixed>
     */
    public function details(Campus $campus, ?AcademicPeriod $period): array
    {
        $figures = $this->figures($campus, $period);
        $overview = $period === null ? null : $this->dashboard->overview(CampusScope::everyCampus()->narrowTo($campus->id));

        return [
            ...$figures['summary'],
            'classes' => $figures['classes'],
            'attendanceTotals' => $figures['attendanceTotals'],
            'gradeDistribution' => $overview['gradeDistribution'] ?? [],
            'subjectPerformance' => $overview['subjectPerformance'] ?? [],
            'thresholds' => $overview['thresholds'] ?? null,
            'instructors' => $overview['instructors'] ?? [],
        ];
    }

    /**
     * @return array{summary: array<string, mixed>, classes: list<array<string, mixed>>, attendanceTotals: array{present: int, late: int, excused: int, absent: int}}
     */
    private function figures(Campus $campus, ?AcademicPeriod $period): array
    {
        $staff = User::query()->where('campus_id', $campus->id)->where('is_active', true);
        $base = [
            'staffCount' => (clone $staff)->count(),
            'instructorCount' => (clone $staff)->teachingStaff()->count(),
        ];
        $empty = ['passing' => 0, 'atRisk' => 0, 'failing' => 0, 'incomplete' => 0, 'noStanding' => 0];

        if ($period === null) {
            return [
                'summary' => [...$base, 'classCount' => 0, 'candidateCount' => 0, 'standing' => $empty, 'qualification' => null,
                    'attendanceRate' => null, 'attendanceRecords' => 0, 'conduct' => ['merits' => 0, 'demerits' => 0, 'net' => 0],
                    'fitness' => ['tests' => 0, 'passed' => 0, 'failed' => 0, 'incomplete' => 0, 'recorded' => 0, 'passRate' => null],
                    'examinations' => ['published' => 0, 'submitted' => 0, 'meanScore' => null]],
                'classes' => [],
                'attendanceTotals' => ['present' => 0, 'late' => 0, 'excused' => 0, 'absent' => 0],
            ];
        }

        $classes = ClassBatch::query()->where('campus_id', $campus->id)->where('academic_period_id', $period->id)->orderBy('name')->orderBy('id')->get();
        $classIds = $classes->modelKeys();
        $candidateIds = Candidate::query()->whereIn('class_batch_id', $classIds)->where('status', '!=', CandidateStatus::Withdrawn->value)->pluck('id')->all();

        // Academic standing from the grade engine, overall and by class.
        $monitored = $this->monitoring->evaluate(ClassSubject::query()->whereIn('class_batch_id', $classIds)->get());
        $standing = array_intersect_key($this->monitoring->counts($monitored), $empty);
        $byClass = collect($monitored)->groupBy(fn (MonitoredCandidate $entry): int => (int) $entry->candidate->class_batch_id);

        $attendanceTotals = $this->attendance->statusTotals($classIds);
        $rosters = $this->attendance->rosterSizes($classIds);
        $conduct = collect($this->conduct->totalsFor($candidateIds));

        // Fitness: every test of the campus's classes this year.
        $tests = FitnessTest::query()->whereIn('class_batch_id', $classIds)->get();
        $fitness = collect($this->fitness->summaries($tests))->reduce(fn (array $sum, array $test): array => [
            'passed' => $sum['passed'] + $test['passed'], 'failed' => $sum['failed'] + $test['failed'],
            'incomplete' => $sum['incomplete'] + $test['incomplete'], 'recorded' => $sum['recorded'] + $test['recorded'],
        ], ['passed' => 0, 'failed' => 0, 'incomplete' => 0, 'recorded' => 0]);
        $decided = $fitness['passed'] + $fitness['failed'];

        // Examinations of the campus's class subjects this year.
        $examIds = Examination::query()->whereHas('classSubject', fn ($offerings) => $offerings->whereIn('class_batch_id', $classIds))->pluck('id');
        $submitted = ExaminationAttempt::query()->whereIn('examination_id', $examIds)->where('status', 'submitted');
        $meanScore = (clone $submitted)->whereNotNull('percentage')->avg('percentage');

        $summary = [
            ...$base,
            'classCount' => $classes->count(),
            'candidateCount' => count($candidateIds),
            'standing' => $standing,
            'qualification' => $this->qualification->activePeriod(CampusScope::everyCampus()->narrowTo($campus->id)),
            'attendanceRate' => AttendanceLedger::rate($attendanceTotals['present'], $attendanceTotals['late'], $attendanceTotals['absent']),
            'attendanceRecords' => array_sum($attendanceTotals),
            'conduct' => [
                'merits' => (int) $conduct->sum('merits'),
                'demerits' => (int) $conduct->sum('demerits'),
                'net' => (int) $conduct->sum('net'),
            ],
            'fitness' => [
                'tests' => $tests->count(),
                ...$fitness,
                'passRate' => $decided === 0 ? null : round($fitness['passed'] * 100 / $decided, 2),
            ],
            'examinations' => [
                'published' => Examination::query()->whereKey($examIds)->where('status', '!=', ExaminationStatus::Draft->value)->count(),
                'submitted' => (clone $submitted)->count(),
                'meanScore' => $meanScore === null ? null : round((float) $meanScore, 2),
            ],
        ];

        return [
            'summary' => $summary,
            'classes' => $classes->map(function (ClassBatch $class) use ($byClass, $rosters, $empty): array {
                $classMonitored = $byClass->get($class->id, new Collection)->all();
                $totals = $this->attendance->statusTotals([$class->id]);

                return [
                    'id' => $class->id,
                    'name' => $class->name,
                    'candidateCount' => (int) ($rosters[$class->id] ?? 0),
                    'standing' => array_intersect_key($this->monitoring->counts($classMonitored), $empty),
                    'attendanceRate' => AttendanceLedger::rate($totals['present'], $totals['late'], $totals['absent']),
                ];
            })->all(),
            'attendanceTotals' => $attendanceTotals,
        ];
    }
}
