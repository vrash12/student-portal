<?php

namespace Tests\Feature\Performance;

use App\Enums\AttendanceStatus;
use App\Enums\CandidateStatus;
use App\Enums\SystemRole;
use App\Models\AcademicPeriod;
use App\Models\Assessment;
use App\Models\Candidate;
use App\Models\ClassBatch;
use App\Models\ClassSubject;
use App\Models\ConductType;
use App\Models\FitnessEvent;
use App\Models\FitnessTest;
use App\Models\PerformanceArea;
use App\Models\Subject;
use App\Models\User;
use App\Services\Attendance\AttendanceService;
use App\Services\ClassBatchService;
use App\Services\Conduct\ConductService;
use App\Services\Fitness\FitnessStandardService;
use App\Services\Fitness\FitnessTestService;
use App\Services\Grading\AssessmentService;
use App\Services\Grading\GradingSchemeService;
use App\Services\Grading\ScoreRecordingService;
use App\Services\Performance\CandidateQualification;
use App\Services\Performance\PerformanceAreaService;
use App\Services\Performance\QualificationEngine;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Qualification and class rank (owner request, 2026-10-01), from real
 * records of every source. Placeholder areas, in order:
 *
 *   Academic          subjects: Subject 1   weight 40  pass 75  must pass
 *   Military Skills   subjects: Subject 2   weight 20  pass 75  must pass
 *   Physical Fitness  fitness               weight 20  pass 60  must pass
 *   Conduct           conduct (85, 1, 1)    weight 10  pass 75  must pass
 *   Attendance        attendance            weight 10  pass 90  must pass
 *
 * Class A (active period):
 *
 *               Subject 1  Subject 2         Fitness        Conduct      Attendance
 *   C-001 Alpha    90      85                50 reps = 80   +3 = 88      present, late = 100
 *   C-002 Bravo    70      80                30 reps = 45   −15 = 70     absent, present = 50
 *   C-003 Charlie  80      90, one missing   not tested     85           present, excused = 100
 *   C-004 Delta    withdrawn
 *
 *   Alpha:   Qualified,      overall (3600 + 1700 + 1600 + 880 + 1000) / 100 = 87.80, rank 1
 *   Charlie: Pending,        overall (3200 + 1800 + 850 + 1000) / 80 = 85.625 → 85.63, rank 2
 *   Bravo:   Not Qualified,  overall (2800 + 1600 + 900 + 700 + 500) / 100 = 65.00, rank 3
 */
class QualificationTest extends TestCase
{
    private User $admin;

    private ClassBatch $classA;

    private Subject $subject1;

    private Subject $subject2;

    private Candidate $alpha;

    private Candidate $bravo;

    private Candidate $charlie;

    private Candidate $delta;

    private FitnessEvent $pushUps;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-01 09:00:00');

        $this->admin = $this->userWithRole(SystemRole::AcademicAdministrator);
        $period = AcademicPeriod::factory()->active()->create(['name' => 'Period Current']);
        $past = AcademicPeriod::factory()->create(['name' => 'Period Past', 'starts_on' => '2026-01-05', 'ends_on' => '2026-05-29']);
        $this->classA = ClassBatch::factory()->for($period)->create(['name' => 'Class A']);
        ClassBatch::factory()->for($period)->create(['name' => 'Class B']);
        ClassBatch::factory()->for($past)->create(['name' => 'Class Old']);

        $this->subject1 = Subject::factory()->create(['code' => 'SUBJ-1', 'name' => 'Subject 1']);
        $this->subject2 = Subject::factory()->create(['code' => 'SUBJ-2', 'name' => 'Subject 2']);
        $this->createAreas();

        $this->alpha = $this->candidate($this->classA, 'C-001', 'Alpha', 'Alpha Company', '1st Platoon');
        $this->bravo = $this->candidate($this->classA, 'C-002', 'Bravo', 'Alpha Company', '2nd Platoon');
        $this->charlie = $this->candidate($this->classA, 'C-003', 'Charlie', 'Bravo Company', '1st Platoon');
        $this->delta = $this->candidate($this->classA, 'C-004', 'Delta', 'Bravo Company', '2nd Platoon');
        $this->delta->forceFill(['status' => CandidateStatus::Withdrawn->value])->save();

