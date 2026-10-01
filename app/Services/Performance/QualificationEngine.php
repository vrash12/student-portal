<?php

namespace App\Services\Performance;

use App\Enums\AreaStatus;
use App\Enums\CandidateStatus;
use App\Enums\FitnessStatus;
use App\Enums\PerformanceSource;
use App\Enums\QualificationStatus;
use App\Models\Candidate;
use App\Models\ClassBatch;
use App\Models\ClassSubject;
use App\Models\FitnessTest;
use App\Models\PerformanceArea;
use App\Services\Attendance\AttendanceLedger;
use App\Services\Conduct\ConductLedger;
use App\Services\Fitness\FitnessOutcome;
use App\Services\Fitness\FitnessResults;
use App\Services\Grading\GradeCalculationService;
use App\Services\Grading\SubjectGrade;
use App\Support\DecimalValue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;

/**
 * The single authoritative calculation of performance areas, the overall
 * score, qualification and class rank (AGENTS.md §15). Pages, the candidate
 * profile, the portal and the dashboard display these results; they never
 * recompute them. Nothing is stored: results are calculated when read, so
 * any change to grades, fitness results, conduct, attendance or the area
 * configuration shows immediately.
 *
 * Inputs come from the existing authoritative calculations, loaded in a
 * fixed number of queries per class however many candidates it has:
 * subject grades from GradeCalculationService, fitness outcomes from
 * FitnessResults (the class's latest test that has results), conduct points
 * from ConductLedger and attendance rates from AttendanceLedger.
 *
 * Rules, for each active area (in area order):
 *
 * - subjects: the candidate's class subjects mapped to the area. Grade = mean
 *   of the subjects' current grades that exist (two decimals, half up).
 *   No results yet without such subjects or grades; Failed below the passing
 *   grade; Incomplete at or above it while any of those subjects has missing
 *   scores (as academic standing); otherwise Passed.
 * - fitness: the outcome of the class's latest fitness test that has
 *   results. Not tested: no results yet; Failed: Failed (grade = points when
 *   every event has a result); Incomplete: Incomplete; Passed: Passed when the
 *   points reach the passing grade, otherwise Failed.
 * - conduct: rating = base + merit points × merit value − demerit points ×
 *   demerit value, limited to 0–100. Passed at or above the passing grade.
 * - attendance: grade = attendance rate. No results yet without a rate;
 *   Passed at or above the passing grade, otherwise Failed.
 *
 * Grades are compared with passing grades in whole hundredths, so a grade
 * shown as 75.00 always passes a passing grade of 75.
 *
 * Overall score = Σ(weight × grade) ÷ Σ(weight) over the areas with a weight
 * above 0 and a grade. Qualification: Not Qualified when a must-pass area is
 * Failed; otherwise Pending while a must-pass area is Incomplete or has no
 * results yet; otherwise Qualified. Rank: by overall score within the class,
 * highest first, ties sharing a rank (1, 2, 2, 4); candidates without an
 * overall score, and withdrawn candidates, are unranked.
 */
final class QualificationEngine
{
    public function __construct(
        private readonly GradeCalculationService $grades,
        private readonly FitnessResults $fitness,
        private readonly ConductLedger $conduct,
        private readonly AttendanceLedger $attendance,
    ) {}

    /**
     * The active areas, in area order.
     *
     * @return list<AreaDefinition>
     */
    public function activeAreas(): array
    {
        return PerformanceArea::query()
            ->active()
            ->ordered()
            ->get()
            ->map(fn (PerformanceArea $area): AreaDefinition => AreaDefinition::fromModel($area))
            ->values()
            ->all();
    }

    /**
     * Every candidate of the class who is not withdrawn, ranked: by rank,
     * then name (last name, first name); unranked candidates last, by name.
     *
     * @return list<CandidateQualification>
     */
    public function forClass(ClassBatch $class): array
    {
        $candidates = Candidate::query()
            ->gradableIn($class->id)
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->orderBy('id')
            ->get();

        return $this->rank($this->evaluate($class, $candidates, $this->activeAreas()));
    }

