<?php

namespace Database\Seeders;

use App\Enums\AttendanceStatus;
use App\Enums\PerformanceSource;
use App\Enums\SystemRole;
use App\Models\AttendanceSession;
use App\Models\Candidate;
use App\Models\ClassBatch;
use App\Models\ConductEntry;
use App\Models\ConductType;
use App\Models\FitnessEvent;
use App\Models\FitnessTest;
use App\Models\PerformanceArea;
use App\Models\Subject;
use App\Models\User;
use App\Services\Attendance\AttendanceService;
use App\Services\CandidateService;
use App\Services\Conduct\ConductService;
use App\Services\Fitness\FitnessTestService;
use App\Services\Performance\PerformanceAreaService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Demonstration data for merits/demerits, attendance, company/platoon and
 * the performance areas (owner request, 2026-10-01), on top of the client
 * demo set (ClientDemoSeeder calls it last):
 *
 * - company and platoon for student01 … student20: Alpha Company (01–10)
 *   and Bravo Company (11–20), 1st Platoon (first five of each company)
 *   and 2nd Platoon (the next five);
 * - the five PLACEHOLDER areas of the contract (Academic: Subject 1,
 *   Military Skills: Subject 2, Physical Fitness, Conduct, Attendance),
 *   to be replaced by the official grading SOP of the institution;
 * - for the students' class: a spread of merits and demerits, six
 *   attendance sessions with varied statuses and a "Midterm Fitness Test"
 *   (after DemoFitnessSeeder's diagnostic test; the latest test with
 *   results is the one that counts), so the qualification page shows
 *   Qualified, Not Qualified (for different reasons) and Pending candidates.
 *
 * With the client demo grades and the placeholder areas the outcome is:
 * Qualified 02, 03, 06, 11, 13, 17; Pending 01 (Subject 2 has a missing
 * score), 12 and 18 (run of the midterm test not taken yet); Not Qualified
 * 07 (conduct), 08 (attendance), 16 (fitness); 04, 05, 10, 15, 19, 20
 * (academic and military skills, from the demo grades), 09 (also fitness)
 * and 14 (also attendance).
 *
 * Everything goes through the services, so it is audited like real work.
 * Synthetic data only. Safe to run again: company/platoon already set,
 * areas that exist by name, conduct already recorded, a class that already
 * has sessions and an existing midterm test are left alone. Never runs in
 * production.
 */
class DemoPerformanceSeeder extends Seeder
{
    private const STUDENTS = 20;

    /**
     * Placeholder areas: [name, source, weight, passing grade, subject code or null, conduct rule or null].
     *
     * @var list<array{0: string, 1: PerformanceSource, 2: string, 3: string, 4: string|null, 5: array{0: string, 1: string, 2: string}|null}>
     */
    private const AREAS = [
        ['Academic', PerformanceSource::Subjects, '40', '75', 'SUBJ-1', null],
        ['Military Skills', PerformanceSource::Subjects, '20', '75', 'SUBJ-2', null],
        ['Physical Fitness', PerformanceSource::Fitness, '20', '60', null, null],
        ['Conduct', PerformanceSource::Conduct, '10', '75', null, ['85', '1', '1']],
        ['Attendance', PerformanceSource::Attendance, '10', '90', null, null],
    ];

    /**
     * Merits and demerits by seat (1 = student01): [type name, points, days after the period start, reason].
     * Seat 7 collects enough demerits to fail the placeholder conduct rule (85 + merits − demerits ≥ 75).
     *
     * @var array<int, list<array{0: string, 1: int, 2: int, 3: string}>>
     */
    private const CONDUCT = [
        1 => [['Outstanding performance', 5, 20, 'Top of the class in the land navigation exercise.'], ['Leadership commendation', 3, 41, 'Led the platoon during the field exercise.']],
        2 => [['Exemplary conduct', 2, 15, 'Assisted a classmate during the road march.']],
        3 => [['Late for formation', 2, 9, 'Late for the morning formation.']],
        5 => [['Improper uniform', 1, 12, 'Unpolished boots at inspection.'], ['Exemplary conduct', 2, 33, 'Kept the barracks in exemplary order.']],
        7 => [
            ['Violation of regulations', 5, 10, 'Left the training area without permission.'],
            ['Violation of regulations', 5, 24, 'Use of a mobile phone during a lecture.'],
            ['Late for formation', 2, 31, 'Late for the evening formation.'],
            ['Improper uniform', 1, 38, 'Incomplete uniform at inspection.'],
        ],
        9 => [['Leadership commendation', 3, 27, 'Acted as squad leader during drills.']],
        11 => [['Late for formation', 2, 18, 'Late for the morning formation.'], ['Late for formation', 2, 25, 'Late for the morning formation.']],
        12 => [['Outstanding performance', 5, 36, 'Highest score in the marksmanship practice.']],
        14 => [['Improper uniform', 1, 22, 'Name tag missing at inspection.']],
        16 => [['Exemplary conduct', 2, 29, 'Volunteered for the community outreach.']],
        18 => [['Violation of regulations', 5, 16, 'Unauthorized absence from study hours.'], ['Late for formation', 2, 39, 'Late for the morning formation.']],
        20 => [['Leadership commendation', 3, 34, 'Organized the class review session.']],
    ];

