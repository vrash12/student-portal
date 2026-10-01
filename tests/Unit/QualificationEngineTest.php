<?php

namespace Tests\Unit;

use App\Enums\AreaStatus;
use App\Enums\CandidateStatus;
use App\Enums\FitnessStatus;
use App\Enums\PerformanceSource;
use App\Enums\QualificationStatus;
use App\Models\Candidate;
use App\Services\Attendance\AttendanceLedger;
use App\Services\Conduct\ConductLedger;
use App\Services\Fitness\FitnessOutcome;
use App\Services\Fitness\FitnessResults;
use App\Services\Grading\GradeCalculationService;
use App\Services\Grading\SubjectGrade;
use App\Services\Performance\AreaDefinition;
use App\Services\Performance\AreaResult;
use App\Services\Performance\CandidateQualification;
use App\Services\Performance\QualificationEngine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Deterministic tests of every performance area rule, the overall score,
 * qualification and class rank (AGENTS.md §52). No database: the rules are
 * applied to values directly.
 */
class QualificationEngineTest extends TestCase
{
    private QualificationEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->engine = new QualificationEngine(new GradeCalculationService, new FitnessResults, new ConductLedger, new AttendanceLedger);
    }

    private static function area(
        PerformanceSource $source,
        float $passingGrade = 75,
        float $weight = 20,
        bool $mustPass = true,
        int $id = 1,
        string $name = 'Area',
        ?float $baseRating = null,
        ?float $meritValue = null,
        ?float $demeritValue = null,
    ): AreaDefinition {
        return new AreaDefinition($id, $name, $source, $weight, $passingGrade, $mustPass, null, $baseRating, $meritValue, $demeritValue);
    }

    /**
     * @return array{name: string, grade: SubjectGrade}
     */
    private static function subject(string $name, ?float $grade, int $missingScores = 0): array
    {
        return ['name' => $name, 'grade' => new SubjectGrade([], $grade, 100.0, $missingScores, 0, null)];
    }

    private function areaResult(int $id, ?float $grade, AreaStatus $status, float $weight = 20, bool $mustPass = true, string $name = 'Area'): AreaResult
    {
        return new AreaResult(self::area(PerformanceSource::Subjects, weight: $weight, mustPass: $mustPass, id: $id, name: $name), $grade, $status);
    }

    // ------------------------------------------------------------------
    // Subject areas
    // ------------------------------------------------------------------

    public function test_a_subject_area_without_subjects_in_the_class_has_no_results_yet(): void
    {
        $result = $this->engine->subjectsResult(self::area(PerformanceSource::Subjects), []);

        $this->assertNull($result->grade);
        $this->assertSame(AreaStatus::NotYet, $result->status);
        $this->assertSame('No subject of this area is taught in the class.', $result->note);
    }

    public function test_a_subject_area_without_any_grade_has_no_results_yet(): void
    {
        $result = $this->engine->subjectsResult(self::area(PerformanceSource::Subjects), [self::subject('Subject 1', null), self::subject('Subject 2', null)]);
        $this->assertNull($result->grade);
        $this->assertSame(AreaStatus::NotYet, $result->status);
        $this->assertSame('No subject grades yet.', $result->note);

        // Missing scores without any grade: still nothing to judge, but the reason is given.
        $result = $this->engine->subjectsResult(self::area(PerformanceSource::Subjects), [self::subject('Subject 1', null, 2)]);
        $this->assertSame(AreaStatus::NotYet, $result->status);
        $this->assertSame('Scores are missing in Subject 1.', $result->note);
    }

    /**
     * @return array<string, array{list<float>, float, float, AreaStatus}>
     */
    public static function subjectGrades(): array
    {
        return [
            'exactly at the passing grade' => [[75.0], 75.0, 75.0, AreaStatus::Passed],
            'one hundredth below' => [[74.99], 75.0, 74.99, AreaStatus::Failed],
            'mean rounded half up' => [[80.0, 75.01], 75.0, 77.51, AreaStatus::Passed],
            'mean below passing' => [[80.0, 60.0], 75.0, 70.0, AreaStatus::Failed],
            'three subjects' => [[84.5, 72.4, 91.0], 75.0, 82.63, AreaStatus::Passed],
            'decimal passing grade' => [[77.5], 77.5, 77.5, AreaStatus::Passed],
        ];
    }

    /**
     * @param  list<float>  $grades
     */
    #[DataProvider('subjectGrades')]
    public function test_a_subject_area_grade_is_the_mean_of_the_subject_grades(array $grades, float $passingGrade, float $expected, AreaStatus $status): void
    {
        $subjects = array_map(fn (float $grade, int $index): array => self::subject('Subject '.($index + 1), $grade), $grades, array_keys($grades));
        $result = $this->engine->subjectsResult(self::area(PerformanceSource::Subjects, $passingGrade), $subjects);

        $this->assertSame($expected, $result->grade);
        $this->assertSame($status, $result->status);
        $this->assertNull($result->note);
    }

    public function test_missing_scores_make_a_passing_subject_area_incomplete_but_never_hide_a_failure(): void
    {
        $area = self::area(PerformanceSource::Subjects);

        $incomplete = $this->engine->subjectsResult($area, [self::subject('Subject 1', 90.0), self::subject('Subject 2', 80.0, 1)]);
        $this->assertSame(85.0, $incomplete->grade);
        $this->assertSame(AreaStatus::Incomplete, $incomplete->status);
        $this->assertSame('Scores are missing in Subject 2.', $incomplete->note);

        $failed = $this->engine->subjectsResult($area, [self::subject('Subject 1', 70.0, 1), self::subject('Subject 2', 72.0, 3)]);
        $this->assertSame(71.0, $failed->grade);
        $this->assertSame(AreaStatus::Failed, $failed->status);
        $this->assertSame('Scores are missing in Subject 1 and Subject 2.', $failed->note);
    }

    public function test_subjects_without_a_grade_are_left_out_of_the_mean(): void
    {
        $result = $this->engine->subjectsResult(self::area(PerformanceSource::Subjects), [self::subject('Subject 1', 90.0), self::subject('Subject 2', null)]);

        $this->assertSame(90.0, $result->grade);
        $this->assertSame(AreaStatus::Passed, $result->status);
        $this->assertSame('Based on 1 of 2 subjects; the others have no grades yet.', $result->note);
    }

    // ------------------------------------------------------------------
    // Fitness areas
    // ------------------------------------------------------------------

    /**
     * @return array<string, array{FitnessOutcome|null, float|null, AreaStatus}>
     */
    public static function fitnessOutcomes(): array
    {
        return [
            'no test with results' => [null, null, AreaStatus::NotYet],
            'not tested' => [new FitnessOutcome(FitnessStatus::NotTested, null), null, AreaStatus::NotYet],
            'incomplete' => [new FitnessOutcome(FitnessStatus::Incomplete, null), null, AreaStatus::Incomplete],
            'failed, every event recorded' => [new FitnessOutcome(FitnessStatus::Failed, 58.5), 58.5, AreaStatus::Failed],
            'failed, events missing' => [new FitnessOutcome(FitnessStatus::Failed, null), null, AreaStatus::Failed],
            'passed above the passing grade' => [new FitnessOutcome(FitnessStatus::Passed, 82.0), 82.0, AreaStatus::Passed],
            'passed exactly at the passing grade' => [new FitnessOutcome(FitnessStatus::Passed, 70.0), 70.0, AreaStatus::Passed],
            'passed every event but below the passing grade' => [new FitnessOutcome(FitnessStatus::Passed, 69.99), 69.99, AreaStatus::Failed],
        ];
    }

    #[DataProvider('fitnessOutcomes')]
    public function test_a_fitness_area_follows_the_latest_test_outcome(?FitnessOutcome $outcome, ?float $grade, AreaStatus $status): void
    {
        $result = $this->engine->fitnessResult(self::area(PerformanceSource::Fitness, 70), $outcome, 'Fitness Test 1');

        $this->assertSame($grade, $result->grade);
        $this->assertSame($status, $result->status);
    }

    public function test_fitness_notes_name_the_test(): void
    {
        $area = self::area(PerformanceSource::Fitness, 60);

        $this->assertSame('No fitness test results for the class yet.', $this->engine->fitnessResult($area, null)->note);
        $this->assertSame('Not tested in Fitness Test 2.', $this->engine->fitnessResult($area, new FitnessOutcome(FitnessStatus::NotTested, null), 'Fitness Test 2')->note);
        $this->assertSame('Fitness Test 2: an event standard was not met.', $this->engine->fitnessResult($area, new FitnessOutcome(FitnessStatus::Failed, 40.0), 'Fitness Test 2')->note);
    }

    // ------------------------------------------------------------------
    // Conduct areas
    // ------------------------------------------------------------------

    /**
     * Base 85, merit value 1, demerit value 1, passing 75 unless stated.
     *
     * @return array<string, array{int, int, float, AreaStatus, float, float, float}>
     */
    public static function conductRatings(): array
    {
        return [
            'no entries: the base rating' => [0, 0, 85.0, AreaStatus::Passed, 85, 1, 1],
            'merits and demerits' => [3, 5, 83.0, AreaStatus::Passed, 85, 1, 1],
            'exactly at the passing grade' => [0, 10, 75.0, AreaStatus::Passed, 85, 1, 1],
            'one point below' => [0, 11, 74.0, AreaStatus::Failed, 85, 1, 1],
            'limited to 100' => [30, 0, 100.0, AreaStatus::Passed, 85, 1, 1],
            'limited to 0' => [0, 50, 0.0, AreaStatus::Failed, 40, 1, 2],
            'fractional values' => [3, 2, 79.0, AreaStatus::Passed, 80, 0.5, 1.25],
            'merits not counted' => [10, 2, 83.0, AreaStatus::Passed, 85, 0, 1],
        ];
    }

    #[DataProvider('conductRatings')]
    public function test_a_conduct_area_rates_merits_and_demerits_from_the_base(int $merits, int $demerits, float $rating, AreaStatus $status, float $base, float $meritValue, float $demeritValue): void
    {
        $area = self::area(PerformanceSource::Conduct, 75, baseRating: $base, meritValue: $meritValue, demeritValue: $demeritValue);
        $result = $this->engine->conductResult($area, $merits, $demerits);

        $this->assertSame($rating, $result->grade);
        $this->assertSame($status, $result->status);
    }

    public function test_conduct_notes_give_the_points_and_the_limit(): void
    {
        $area = self::area(PerformanceSource::Conduct, 75, baseRating: 85, meritValue: 1, demeritValue: 1);

        $this->assertSame('1 merit point, 0 demerit points.', $this->engine->conductResult($area, 1, 0)->note);
        $this->assertSame('30 merit points, 2 demerit points. The rating is limited to 0–100.', $this->engine->conductResult($area, 30, 2)->note);
    }

    // ------------------------------------------------------------------
    // Attendance areas
    // ------------------------------------------------------------------

    /**
     * @return array{sessions: int, present: int, late: int, excused: int, absent: int, unrecorded: int, hours: float, rate: ?float}
     */
    private static function attendance(int $present, int $late, int $excused, int $absent, ?float $rate): array
    {
        return ['sessions' => $present + $late + $excused + $absent, 'present' => $present, 'late' => $late, 'excused' => $excused, 'absent' => $absent, 'unrecorded' => 0, 'hours' => 0.0, 'rate' => $rate];
    }

    public function test_an_attendance_area_grade_is_the_attendance_rate(): void
    {
        $area = self::area(PerformanceSource::Attendance, 90);

        $none = $this->engine->attendanceResult($area, null);
        $this->assertNull($none->grade);
        $this->assertSame(AreaStatus::NotYet, $none->status);

        $onlyExcused = $this->engine->attendanceResult($area, self::attendance(0, 0, 3, 0, null));
        $this->assertSame(AreaStatus::NotYet, $onlyExcused->status);
        $this->assertSame('No attendance counted yet.', $onlyExcused->note);

        $atPassing = $this->engine->attendanceResult($area, self::attendance(8, 1, 1, 1, 90.0));
        $this->assertSame(90.0, $atPassing->grade);
        $this->assertSame(AreaStatus::Passed, $atPassing->status);
        $this->assertSame('Attended 9 of 10 counted sessions. 1 excused.', $atPassing->note);

        $below = $this->engine->attendanceResult($area, self::attendance(7, 0, 0, 1, 87.5));
        $this->assertSame(87.5, $below->grade);
        $this->assertSame(AreaStatus::Failed, $below->status);
    }

    // ------------------------------------------------------------------
    // Overall score
    // ------------------------------------------------------------------

    public function test_the_overall_score_is_the_weighted_mean_of_the_area_grades(): void
    {
        // 40 × 80 + 20 × 90 + 20 × 70 + 10 × 85 + 10 × 95 = 8200 / 100
        $overall = $this->engine->overall([
            $this->areaResult(1, 80.0, AreaStatus::Passed, 40),
            $this->areaResult(2, 90.0, AreaStatus::Passed, 20),
            $this->areaResult(3, 70.0, AreaStatus::Failed, 20),
            $this->areaResult(4, 85.0, AreaStatus::Passed, 10),
            $this->areaResult(5, 95.0, AreaStatus::Passed, 10),
        ]);

        $this->assertSame(['score' => 82.0, 'complete' => true], $overall);
    }

    public function test_areas_without_a_grade_are_left_out_of_the_overall_score(): void
    {
        // (40 × 80 + 20 × 90) / 60 = 83.333…
        $overall = $this->engine->overall([
            $this->areaResult(1, 80.0, AreaStatus::Passed, 40),
            $this->areaResult(2, 90.0, AreaStatus::Passed, 20),
            $this->areaResult(3, null, AreaStatus::NotYet, 20),
        ]);

        $this->assertSame(['score' => 83.33, 'complete' => false], $overall);
    }

    public function test_the_overall_score_rounds_half_up_and_ignores_unweighted_areas(): void
    {
        // (50 × 80 + 50 × 75.01) / 100 = 77.505; the unweighted area without a grade does not make it partial.
        $overall = $this->engine->overall([
            $this->areaResult(1, 80.0, AreaStatus::Passed, 50),
            $this->areaResult(2, 75.01, AreaStatus::Passed, 50),
            $this->areaResult(3, 10.0, AreaStatus::Failed, 0),
            $this->areaResult(4, null, AreaStatus::NotYet, 0),
        ]);

        $this->assertSame(['score' => 77.51, 'complete' => true], $overall);

        // Weights do not have to add up to 100: (2.5 × 90 + 7.5 × 70) / 10 = 75.
        $this->assertSame(75.0, $this->engine->overall([$this->areaResult(1, 90.0, AreaStatus::Passed, 2.5), $this->areaResult(2, 70.0, AreaStatus::Failed, 7.5)])['score']);
    }

    public function test_there_is_no_overall_score_without_a_graded_weighted_area(): void
    {
        $this->assertSame(['score' => null, 'complete' => false], $this->engine->overall([]));
        $this->assertSame(['score' => null, 'complete' => false], $this->engine->overall([$this->areaResult(1, null, AreaStatus::NotYet, 40)]));
        $this->assertSame(['score' => null, 'complete' => false], $this->engine->overall([$this->areaResult(1, 90.0, AreaStatus::Passed, 0)]));
    }

    // ------------------------------------------------------------------
    // Qualification
    // ------------------------------------------------------------------

    public function test_a_failed_must_pass_area_makes_the_candidate_not_qualified_with_reasons_in_area_order(): void
    {
        $decision = $this->engine->qualify([
            $this->areaResult(1, 70.0, AreaStatus::Failed, name: 'Academic'),
            $this->areaResult(2, null, AreaStatus::NotYet, name: 'Physical Fitness'),
            $this->areaResult(3, 60.0, AreaStatus::Failed, mustPass: false, name: 'Leadership'),
            $this->areaResult(4, 70.0, AreaStatus::Failed, name: 'Conduct'),
            $this->areaResult(5, 95.0, AreaStatus::Passed, name: 'Attendance'),
        ]);

        $this->assertSame(QualificationStatus::NotQualified, $decision->status);
        $this->assertSame(['Academic requirement not met', 'Conduct requirement not met'], $decision->reasons);
        $this->assertSame(['Physical Fitness'], $decision->pending);
    }

    public function test_an_incomplete_or_unresulted_must_pass_area_keeps_the_decision_pending(): void
    {
        $decision = $this->engine->qualify([
            $this->areaResult(1, 80.0, AreaStatus::Incomplete, name: 'Academic'),
            $this->areaResult(2, null, AreaStatus::NotYet, name: 'Physical Fitness'),
            $this->areaResult(3, 60.0, AreaStatus::Failed, mustPass: false, name: 'Leadership'),
        ]);

        $this->assertSame(QualificationStatus::Pending, $decision->status);
        $this->assertSame([], $decision->reasons);
        $this->assertSame(['Academic', 'Physical Fitness'], $decision->pending);
    }

    public function test_passing_every_must_pass_area_qualifies_even_when_another_area_is_failed(): void
    {
        $decision = $this->engine->qualify([
            $this->areaResult(1, 80.0, AreaStatus::Passed, name: 'Academic'),
            $this->areaResult(2, null, AreaStatus::NotYet, mustPass: false, name: 'Leadership'),
            $this->areaResult(3, 50.0, AreaStatus::Failed, mustPass: false, name: 'Extra'),
        ]);

        $this->assertSame(QualificationStatus::Qualified, $decision->status);
        $this->assertSame([], $decision->reasons);
        $this->assertSame([], $decision->pending);
    }

    public function test_nobody_is_qualified_while_no_area_is_active(): void
    {
        $decision = $this->engine->qualify([]);

        $this->assertSame(QualificationStatus::Pending, $decision->status);
        $this->assertSame([], $decision->reasons);
    }

    // ------------------------------------------------------------------
    // Rank
    // ------------------------------------------------------------------

    public function test_ranks_are_shared_on_ties_and_skip_the_following_places(): void
    {
        $this->assertSame(
            ['a' => 1, 'b' => 2, 'c' => 2, 'd' => 4, 'e' => null, 'f' => 5],
            $this->engine->ranks(['a' => 90.0, 'b' => 85.0, 'c' => 85.0, 'd' => 80.5, 'e' => null, 'f' => 80.49]),
        );
        $this->assertSame([0 => 1, 1 => 1, 2 => 1], $this->engine->ranks([77.51, 77.51, 77.51]));
        $this->assertSame([0 => null, 1 => null], $this->engine->ranks([null, null]));
        $this->assertSame([], $this->engine->ranks([]));
    }

    public function test_rank_orders_candidates_and_never_ranks_withdrawn_or_unscored_candidates(): void
    {
        $make = fn (string $name, ?float $overall, CandidateStatus $status = CandidateStatus::Enrolled): CandidateQualification => new CandidateQualification(
            self::candidate($name, $status),
            [],
            $overall,
            $overall !== null,
            $this->engine->qualify([]),
        );

        $ranked = $this->engine->rank([
            $make('Alpha', null),
            $make('Bravo', 80.0),
            $make('Charlie', 92.0, CandidateStatus::Withdrawn),
            $make('Delta', 90.0),
            $make('Echo', 80.0),
        ]);

        $this->assertSame(['Delta', 'Bravo', 'Echo', 'Alpha', 'Charlie'], array_map(fn (CandidateQualification $entry): string => $entry->candidate->last_name, $ranked));
        $this->assertSame([1, 2, 2, null, null], array_map(fn (CandidateQualification $entry): ?int => $entry->rank, $ranked));
    }

    public function test_the_rank_is_sent_only_when_asked_for(): void
    {
        $qualification = (new CandidateQualification(self::candidate('007'), [], 80.0, true, $this->engine->qualify([])))->withRank(3);

        $this->assertArrayNotHasKey('rank', $qualification->toArray());
        $this->assertSame(3, $qualification->toArray(withRank: true)['rank']);
        $this->assertSame(['score' => 80.0, 'complete' => true], $qualification->toArray()['overall']);
        $this->assertSame('Candidate 007', $qualification->toArray()['candidate']['name']);
    }

    /** Every attribute the results read is set, so strict models never see a missing one. */
    private static function candidate(string $lastName, CandidateStatus $status = CandidateStatus::Enrolled): Candidate
    {
        $candidate = new Candidate;
        $candidate->forceFill([
            'id' => crc32($lastName),
            'candidate_number' => "C-{$lastName}",
            'first_name' => 'Candidate',
            'middle_name' => null,
            'last_name' => $lastName,
            'suffix' => null,
            'company' => null,
            'platoon' => null,
            'status' => $status,
        ]);

        return $candidate;
    }
}
