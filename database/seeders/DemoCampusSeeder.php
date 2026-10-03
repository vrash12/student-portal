<?php

namespace Database\Seeders;

use App\Enums\CampusCode;
use App\Enums\CandidateStatus;
use App\Enums\SystemRole;
use App\Models\AcademicPeriod;
use App\Models\Campus;
use App\Models\Candidate;
use App\Models\ClassBatch;
use App\Models\ClassSubject;
use App\Models\InstructorAssignment;
use App\Models\Role;
use App\Models\Subject;
use App\Models\User;
use App\Services\CandidateService;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Demo data on the other three campuses, for demonstrations of campus
 * scoping (owner requests 2026-10-03 and 2026-10-04: 20 candidates on every
 * campus). Each campus gets a class taking Subject 1 and Subject 2, an
 * instructor, an administrator limited to the campus and 20 candidates:
 *
 *   North Campus   Class B   north.admin, instructor3   north01 … north20
 *   East Campus    Class C   east.admin,  instructor4   east01 … east20
 *   West Campus    Class D   west.admin,  instructor5   west01 … west20
 *
 * (The South Campus has Class A with student01 … student20, from
 * ClientDemoSeeder.) Names are fictional Filipino names; DemoPeopleSeeder
 * draws their pictures. Every account uses ClientDemoSeeder::PASSWORD and is
 * not asked to change it. Run by ClientDemoSeeder before the grading,
 * fitness and expense seeders, so these classes have results too. Only
 * missing records are added: safe to run again on an existing demo
 * database. Never runs in production.
 */
class DemoCampusSeeder extends Seeder
{
    /** Candidates in the class of every campus. */
    public const PER_CAMPUS = 20;

    /**
     * The demo set of each campus besides the South Campus: class, number
     * prefix, administrator and instructor (username => fictional name).
     *
     * @var array<string, array{class: string, prefix: string, admin: array{0: string, 1: string}, instructor: array{0: string, 1: string}}>
     */
    public const CAMPUSES = [
        'NORTH' => ['class' => 'Class B', 'prefix' => 'north', 'admin' => ['north.admin', 'Rosario T. Valdez'], 'instructor' => ['instructor3', 'Dennis R. Aquino']],
        'EAST' => ['class' => 'Class C', 'prefix' => 'east', 'admin' => ['east.admin', 'Marilou C. Fajardo'], 'instructor' => ['instructor4', 'Edgardo B. Lucero']],
        'WEST' => ['class' => 'Class D', 'prefix' => 'west', 'admin' => ['west.admin', 'Josefina L. Marquez'], 'instructor' => ['instructor5', 'Rolando V. Samson']],
    ];

    /**
     * The first North Campus candidates (2026-10-03), named by hand:
     * number => [first, middle, last, female]. The others are composed by
     * candidates() from the name lists below.
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3: bool}>
     */
    public const CANDIDATES = [
        'north01' => ['Joshua', 'Reyes', 'Lacson', false],
        'north02' => ['Angeline', 'Santos', 'Ilagan', true],
        'north03' => ['Mark Joseph', 'Cruz', 'Valencia', false],
        'north04' => ['Rhea Mae', 'Domingo', 'Pangilinan', true],
        'north05' => ['Carlo', 'Mendoza', 'Bautista', false],
    ];

    private const MALE_NAMES = [
        'Adrian', 'Benedict', 'Dominic', 'Emmanuel', 'Francis', 'Gabriel', 'Harold', 'Ivan', 'Jericho', 'Kenneth',
        'Lorenzo', 'Miguel', 'Nathaniel', 'Oliver', 'Patrick', 'Renz', 'Samuel', 'Tristan', 'Vincent', 'Wilfredo',
    ];

    private const FEMALE_NAMES = [
        'Abigail', 'Bea', 'Clarisse', 'Danica', 'Ella Mae', 'Faith', 'Gwen', 'Hannah Joy', 'Isabel', 'Janine',
        'Kimberly', 'Lea', 'Mikaela', 'Nicole Ann', 'Patricia', 'Queenie', 'Regine', 'Shaira', 'Trisha', 'Venus',
    ];

    private const SURNAMES = [
        'Abad', 'Belmonte', 'Cortez', 'Dimaculangan', 'Esguerra', 'Galang', 'Hernandez', 'Javier', 'Lim', 'Magbanua',
        'Natividad', 'Ong', 'Panganiban', 'Quiambao', 'Robles', 'Samonte', 'Tagle', 'Umali', 'Velasco', 'Yap',
        'Alcantara', 'Buenaventura', 'Concepcion', 'De Leon', 'Evangelista', 'Fajardo', 'Gatchalian', 'Ilagan', 'Lacsamana', 'Manalang',
    ];

    /**
     * Every demo candidate of the North, East and West campuses:
     * number => [first, middle, last, female]. Composed the same way every
     * time, so the names never change between runs; no two are alike.
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: bool}>
     */
    public static function candidates(): array
    {
        $candidates = [];
        foreach (array_values(self::CAMPUSES) as $campusIndex => $set) {
            for ($seat = 1; $seat <= self::PER_CAMPUS; $seat++) {
                $number = $set['prefix'].str_pad((string) $seat, 2, '0', STR_PAD_LEFT);
                $candidates[$number] = self::CANDIDATES[$number] ?? self::composedName($campusIndex * self::PER_CAMPUS + $seat - 1, $seat % 2 === 0);
            }
        }

        return $candidates;
    }