    /**
     * Attendance sessions: [title, hours, days after the period start].
     *
     * @var list<array{0: string, 1: string, 2: int}>
     */
    private const SESSIONS = [
        ['Orientation and Formation', '2', 7],
        ['Drill and Ceremonies', '3', 14],
        ['Field Training Exercise', '4', 21],
        ['Physical Training', '2', 28],
        ['Leadership Seminar', '3', 35],
        ['Map Reading Practical', '2.5', 42],
    ];

    /**
     * Statuses other than Present, by seat and session number (1–6). One
     * absence out of six falls below the placeholder minimum of 90.
     *
     * @var array<int, array<int, AttendanceStatus>>
     */
    private const ATTENDANCE = [
        2 => [3 => AttendanceStatus::Late],
        4 => [2 => AttendanceStatus::Excused],
        6 => [5 => AttendanceStatus::Late, 6 => AttendanceStatus::Late],
        8 => [4 => AttendanceStatus::Absent],
        10 => [1 => AttendanceStatus::Excused, 6 => AttendanceStatus::Late],
        13 => [3 => AttendanceStatus::Late, 5 => AttendanceStatus::Excused],
        14 => [3 => AttendanceStatus::Absent],
        15 => [2 => AttendanceStatus::Late],
        17 => [4 => AttendanceStatus::Excused],
        19 => [6 => AttendanceStatus::Late],
    ];

    private const FITNESS_TEST = 'Midterm Fitness Test';

    /** DemoFitnessSeeder's sample events, in the order of the results below. */
    private const FITNESS_EVENTS = ['Push-ups (2 minutes)', 'Sit-ups (2 minutes)', '3.2 km Run'];

    /** Seats short of the push-up standard (40) in the midterm test: they fail it. */
    private const FITNESS_FAILED = [9 => 33.0, 16 => 35.0];

    /** Seats whose run is not recorded yet: their midterm result is incomplete. */
    private const FITNESS_PENDING = [12, 18];

    /** Remarks recorded with some statuses. */
    private const REMARKS = [
        'excused' => 'Medical appointment (excused by the tactical officer).',
        'absent' => 'No notice given.',
    ];