    /**
     * One candidate's results in their current class, or null without a
     * class. The rank is calculated against the whole class unless
     * `$withRank` is false (cheaper, for the candidate portal, which never
     * shows it). A withdrawn candidate gets results but no rank.
     */
    public function forCandidate(Candidate $candidate, bool $withRank = true): ?CandidateQualification
    {
        if ($candidate->class_batch_id === null) {
            return null;
        }

        // Queried rather than lazy loaded, so a candidate taken from a list works too.
        $class = $candidate->relationLoaded('classBatch') ? $candidate->classBatch : $candidate->classBatch()->first();
        if ($class === null) {
            return null;
        }

        $areas = $this->activeAreas();
        if (! $withRank) {
            return $this->evaluate($class, new Collection([$candidate]), $areas)[0] ?? null;
        }

        $population = Candidate::query()
            ->gradableIn($class->id)
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->orderBy('id')
            ->get();
        if (! $population->contains($candidate->id)) {
            $population->push($candidate);
        }

        foreach ($this->rank($this->evaluate($class, $population, $areas)) as $qualification) {
            if ($qualification->candidate->id === $candidate->id) {
                return $qualification;
            }
        }

        return null;
    }

    // ------------------------------------------------------------------
    // Rules (no database access)
    // ------------------------------------------------------------------

    /**
     * @param  list<array{name: string, grade: SubjectGrade}>  $subjects  the candidate's class subjects mapped to the area
     */
    public function subjectsResult(AreaDefinition $area, array $subjects): AreaResult
    {
        if ($subjects === []) {
            return new AreaResult($area, null, AreaStatus::NotYet, 'No subject of this area is taught in the class.');
        }

        $grades = [];
        $missing = [];
        foreach ($subjects as $subject) {
            if ($subject['grade']->grade !== null) {
                $grades[] = DecimalValue::toHundredths($subject['grade']->grade);
            }
            if ($subject['grade']->missingScores > 0) {
                $missing[] = $subject['name'];
            }
        }

        if ($grades === []) {
            return new AreaResult($area, null, AreaStatus::NotYet, $missing === [] ? 'No subject grades yet.' : self::missingScoresNote($missing));
        }

        $grade = self::meanOfHundredths($grades);
        $notes = [];
        if (count($grades) < count($subjects)) {
            $notes[] = sprintf('Based on %d of %d subjects; the others have no grades yet.', count($grades), count($subjects));
        }
        if ($missing !== []) {
            $notes[] = self::missingScoresNote($missing);
        }

        $status = match (true) {
            ! self::meets($grade, $area->passingGrade) => AreaStatus::Failed,
            $missing !== [] => AreaStatus::Incomplete,
            default => AreaStatus::Passed,
        };

        return new AreaResult($area, $grade, $status, $notes === [] ? null : implode(' ', $notes));
    }

    /**
     * @param  FitnessOutcome|null  $outcome  the candidate's outcome in the class's latest test with results; null when there is no such test
     */
    public function fitnessResult(AreaDefinition $area, ?FitnessOutcome $outcome, ?string $testTitle = null): AreaResult
    {
        if ($outcome === null) {
            return new AreaResult($area, null, AreaStatus::NotYet, 'No fitness test results for the class yet.');
        }

        $test = $testTitle ?? 'Latest fitness test';

        return match ($outcome->status) {
            FitnessStatus::NotTested => new AreaResult($area, null, AreaStatus::NotYet, "Not tested in {$test}."),
            FitnessStatus::Incomplete => new AreaResult($area, null, AreaStatus::Incomplete, "{$test}: some events have no result yet."),
            FitnessStatus::Failed => new AreaResult($area, $outcome->points, AreaStatus::Failed, "{$test}: an event standard was not met."),
            FitnessStatus::Passed => $outcome->points !== null && self::meets($outcome->points, $area->passingGrade)
                ? new AreaResult($area, $outcome->points, AreaStatus::Passed, "{$test}.")
                : new AreaResult($area, $outcome->points, AreaStatus::Failed, "{$test}: every event was passed, but the points are below the passing grade of this area."),
        };
    }