        $this->pushUps = $this->app->make(FitnessStandardService::class)->create([
            'name' => 'Push-ups', 'description' => null, 'unit' => 'repetitions', 'higher_is_better' => true,
            'passing_value' => 40, 'maximum_value' => 60, 'sort_order' => 1,
        ]);

        // Subject 1: one examination. Subject 2: two drills, Charlie has no score on the second.
        $offering1 = $this->offering($this->classA, $this->subject1);
        $this->finalizedAssessment($offering1, 'Examination 1', [$this->alpha->id => '90', $this->bravo->id => '70', $this->charlie->id => '80']);
        $offering2 = $this->offering($this->classA, $this->subject2);
        $this->finalizedAssessment($offering2, 'Drill 1', [$this->alpha->id => '85', $this->bravo->id => '80', $this->charlie->id => '90']);
        $this->finalizedAssessment($offering2, 'Drill 2', [$this->alpha->id => '85', $this->bravo->id => '80']);

        // Fitness: an older test (Charlie passed), the latest test with results, and a newer test without results.
        $this->fitnessTest($this->classA, 'Fitness Test 1', '2026-09-01', [$this->charlie->id => 55]);
        $this->fitnessTest($this->classA, 'Fitness Test 2', '2026-09-20', [$this->alpha->id => 50, $this->bravo->id => 30]);
        $this->fitnessTest($this->classA, 'Fitness Test 3', '2026-09-28', []);

        // Conduct: Alpha 3 merit points; Bravo 15 demerit points (one entry voided).
        $conduct = $this->app->make(ConductService::class);
        $leadership = ConductType::query()->where('name', 'Leadership commendation')->sole();
        $violation = ConductType::query()->where('name', 'Violation of regulations')->sole();
        $conduct->record($this->alpha, ['conduct_type_id' => $leadership->id, 'points' => 3, 'occurred_on' => '2026-09-15', 'reason' => 'Led the field exercise'], $this->admin);
        foreach (range(1, 4) as $day) {
            $entry = $conduct->record($this->bravo, ['conduct_type_id' => $violation->id, 'points' => 5, 'occurred_on' => "2026-09-1{$day}", 'reason' => 'Violation'], $this->admin);
        }
        $conduct->void($entry, 'Recorded twice', $this->admin);