    /**
     * One of the four campuses (owner decision 2026-10-04). The migrations
     * create them; this only recreates one that is missing.
     */
    public static function campus(CampusCode $code): Campus
    {
        return Campus::query()->where('code', $code->value)->first()
            ?? Campus::query()->create(['name' => $code->label(), 'code' => $code->value, 'address' => null]);
    }

    /** The campus the main demo data (Class A) belongs to. */
    public static function southCampus(): Campus
    {
        return self::campus(CampusCode::South);
    }

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('Demo campus data must not be seeded in production.');
        }

        $period = AcademicPeriod::query()->active()->first();
        if ($period === null) {
            return;
        }

        $names = self::candidates();
        foreach (self::CAMPUSES as $code => $set) {
            $campus = self::campus(CampusCode::from($code));

            $this->account($set['admin'][0], $set['admin'][1], SystemRole::SuperAdministrator, $campus);
            $instructor = $this->account($set['instructor'][0], $set['instructor'][1], SystemRole::Instructor, $campus);

            $class = ClassBatch::query()->where('academic_period_id', $period->id)->where('campus_id', $campus->id)->where('name', $set['class'])->first();
            if ($class === null) {
                $class = new ClassBatch(['name' => $set['class']]);
                $class->academicPeriod()->associate($period);
                $class->campus()->associate($campus);
                $class->save();
            }

            foreach ([1, 2] as $number) {
                $subject = Subject::query()->firstOrCreate(['code' => "SUBJ-{$number}"], ['name' => "Subject {$number}", 'description' => null]);
                $this->offering($class, $subject, $instructor);
            }

            $prefix = $set['prefix'];
            $this->enrol($class, array_filter($names, fn (string $number): bool => str_starts_with($number, $prefix), ARRAY_FILTER_USE_KEY));
        }
    }

    /** @return array{0: string, 1: string, 2: string, 3: bool} */
    private static function composedName(int $index, bool $female): array
    {
        $first = $female ? self::FEMALE_NAMES[intdiv($index, 2) % count(self::FEMALE_NAMES)] : self::MALE_NAMES[intdiv($index, 2) % count(self::MALE_NAMES)];
        $last = self::SURNAMES[($index * 7) % count(self::SURNAMES)];
        $middle = self::SURNAMES[($index * 11 + 4) % count(self::SURNAMES)];
        if ($middle === $last) {
            $middle = self::SURNAMES[($index * 11 + 5) % count(self::SURNAMES)];
        }

        return [$first, $middle, $last, $female];
    }

    private function account(string $username, string $name, SystemRole $role, Campus $campus): User
    {
        $user = User::query()->where('username', $username)->first() ?? new User(['username' => $username, 'email' => null]);
        $user->fill(['name' => $name, 'password' => ClientDemoSeeder::PASSWORD]);
        $user->role()->associate(Role::query()->where('code', $role->value)->firstOrFail());
        $user->campus()->associate($campus);
        $user->is_active = true;
        $user->password_change_required = false;
        $user->save();

        return $user;
    }

    private function offering(ClassBatch $class, Subject $subject, User $instructor): void
    {
        $offering = ClassSubject::query()->where('class_batch_id', $class->id)->where('subject_id', $subject->id)->first();
        if ($offering === null) {
            $offering = new ClassSubject;
            $offering->classBatch()->associate($class);
            $offering->subject()->associate($subject);
            $offering->save();
        }

        if (! InstructorAssignment::query()->where('class_subject_id', $offering->id)->where('instructor_id', $instructor->id)->exists()) {
            $assignment = new InstructorAssignment;
            $assignment->classSubject()->associate($offering);
            $assignment->instructor()->associate($instructor);
            $assignment->save();
        }
    }

    /**
     * @param  array<string, array{0: string, 1: string, 2: string, 3: bool}>  $names
     */
    private function enrol(ClassBatch $class, array $names): void
    {
        $role = Role::query()->where('code', SystemRole::Candidate->value)->firstOrFail();

        foreach ($names as $number => [$first, $middle, $last]) {
            if (Candidate::query()->where('candidate_number', $number)->exists()) {
                continue;
            }

            $account = new User([
                'name' => "{$first} {$middle} {$last}",
                'username' => CandidateService::usernameFor($number),
                'email' => null,
                'password' => ClientDemoSeeder::PASSWORD,
            ]);
            $account->role()->associate($role);
            $account->is_active = true;
            $account->password_change_required = false;
            $account->save();

            // The campus follows the class (Candidate's saving hook).
            $candidate = new Candidate(['candidate_number' => $number, 'first_name' => $first, 'middle_name' => $middle, 'last_name' => $last]);
            $candidate->user()->associate($account);
            $candidate->classBatch()->associate($class);
            $candidate->status = CandidateStatus::Enrolled;
            $candidate->save();
        }
    }
}