    /**
     * @param  int  $merits  merit points over entries that are not voided
     * @param  int  $demerits  demerit points over entries that are not voided
     */
    public function conductResult(AreaDefinition $area, int $merits, int $demerits): AreaResult
    {
        // Whole hundredths, so the rating is exact.
        $raw = DecimalValue::toHundredths($area->baseRating ?? 0)
            + $merits * DecimalValue::toHundredths($area->meritValue ?? 0)
            - $demerits * DecimalValue::toHundredths($area->demeritValue ?? 0);
        $clamped = max(0, min(10000, $raw));
        $rating = (float) ($clamped / 100);

        $note = sprintf('%s, %s.', self::points($merits, 'merit'), self::points($demerits, 'demerit'));
        if ($clamped !== $raw) {
            $note .= ' The rating is limited to 0–100.';
        }

        return new AreaResult($area, $rating, self::meets($rating, $area->passingGrade) ? AreaStatus::Passed : AreaStatus::Failed, $note);
    }

    /**
     * @param  array{sessions: int, present: int, late: int, excused: int, absent: int, unrecorded: int, hours: float, rate: ?float}|null  $summary  from AttendanceLedger::summariesFor
     */
    public function attendanceResult(AreaDefinition $area, ?array $summary): AreaResult
    {
        $rate = $summary['rate'] ?? null;
        if ($summary === null || $rate === null) {
            return new AreaResult($area, null, AreaStatus::NotYet, 'No attendance counted yet.');
        }

        $attended = $summary['present'] + $summary['late'];
        $note = sprintf('Attended %d of %d counted sessions.', $attended, $attended + $summary['absent']);
        if ($summary['excused'] > 0) {
            $note .= sprintf(' %d excused.', $summary['excused']);
        }

        return new AreaResult($area, $rate, self::meets($rate, $area->passingGrade) ? AreaStatus::Passed : AreaStatus::Failed, $note);
    }

    /**
     * Σ(weight × grade) ÷ Σ(weight) over areas with a weight above 0 and a
     * grade, rounded half up to two decimals with exact integer arithmetic.
     * `complete` is true when every weighted area has a grade (false when no
     * area is weighted, as there is then no overall score).
     *
     * @param  list<AreaResult>  $results
     * @return array{score: float|null, complete: bool}
     */
    public function overall(array $results): array
    {
        $numerator = 0;
        $denominator = 0;
        $weighted = 0;
        $graded = 0;

        foreach ($results as $result) {
            $weight = DecimalValue::toHundredths($result->area->weight);
            if ($weight <= 0) {
                continue;
            }

            $weighted++;
            if ($result->grade === null) {
                continue;
            }

            $graded++;
            $numerator += $weight * DecimalValue::toHundredths($result->grade);
            $denominator += $weight;
        }

        return [
            // numerator ÷ denominator is the score in hundredths.
            'score' => $denominator === 0 ? null : (float) (intdiv(2 * $numerator + $denominator, 2 * $denominator) / 100),
            'complete' => $weighted > 0 && $graded === $weighted,
        ];
    }

    /**
     * Not Qualified when any must-pass area is Failed, with the reason
     * "{Area} requirement not met" for each, in area order; otherwise
     * Pending while any must-pass area is Incomplete or has no results yet;
     * otherwise Qualified.
     *
     * With no active area at all the decision is Pending, so nobody is
     * reported Qualified before the areas are configured.
     *
     * @param  list<AreaResult>  $results
     */
    /** The reason given for a failed must-pass area. */
    public static function reasonFor(string $areaName): string
    {
        return "{$areaName} requirement not met";
    }