        // Attendance: two sessions.
        $this->attendance($this->classA, '2026-09-10', [
            $this->alpha->id => AttendanceStatus::Present, $this->bravo->id => AttendanceStatus::Absent, $this->charlie->id => AttendanceStatus::Present,
        ]);
        $this->attendance($this->classA, '2026-09-17', [
            $this->alpha->id => AttendanceStatus::Late, $this->bravo->id => AttendanceStatus::Present, $this->charlie->id => AttendanceStatus::Excused,
        ]);
    }

    private function createAreas(): void
    {
        $service = $this->app->make(PerformanceAreaService::class);
        $area = fn (string $name, string $source, string $weight, string $passing, int $order, array $extra = []): array => [
            'name' => $name, 'description' => null, 'source' => $source, 'weight' => $weight, 'passing_grade' => $passing,
            'must_pass' => true, 'base_rating' => null, 'merit_value' => null, 'demerit_value' => null, 'sort_order' => $order,
            'is_active' => true, ...$extra,
        ];

        $service->create($area('Academic', 'subjects', '40', '75', 1), [$this->subject1->id]);
        $service->create($area('Military Skills', 'subjects', '20', '75', 2), [$this->subject2->id]);
        $service->create($area('Physical Fitness', 'fitness', '20', '60', 3));
        $service->create($area('Conduct', 'conduct', '10', '75', 4, ['base_rating' => '85', 'merit_value' => '1', 'demerit_value' => '1']));
        $service->create($area('Attendance', 'attendance', '10', '90', 5));
    }

    private function candidate(ClassBatch $class, string $number, string $lastName, ?string $company = null, ?string $platoon = null): Candidate
    {
        return Candidate::factory()->create([
            'class_batch_id' => $class->id,
            'candidate_number' => $number,
            'first_name' => 'Candidate',
            'last_name' => $lastName,
            'company' => $company,
            'platoon' => $platoon,
        ]);
    }

    private function offering(ClassBatch $class, Subject $subject): ClassSubject
    {
        $offering = $this->app->make(ClassBatchService::class)->addSubject($class, $subject);
        $this->app->make(GradingSchemeService::class)->save($offering, [['id' => null, 'name' => 'Examinations', 'weight' => '100']], null);

        return $offering;
    }

    /**
     * @param  array<int, string>  $scores  candidate id => score out of 100
     */
    private function finalizedAssessment(ClassSubject $offering, string $title, array $scores): Assessment
    {
        $assessments = $this->app->make(AssessmentService::class);
        $assessment = $assessments->create($offering, [
            'title' => $title,
            'assessment_category_id' => $offering->assessmentCategories()->sole()->id,
            'max_score' => '100',
            'assessed_on' => null,
        ], $this->admin);

        $this->app->make(ScoreRecordingService::class)->recordDraftScores($assessment, array_map(
            fn (string $score): array => ['score' => $score, 'comment' => null, 'expected_score' => null, 'expected_comment' => null],
            $scores,
        ), $this->admin);
        $assessments->finalize($assessment, $this->admin);

        return $assessment;
    }

    /**
     * @param  array<int, float>  $repetitions  candidate id => push-ups
     */
    private function fitnessTest(ClassBatch $class, string $title, string $date, array $repetitions): FitnessTest
    {
        $service = $this->app->make(FitnessTestService::class);
        $test = $service->create($class, ['title' => $title, 'tested_on' => $date, 'notes' => null], [$this->pushUps->id], $this->admin);
        $eventId = $test->events()->sole()->id;
        if ($repetitions !== []) {
            $service->recordResults($test, array_map(fn (float $value): array => [$eventId => $value], $repetitions), $this->admin);
        }

        return $test;
    }

    /**
     * @param  array<int, AttendanceStatus>  $statuses
     */
    private function attendance(ClassBatch $class, string $date, array $statuses): void
    {
        $service = $this->app->make(AttendanceService::class);
        $session = $service->create($class, ['held_on' => $date, 'title' => "Session {$date}", 'hours' => '2', 'notes' => null], $this->admin);
        $service->record($session, array_map(fn (AttendanceStatus $status): array => ['status' => $status, 'remarks' => null], $statuses), $this->admin);
    }

    /**
     * @param  list<CandidateQualification>  $results
     * @return array<string, CandidateQualification> by last name
     */
    private static function byName(array $results): array
    {
        $byName = [];
        foreach ($results as $result) {
            $byName[$result->candidate->last_name] = $result;
        }

        return $byName;
    }

    /**
     * @return list<array{float|null, string}> grade and status of each area
     */
    private static function areaSummary(CandidateQualification $qualification): array
    {
        return array_map(fn ($result): array => [$result->grade, $result->status->value], $qualification->areas);
    }

    public function test_the_engine_combines_every_source_and_ranks_the_class(): void
    {
        $results = $this->app->make(QualificationEngine::class)->forClass($this->classA);

        // Withdrawn candidates are left out; the list is in rank order.
        $this->assertSame(['Alpha', 'Charlie', 'Bravo'], array_map(fn (CandidateQualification $result): string => $result->candidate->last_name, $results));
        $this->assertSame([1, 2, 3], array_map(fn (CandidateQualification $result): ?int => $result->rank, $results));
        ['Alpha' => $alpha, 'Bravo' => $bravo, 'Charlie' => $charlie] = self::byName($results);

        $this->assertSame([[90.0, 'passed'], [85.0, 'passed'], [80.0, 'passed'], [88.0, 'passed'], [100.0, 'passed']], self::areaSummary($alpha));
        $this->assertSame(87.8, $alpha->overall);
        $this->assertTrue($alpha->overallComplete);
        $this->assertSame('qualified', $alpha->qualification->status->value);

        $this->assertSame([[70.0, 'failed'], [80.0, 'passed'], [45.0, 'failed'], [70.0, 'failed'], [50.0, 'failed']], self::areaSummary($bravo));
        $this->assertSame(65.0, $bravo->overall);
        $this->assertSame('not_qualified', $bravo->qualification->status->value);
        $this->assertSame([
            'Academic requirement not met',
            'Physical Fitness requirement not met',
            'Conduct requirement not met',
            'Attendance requirement not met',
        ], $bravo->qualification->reasons);

        // The latest test with results is Fitness Test 2, which Charlie did not take.
        $this->assertSame([[80.0, 'passed'], [90.0, 'incomplete'], [null, 'not_yet'], [85.0, 'passed'], [100.0, 'passed']], self::areaSummary($charlie));
        $this->assertSame('Scores are missing in Subject 2.', $charlie->areas[1]->note);
        $this->assertSame('Not tested in Fitness Test 2.', $charlie->areas[2]->note);
        $this->assertSame('Attended 1 of 1 counted sessions. 1 excused.', $charlie->areas[4]->note);
        $this->assertSame(85.63, $charlie->overall);
        $this->assertFalse($charlie->overallComplete);
        $this->assertSame('pending', $charlie->qualification->status->value);
        $this->assertSame(['Military Skills', 'Physical Fitness'], $charlie->qualification->pending);
    }

    public function test_a_single_candidate_gets_the_same_results_with_or_without_rank(): void
    {
        $engine = $this->app->make(QualificationEngine::class);

        $ranked = $engine->forCandidate($this->charlie);
        $this->assertSame(2, $ranked->rank);
        $this->assertSame(85.63, $ranked->overall);

        $unranked = $engine->forCandidate($this->charlie->fresh(), withRank: false);
        $this->assertNull($unranked->rank);
        $this->assertSame(self::areaSummary($ranked), self::areaSummary($unranked));
        $this->assertSame($ranked->qualification->toArray(), $unranked->qualification->toArray());

        // Withdrawn: results, never a rank. Without a class: nothing.
        $delta = $engine->forCandidate($this->delta->fresh());
        $this->assertNotNull($delta);
        $this->assertNull($delta->rank);
        $this->assertNull($engine->forCandidate(Candidate::factory()->create()));
    }

    public function test_changes_to_the_records_and_areas_show_immediately(): void
    {
        $engine = $this->app->make(QualificationEngine::class);

        // Deactivating Attendance and making Conduct optional changes Bravo's reasons.
        $attendance = PerformanceArea::query()->where('name', 'Attendance')->sole();
        $attendance->forceFill(['is_active' => false])->save();
        PerformanceArea::query()->where('name', 'Conduct')->update(['must_pass' => false]);

        $bravo = self::byName($engine->forClass($this->classA))['Bravo'];
        $this->assertCount(4, $bravo->areas);
        $this->assertSame(['Academic requirement not met', 'Physical Fitness requirement not met'], $bravo->qualification->reasons);
        // (2800 + 1600 + 900 + 700) / 90 = 66.666…
        $this->assertSame(66.67, $bravo->overall);
    }

    public function test_the_qualification_page_lists_the_class_with_rank_and_summary(): void
    {
        $this->actingAs($this->admin)->get('/qualification')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('staff/qualification/index')
                // The first class of the active period by default.
                ->where('filters', ['class' => (string) $this->classA->id, 'company' => '', 'platoon' => '', 'status' => ''])
                ->where('classBatch', ['id' => $this->classA->id, 'name' => 'Class A', 'period' => 'Period Current', 'isActivePeriod' => true])
                ->where('classOptions.0.period', 'Period Current')
                ->where('companyOptions', ['Alpha Company', 'Bravo Company'])
                ->where('platoonOptions', ['1st Platoon', '2nd Platoon'])
                ->has('areas', 5)
                ->where('areas.2', [
                    'id' => PerformanceArea::query()->where('name', 'Physical Fitness')->value('id'),
                    'name' => 'Physical Fitness',
                    'description' => null,
                    'source' => ['value' => 'fitness', 'label' => 'Military Fitness'],
                    // Whole numbers arrive as integers in the page JSON.
                    'weight' => 20,
                    'passingGrade' => 60,
                    'mustPass' => true,
                    'conductRule' => null,
                ])
                ->where('areas.3.conductRule', ['baseRating' => 85, 'meritValue' => 1, 'demeritValue' => 1])
                ->has('rows', 3)
                ->where('rows.0.rank', 1)
                ->where('rows.0.candidate.candidateNumber', 'C-001')
                ->where('rows.0.candidate.company', 'Alpha Company')
                ->where('rows.0.overall', ['score' => 87.8, 'complete' => true])
                ->where('rows.0.qualification.status.label', 'Qualified')
                ->where('rows.2.candidate.candidateNumber', 'C-002')
                ->where('rows.2.qualification.status.label', 'Not Qualified')
                ->where('rows.2.qualification.reasons.0', 'Academic requirement not met')
                ->where('rows.2.areas.2', [
                    'areaId' => PerformanceArea::query()->where('name', 'Physical Fitness')->value('id'),
                    'grade' => 45,
                    'status' => ['value' => 'failed', 'label' => 'Failed', 'tone' => 'danger'],
                    'note' => 'Fitness Test 2: an event standard was not met.',
                ])
                ->where('rows.1.areas.2.status.label', 'No results yet')
                ->where('counts', ['total' => 3, 'qualified' => 1, 'notQualified' => 1, 'pending' => 1])
                ->where('areaCounts.0', [
                    'areaId' => PerformanceArea::query()->where('name', 'Academic')->value('id'),
                    'name' => 'Academic',
                    'mustPass' => true,
                    'passed' => 2,
                    'failed' => 1,
                    'incomplete' => 0,
                    'notYet' => 0,
                    'passRate' => 66.67,
                ])
                ->where('classSize', 3)
                ->where('can', ['configure' => true, 'viewCandidates' => true]));
    }

    public function test_company_platoon_and_status_filters_keep_the_class_rank(): void
    {
        $this->actingAs($this->admin)->get('/qualification?company=Alpha+Company')
            ->assertInertia(fn (Assert $page) => $page
                ->has('rows', 2)
                ->where('rows.0.candidate.candidateNumber', 'C-001')
                ->where('rows.0.rank', 1)
                ->where('rows.1.candidate.candidateNumber', 'C-002')
                ->where('rows.1.rank', 3)
                ->where('counts', ['total' => 2, 'qualified' => 1, 'notQualified' => 1, 'pending' => 0])
                ->where('classSize', 3));

        $this->actingAs($this->admin)->get('/qualification?platoon=1st+Platoon')
            ->assertInertia(fn (Assert $page) => $page->has('rows', 2)->where('rows.1.candidate.candidateNumber', 'C-003'));

        // The status filter narrows the list but not the summary.
        $this->actingAs($this->admin)->get('/qualification?status=pending')
            ->assertInertia(fn (Assert $page) => $page
                ->has('rows', 1)
                ->where('rows.0.candidate.candidateNumber', 'C-003')
                ->where('counts.total', 3));

        // Unknown values are ignored.
        $this->actingAs($this->admin)->get('/qualification?class=999999&company=Nobody&platoon[]=x&status=maybe')
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters', ['class' => (string) $this->classA->id, 'company' => '', 'platoon' => '', 'status' => ''])
                ->has('rows', 3));
    }

    public function test_another_class_can_be_chosen_and_an_empty_class_shows_no_rows(): void
    {
        $classOld = ClassBatch::query()->where('name', 'Class Old')->sole();

        $this->actingAs($this->admin)->get("/qualification?class={$classOld->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.class', (string) $classOld->id)
                ->where('classBatch.isActivePeriod', false)
                ->where('rows', [])
                ->where('counts', ['total' => 0, 'qualified' => 0, 'notQualified' => 0, 'pending' => 0])
                ->where('companyOptions', []));
    }

    public function test_without_active_areas_nobody_is_qualified(): void
    {
        PerformanceArea::query()->update(['is_active' => false]);

        $this->actingAs($this->admin)->get('/qualification')
            ->assertInertia(fn (Assert $page) => $page
                ->where('areas', [])
                ->has('rows', 3)
                ->where('rows.0.qualification.status.value', 'pending')
                ->where('rows.0.rank', null)
                ->where('counts.pending', 3));
    }

    public function test_the_calculation_runs_a_fixed_number_of_queries_however_large_the_class(): void
    {
        $engine = $this->app->make(QualificationEngine::class);
        $small = $this->populatedClass('Class Small', 'S', 4);
        $large = $this->populatedClass('Class Large', 'L', 20);

        $count = function (ClassBatch $class) use ($engine): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $results = $engine->forClass($class);
            DB::disableQueryLog();
            $this->assertNotEmpty($results);

            return count(DB::getQueryLog());
        };

        $smallQueries = $count($small);
        $largeQueries = $count($large);

        $this->assertSame($smallQueries, $largeQueries);
        $this->assertLessThanOrEqual(25, $largeQueries);
    }

    /**
     * A class whose candidates all have grades, a fitness result, conduct
     * entries and attendance.
     */
    private function populatedClass(string $name, string $prefix, int $size): ClassBatch
    {
        $class = ClassBatch::factory()->for(AcademicPeriod::query()->where('is_active', true)->sole())->create(['name' => $name]);
        $candidates = collect(range(1, $size))->map(fn (int $index): Candidate => $this->candidate($class, "{$prefix}-{$index}", "{$name} {$index}"));
        $ids = $candidates->pluck('id')->all();

        $this->finalizedAssessment($this->offering($class, $this->subject1), "{$name} Examination", array_fill_keys($ids, '80'));
        $this->finalizedAssessment($this->offering($class, $this->subject2), "{$name} Drill", array_fill_keys($ids, '85'));
        $this->fitnessTest($class, "{$name} Fitness", '2026-09-15', array_fill_keys($ids, 45.0));
        $merit = ConductType::query()->where('name', 'Exemplary conduct')->sole();
        foreach ($candidates as $candidate) {
            $this->app->make(ConductService::class)->record($candidate, ['conduct_type_id' => $merit->id, 'points' => 2, 'occurred_on' => '2026-09-15', 'reason' => 'Exemplary'], $this->admin);
        }
        $this->attendance($class, '2026-09-12', array_fill_keys($ids, AttendanceStatus::Present));

        return $class;
    }

    public function test_instructors_and_candidates_cannot_see_qualification(): void
    {
        $instructor = $this->userWithRole(SystemRole::Instructor);

        foreach ([$instructor, $this->alpha->user] as $user) {
            $this->actingAs($user)->get('/qualification')->assertForbidden();
            $this->actingAs($user)->get("/qualification?class={$this->classA->id}")->assertForbidden();
        }
    }

    public function test_the_class_is_saved_as_a_pdf_with_the_same_filters(): void
    {
        $pdf = $this->actingAs($this->admin)->get("/qualification/pdf?class={$this->classA->id}&status=qualified")->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')->assertHeader('Cache-Control', 'no-store, private');
        $this->assertStringStartsWith('%PDF-', (string) $pdf->getContent());
        $this->assertStringStartsWith('attachment; filename="qualification-', (string) $pdf->headers->get('Content-Disposition'));

        foreach ([$this->userWithRole(SystemRole::Instructor), $this->alpha->user] as $user) {
            $this->actingAs($user)->get('/qualification/pdf')->assertForbidden();
        }
    }
}
