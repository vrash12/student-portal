<?php

namespace Database\Seeders;

use App\Enums\CandidateStatus;
use App\Enums\SystemRole;
use App\Models\AcademicPeriod;
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
 * Synthetic academic structure for local development: one active period,
 * generic subjects, two sample batches, instructor assignments, and ten
 * candidates with sign-in accounts, all on the South Campus. Idempotent.
 * Never runs in production.
 *
 * Requires DemoAccountsSeeder (instructor accounts) to have run first.
 */
class DemoAcademicSeeder extends Seeder
{
    private const CANDIDATES_PER_CLASS = 5;

    /**
     * Instructor username => [class name => subject numbers].
     */
    private const ASSIGNMENTS = [
        'instructor.alpha' => ['Sample Batch A' => [1, 2], 'Sample Batch B' => [1]],
        'instructor.bravo' => ['Sample Batch A' => [3, 4], 'Sample Batch B' => [2, 3, 4]],
    ];

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('Demo academic data must not be seeded in production.');
        }

        $period = $this->activePeriod();
        $subjects = $this->subjects();

        $classes = [];
        foreach (['Sample Batch A', 'Sample Batch B'] as $className) {
            $classes[$className] = $this->classBatch($period, $className);

            foreach ($subjects as $subject) {
                $this->offering($classes[$className], $subject);
            }
        }

        foreach (self::ASSIGNMENTS as $username => $classSubjects) {
            $instructor = User::query()->where('username', $username)->first();
            if ($instructor === null) {
                continue;
            }

            foreach ($classSubjects as $className => $subjectNumbers) {
                foreach ($subjectNumbers as $number) {
                    $this->assign($this->offering($classes[$className], $subjects[$number]), $instructor);
                }
            }
        }

        $this->candidates(array_values($classes));
    }

    private function activePeriod(): AcademicPeriod
    {
        $period = AcademicPeriod::query()->firstOrCreate(
            ['name' => 'Academic Period 2026-1'],
            ['starts_on' => '2026-08-03', 'ends_on' => '2026-12-18'],
        );

        if (! AcademicPeriod::query()->active()->exists()) {
            $period->forceFill(['is_active' => true])->save();
        }

        return $period;
    }

    /**
     * @return array<int, Subject>
     */
    private function subjects(): array
    {
        $subjects = [];
        foreach (range(1, 4) as $number) {
            $subjects[$number] = Subject::query()->firstOrCreate(
                ['code' => "SUBJ-{$number}"],
                ['name' => "Subject {$number}", 'description' => null],
            );
        }

        return $subjects;
    }

    private function classBatch(AcademicPeriod $period, string $name): ClassBatch
    {
        $existing = ClassBatch::query()->where('academic_period_id', $period->id)->where('name', $name)->first();
        if ($existing !== null) {
            return $existing;
        }

        $classBatch = new ClassBatch(['name' => $name]);
        $classBatch->academicPeriod()->associate($period);
        $classBatch->campus()->associate(DemoCampusSeeder::southCampus());
        $classBatch->save();

        return $classBatch;
    }

    private function offering(ClassBatch $classBatch, Subject $subject): ClassSubject
    {
        $existing = ClassSubject::query()->where('class_batch_id', $classBatch->id)->where('subject_id', $subject->id)->first();
        if ($existing !== null) {
            return $existing;
        }

        $offering = new ClassSubject;
        $offering->classBatch()->associate($classBatch);
        $offering->subject()->associate($subject);
        $offering->save();

        return $offering;
    }

    private function assign(ClassSubject $offering, User $instructor): void
    {
        $exists = InstructorAssignment::query()
            ->where('class_subject_id', $offering->id)
            ->where('instructor_id', $instructor->id)
            ->exists();

        if ($exists) {
            return;
        }

        $assignment = new InstructorAssignment;
        $assignment->classSubject()->associate($offering);
        $assignment->instructor()->associate($instructor);
        $assignment->save();
    }

    /**
     * @param  list<ClassBatch>  $classes
     */
    private function candidates(array $classes): void
    {
        $role = Role::query()->where('code', SystemRole::Candidate->value)->firstOrFail();
        $password = (string) config('demo.account_password');
        $number = 0;

        foreach ($classes as $classBatch) {
            for ($seat = 1; $seat <= self::CANDIDATES_PER_CLASS; $seat++) {
                $number++;
                $padded = str_pad((string) $number, 3, '0', STR_PAD_LEFT);
                $candidateNumber = '2026-0'.$padded;

                if (Candidate::query()->where('candidate_number', $candidateNumber)->exists()) {
                    continue;
                }

                $account = new User([
                    'name' => "Candidate {$padded}",
                    'username' => CandidateService::usernameFor($candidateNumber),
                    'email' => null,
                    'password' => $password,
                ]);
                $account->role()->associate($role);
                $account->is_active = true;
                $account->save();

                $candidate = new Candidate([
                    'candidate_number' => $candidateNumber,
                    'first_name' => 'Candidate',
                    'last_name' => $padded,
                ]);
                $candidate->user()->associate($account);
                $candidate->classBatch()->associate($classBatch);
                $candidate->status = CandidateStatus::Enrolled;
                $candidate->save();
            }
        }
    }
}