    public function __construct(
        private readonly CandidateService $candidates,
        private readonly PerformanceAreaService $areas,
        private readonly ConductService $conduct,
        private readonly AttendanceService $attendance,
        private readonly FitnessTestService $fitness,
    ) {}

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('Demo performance data must not be seeded in production.');
        }

        $students = $this->students();
        $this->companiesAndPlatoons($students);
        $this->performanceAreas();

        $actor = $this->administrator();
        $class = $students->first()?->classBatch;
        if ($actor === null || $class === null) {
            return;
        }

        $inClass = $students->filter(fn (Candidate $student): bool => $student->isGradableIn($class->id));
        $this->conductEntries($inClass, $class, $actor);
        $this->attendanceSessions($inClass, $class, $actor);
        $this->midtermFitnessTest($inClass, $class, $actor);
    }

    /**
     * student01 … student20 by seat (1 = student01), with their class.
     *
     * @return Collection<int, Candidate>
     */
    private function students(): Collection
    {
        $numbers = array_map(fn (int $seat): string => 'student'.str_pad((string) $seat, 2, '0', STR_PAD_LEFT), range(1, self::STUDENTS));

        return Candidate::query()
            ->whereIn('candidate_number', $numbers)
            ->with('classBatch.academicPeriod')
            ->get()
            ->keyBy(fn (Candidate $student): int => (int) substr($student->candidate_number, strlen('student')))
            ->sortKeys();
    }

    /**
     * @param  Collection<int, Candidate>  $students  by seat
     */
    private function companiesAndPlatoons(Collection $students): void
    {
        foreach ($students as $seat => $student) {
            // Assignments an administrator already made are kept.
            if ($student->company !== null || $student->platoon !== null) {
                continue;
            }

            $company = $seat <= 10 ? 'Alpha Company' : 'Bravo Company';
            $platoon = ($seat - 1) % 10 < 5 ? '1st Platoon' : '2nd Platoon';
            $this->candidates->assignCompanyAndPlatoon($student, $company, $platoon);
        }
    }

    private function performanceAreas(): void
    {
        foreach (self::AREAS as $order => [$name, $source, $weight, $passing, $subjectCode, $conductRule]) {
            if (PerformanceArea::query()->where('name', $name)->exists()) {
                continue;
            }
            // Only one active fitness, conduct or attendance area: an existing one is kept.
            if ($this->areas->activeAreaUsing($source) !== null) {
                continue;
            }

            $subjectIds = $subjectCode === null ? [] : Subject::query()->where('code', $subjectCode)->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
            $this->areas->create([
                'name' => $name,
                'description' => 'Placeholder until the official grading SOP is provided.',
                'source' => $source->value,
                'weight' => $weight,
                'passing_grade' => $passing,
                'must_pass' => true,
                'base_rating' => $conductRule[0] ?? null,
                'merit_value' => $conductRule[1] ?? null,
                'demerit_value' => $conductRule[2] ?? null,
                'sort_order' => $order + 1,
                'is_active' => true,
            ], $subjectIds);
        }
    }

    /**
     * @param  Collection<int, Candidate>  $students  by seat, current members of $class
     */
    private function conductEntries(Collection $students, ClassBatch $class, User $actor): void
    {
        if ($students->isEmpty() || ConductEntry::query()->whereIn('candidate_id', $students->modelKeys())->exists()) {
            return;
        }

        $types = ConductType::query()->active()->pluck('id', 'name');
        foreach (self::CONDUCT as $seat => $entries) {
            $student = $students->get($seat);
            if ($student === null) {
                continue;
            }

            foreach ($entries as [$type, $points, $days, $reason]) {
                if (! isset($types[$type])) {
                    continue;
                }

                $this->conduct->record($student, [
                    'conduct_type_id' => (int) $types[$type],
                    'points' => $points,
                    'occurred_on' => $this->dayOf($class, $days),
                    'reason' => $reason,
                ], $actor);
            }
        }
    }

    /**
     * @param  Collection<int, Candidate>  $students  by seat, current members of $class
     */
    private function attendanceSessions(Collection $students, ClassBatch $class, User $actor): void
    {
        if ($students->isEmpty() || AttendanceSession::query()->where('class_batch_id', $class->id)->exists()) {
            return;
        }

        foreach (self::SESSIONS as $index => [$title, $hours, $days]) {
            $number = $index + 1;
            $session = $this->attendance->create($class, [
                'held_on' => $this->dayOf($class, $days),
                'title' => $title,
                'hours' => $hours,
                'notes' => null,
            ], $actor);

            $entries = [];
            foreach ($students as $seat => $student) {
                $status = self::ATTENDANCE[$seat][$number] ?? AttendanceStatus::Present;
                $entries[$student->id] = ['status' => $status, 'remarks' => self::REMARKS[$status->value] ?? null];
            }
            $this->attendance->record($session, $entries, $actor);
        }
    }

    /**
     * A second test with synthetic results, newer than the diagnostic one.
     * Skipped without the sample events or when the class already has it.
     *
     * @param  Collection<int, Candidate>  $students  by seat, current members of $class
     */
    private function midtermFitnessTest(Collection $students, ClassBatch $class, User $actor): void
    {
        $events = FitnessEvent::query()->active()->whereIn('name', self::FITNESS_EVENTS)->get()->keyBy('name');
        if ($students->isEmpty() || $events->count() !== count(self::FITNESS_EVENTS)
            || FitnessTest::query()->where('class_batch_id', $class->id)->where('title', self::FITNESS_TEST)->exists()) {
            return;
        }

        $test = $this->fitness->create($class, [
            'title' => self::FITNESS_TEST,
            'tested_on' => $this->dayOf($class, 49),
            'notes' => 'Synthetic demonstration results.',
        ], $events->pluck('id')->all(), $actor);
        $testEvents = $test->events()->get()->keyBy('fitness_event_id');
        [$pushUps, $sitUps, $run] = array_map(fn (string $name): int => $testEvents[$events[$name]->id]->id, self::FITNESS_EVENTS);

        $entries = [];
        foreach ($students as $seat => $student) {
            $entries[$student->id] = [
                // Deterministic results that meet every standard, except the seats listed above.
                $pushUps => self::FITNESS_FAILED[$seat] ?? (float) (44 + ($seat * 5) % 24),
                $sitUps => (float) (48 + ($seat * 3) % 25),
                $run => in_array($seat, self::FITNESS_PENDING, true) ? null : (float) (760 + ($seat * 23) % 180),
            ];
        }
        $this->fitness->recordResults($test, $entries, $actor);
    }

    /** A date in the class's period, never later than today (records describe what has happened). */
    private function dayOf(ClassBatch $class, int $daysAfterStart): string
    {
        $date = CarbonImmutable::parse($class->academicPeriod->starts_on->toDateString())->addDays($daysAfterStart);
        $today = CarbonImmutable::parse(now()->timezone((string) config('institution.timezone'))->toDateString());

        return ($date->greaterThan($today) ? $today : $date)->toDateString();
    }

    /** The administrator who records (`admin` in the demo set, else any Super Administrator). */
    private function administrator(): ?User
    {
        return User::query()->where('username', 'admin')->first()
            ?? User::query()->whereHas('role', fn ($role) => $role->where('code', SystemRole::SuperAdministrator->value))->orderBy('id')->first();
    }
}