    public function qualify(array $results): QualificationDecision
    {
        if ($results === []) {
            return new QualificationDecision(QualificationStatus::Pending, [], []);
        }

        $reasons = [];
        $pending = [];
        foreach ($results as $result) {
            if (! $result->area->mustPass) {
                continue;
            }

            if ($result->status === AreaStatus::Failed) {
                $reasons[] = self::reasonFor($result->area->name);
            } elseif ($result->status !== AreaStatus::Passed) {
                $pending[] = $result->area->name;
            }
        }

        $status = match (true) {
            $reasons !== [] => QualificationStatus::NotQualified,
            $pending !== [] => QualificationStatus::Pending,
            default => QualificationStatus::Qualified,
        };

        return new QualificationDecision($status, $reasons, $pending);
    }

    /**
     * @param  list<AreaResult>  $results  one per active area, in area order
     */
    public function qualification(Candidate $candidate, array $results): CandidateQualification
    {
        $overall = $this->overall($results);

        return new CandidateQualification($candidate, $results, $overall['score'], $overall['complete'], $this->qualify($results));
    }

    /**
     * Competition ranking ("1, 2, 2, 4") by score, highest first, compared
     * in whole hundredths. Null scores are unranked (null).
     *
     * @param  array<array-key, float|null>  $scores
     * @return array<array-key, int|null> with the same keys
     */
    public function ranks(array $scores): array
    {
        $ranks = array_fill_keys(array_keys($scores), null);
        $ranked = array_map(
            fn (float $score): int => DecimalValue::toHundredths($score),
            array_filter($scores, fn (?float $score): bool => $score !== null),
        );
        arsort($ranked);

        $position = 0;
        $rank = 0;
        $previous = null;
        foreach ($ranked as $key => $score) {
            $position++;
            if ($score !== $previous) {
                $rank = $position;
                $previous = $score;
            }
            $ranks[$key] = $rank;
        }

        return $ranks;
    }

    /**
     * Assigns class ranks and sorts by rank; ties and unranked candidates
     * (last) keep the order given. Withdrawn candidates are never ranked.
     *
     * @param  list<CandidateQualification>  $qualifications  all of one class
     * @return list<CandidateQualification>
     */
    public function rank(array $qualifications): array
    {
        $ranks = $this->ranks(array_map(
            fn (CandidateQualification $qualification): ?float => $qualification->candidate->status === CandidateStatus::Withdrawn ? null : $qualification->overall,
            $qualifications,
        ));

        $ranked = [];
        foreach ($qualifications as $index => $qualification) {
            $ranked[] = $qualification->withRank($ranks[$index]);
        }

        usort($ranked, fn (CandidateQualification $a, CandidateQualification $b): int => [$a->rank === null, $a->rank ?? 0] <=> [$b->rank === null, $b->rank ?? 0]);

        return $ranked;
    }

    // ------------------------------------------------------------------
    // Summaries of a list of results (pages and dashboard)
    // ------------------------------------------------------------------

    /**
     * Candidates per qualification status. The buckets add up to the total.
     *
     * @param  list<CandidateQualification>  $qualifications
     * @return array{total: int, qualified: int, notQualified: int, pending: int}
     */
    public function counts(array $qualifications): array
    {
        $counts = ['total' => count($qualifications), 'qualified' => 0, 'notQualified' => 0, 'pending' => 0];
        foreach ($qualifications as $qualification) {
            $counts[match ($qualification->qualification->status) {
                QualificationStatus::Qualified => 'qualified',
                QualificationStatus::NotQualified => 'notQualified',
                QualificationStatus::Pending => 'pending',
            }]++;
        }

        return $counts;
    }

