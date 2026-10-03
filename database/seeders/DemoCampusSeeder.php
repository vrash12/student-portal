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
 * Demo data on a second campus for demonstrations of campus scoping (owner
 * request, 2026-10-03): the North Campus with Class B, an instructor, five
 * candidates and an administrator limited to the campus:
 *
 *   north.admin          Admin, North Campus only
 *   instructor3          Instructor, North Campus (Subject 1 and Subject 2)
 *   north01 … north05    Candidates in Class B (North Campus)
 *
 * Every account uses ClientDemoSeeder::PASSWORD and is not asked to change
 * it. Run by ClientDemoSeeder before the grading, fitness and expense
 * seeders, so the campus has results too. Idempotent. Never runs in
 * production.
 */
class DemoCampusSeeder extends Seeder
{
    /**
     * Fictional candidates: number => [first, middle, last, female]
     * (DemoPeopleSeeder draws their pictures).
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

        $campus = self::campus(CampusCode::North);

        $this->account('north.admin', 'Rosario T. Valdez', SystemRole::SuperAdministrator, $campus);
        $instructor = $this->account('instructor3', 'Dennis R. Aquino', SystemRole::Instructor, $campus);

        $class = ClassBatch::query()->where('academic_period_id', $period->id)->where('campus_id', $campus->id)->where('name', 'Class B')->first();
        if ($class === null) {
            $class = new ClassBatch(['name' => 'Class B']);
            $class->academicPeriod()->associate($period);
            $class->campus()->associate($campus);
            $class->save();
        }

        foreach ([1, 2] as $number) {
            $subject = Subject::query()->firstOrCreate(['code' => "SUBJ-{$number}"], ['name' => "Subject {$number}", 'description' => null]);
            $this->offering($class, $subject, $instructor);
        }

        $this->candidates($class);
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

    private function candidates(ClassBatch $class): void
    {
        $role = Role::query()->where('code', SystemRole::Candidate->value)->firstOrFail();

        foreach (self::CANDIDATES as $number => [$first, $middle, $last]) {
            if (Candidate::query()->where('candidate_number', $number)->exists()) {
                continue;
            }

            $account = new User([
                'name' => "{$first} {$last}",
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