    /**
     * Candidates per area status, for each of the given areas.
     *
     * @param  list<CandidateQualification>  $qualifications
     * @param  list<AreaDefinition>  $areas
     * @return list<array{areaId: int, name: string, mustPass: bool, passed: int, failed: int, incomplete: int, notYet: int, passRate: float|null}>
     */
    public function areaCounts(array $qualifications, array $areas): array
    {
        return array_map(function (AreaDefinition $area) use ($qualifications): array {
            $counts = ['passed' => 0, 'failed' => 0, 'incomplete' => 0, 'notYet' => 0];
            foreach ($qualifications as $qualification) {
                $status = $qualification->result($area->id)?->status;
                if ($status !== null) {
                    $counts[match ($status) {
                        AreaStatus::Passed => 'passed',
                        AreaStatus::Failed => 'failed',
                        AreaStatus::Incomplete => 'incomplete',
                        AreaStatus::NotYet => 'notYet',
                    }]++;
                }
            }
            $total = array_sum($counts);

            return [
                'areaId' => $area->id,
                'name' => $area->name,
                'mustPass' => $area->mustPass,
                ...$counts,
                // Share of the candidates who passed the area, half up.
                'passRate' => $total === 0 ? null : (float) (intdiv(2 * $counts['passed'] * 10000 + $total, 2 * $total) / 100),
            ];
        }, $areas);
    }

    /**
     * The must-pass area failed by the most candidates (the first in area
     * order on a tie), or null when no must-pass area is failed.
     *
     * @param  list<CandidateQualification>  $qualifications
     * @param  list<AreaDefinition>  $areas
     * @return array{areaId: int, name: string, count: int}|null
     */
    public function mostCommonUnmet(array $qualifications, array $areas): ?array
    {
        $most = null;
        foreach ($this->areaCounts($qualifications, $areas) as $area) {
            if ($area['mustPass'] && $area['failed'] > 0 && ($most === null || $area['failed'] > $most['count'])) {
                $most = ['areaId' => $area['areaId'], 'name' => $area['name'], 'count' => $area['failed']];
            }
        }

        return $most;
    }

    // ------------------------------------------------------------------
    // Loading (a fixed number of queries per class)
    // ------------------------------------------------------------------

    /**
     * @param  Collection<int, Candidate>  $candidates  all assigned to $class
     * @param  list<AreaDefinition>  $areas
     * @return list<CandidateQualification> in the order of $candidates
     */
    private function evaluate(ClassBatch $class, Collection $candidates, array $areas): array
    {
        if ($candidates->isEmpty()) {
            return [];
        }

        $sources = array_map(fn (AreaDefinition $area): PerformanceSource => $area->source, $areas);
        $candidateIds = $candidates->modelKeys();

        $subjects = in_array(PerformanceSource::Subjects, $sources, true) ? $this->subjectGrades($class, $candidates, $areas) : [];
        $fitness = in_array(PerformanceSource::Fitness, $sources, true) ? $this->latestFitness($class) : null;
        $conduct = in_array(PerformanceSource::Conduct, $sources, true) ? $this->conduct->totalsFor($candidateIds) : [];
        $attendance = in_array(PerformanceSource::Attendance, $sources, true) ? $this->attendance->summariesFor($class->id, $candidateIds) : [];

        return $candidates
            ->map(function (Candidate $candidate) use ($areas, $subjects, $fitness, $conduct, $attendance): CandidateQualification {
                $results = array_map(fn (AreaDefinition $area): AreaResult => match ($area->source) {
                    PerformanceSource::Subjects => $this->subjectsResult($area, $subjects[$candidate->id][$area->id] ?? []),
                    PerformanceSource::Fitness => $this->fitnessResult(
                        $area,
                        $fitness === null ? null : ($fitness['outcomes'][$candidate->id] ?? new FitnessOutcome(FitnessStatus::NotTested, null)),
                        $fitness['title'] ?? null,
                    ),
                    PerformanceSource::Conduct => $this->conductResult($area, $conduct[$candidate->id]['merits'] ?? 0, $conduct[$candidate->id]['demerits'] ?? 0),
                    PerformanceSource::Attendance => $this->attendanceResult($area, $attendance[$candidate->id] ?? null),
                }, $areas);

                return $this->qualification($candidate, $results);
            })
            ->values()
            ->all();
    }

    /**
     * The grades of each candidate in the class subjects mapped to an active
     * subject area, from GradeCalculationService (same grades and missing
     * scores as academic standing), grouped by area and ordered by subject.
     *
     * @param  Collection<int, Candidate>  $candidates
     * @param  list<AreaDefinition>  $areas
     * @return array<int, array<int, list<array{name: string, grade: SubjectGrade}>>> candidate id => area id => subjects
     */
    private function subjectGrades(ClassBatch $class, Collection $candidates, array $areas): array
    {
        $areaIds = array_values(array_map(
            fn (AreaDefinition $area): int => $area->id,
            array_filter($areas, fn (AreaDefinition $area): bool => $area->source === PerformanceSource::Subjects),
        ));

        $offerings = ClassSubject::query()
            ->where('class_batch_id', $class->id)
            ->whereHas('subject', fn (Builder $subjects) => $subjects->whereIn('performance_area_id', $areaIds))
            ->with('subject:id,code,name,performance_area_id')
            ->get()
            ->sortBy(fn (ClassSubject $offering): string => $offering->subject->name)
            ->values();
        if ($offerings->isEmpty()) {
            return [];
        }

        // The offerings all belong to this class; share it (and its period) instead of loading it per offering.
        $class->loadMissing('academicPeriod');
        $offerings->each(fn (ClassSubject $offering) => $offering->setRelation('classBatch', $class));

        $grades = $this->grades->forClasses($offerings, $candidates);

        $byCandidate = [];
        foreach ($candidates as $candidate) {
            foreach ($offerings as $offering) {
                $grade = $grades[$candidate->id][$offering->id] ?? null;
                if ($grade !== null) {
                    $byCandidate[$candidate->id][(int) $offering->subject->performance_area_id][] = ['name' => $offering->subject->name, 'grade' => $grade];
                }
            }
        }

        return $byCandidate;
    }

    /**
     * Outcomes of the class's latest fitness test that has results (by test
     * date, then most recently created), or null when there is none. The
     * outcomes come from FitnessResults, the fitness scoring rule.
     *
     * @return array{title: string, outcomes: array<int, FitnessOutcome>}|null
     */
    private function latestFitness(ClassBatch $class): ?array
    {
        $test = FitnessTest::query()
            ->where('class_batch_id', $class->id)
            ->whereHas('results')
            ->orderByDesc('tested_on')
            ->orderByDesc('id')
            ->first();
        if ($test === null) {
            return null;
        }

        $outcomes = [];
        foreach ($this->fitness->sheet($test)['rows'] as $row) {
            $outcomes[(int) $row['candidate']['id']] = new FitnessOutcome(
                FitnessStatus::from((string) $row['outcome']['status']['value']),
                $row['outcome']['points'],
            );
        }

        return ['title' => $test->title, 'outcomes' => $outcomes];
    }

    private static function meets(float $grade, float $passingGrade): bool
    {
        return DecimalValue::toHundredths($grade) >= DecimalValue::toHundredths($passingGrade);
    }

    /**
     * Mean of non-negative values in hundredths, rounded half up to two
     * decimals with exact integer arithmetic.
     *
     * @param  non-empty-list<int>  $hundredths
     */
    private static function meanOfHundredths(array $hundredths): float
    {
        $count = count($hundredths);

        return (float) (intdiv(2 * array_sum($hundredths) + $count, 2 * $count) / 100);
    }

    /**
     * @param  non-empty-list<string>  $subjects
     */
    private static function missingScoresNote(array $subjects): string
    {
        return 'Scores are missing in '.Arr::join($subjects, ', ', ' and ').'.';
    }

    private static function points(int $points, string $kind): string
    {
        return $points === 1 ? "1 {$kind} point" : "{$points} {$kind} points";
    }
}
