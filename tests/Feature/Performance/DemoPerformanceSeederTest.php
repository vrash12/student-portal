<?php

namespace Tests\Feature\Performance;

use App\Enums\AuditAction;
use App\Enums\QualificationStatus;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\ClassBatch;
use App\Models\ConductEntry;
use App\Models\FitnessTest;
use App\Models\PerformanceArea;
use App\Models\Subject;
use App\Models\User;
use App\Services\CandidateService;
use App\Services\Performance\CandidateQualification;
use App\Services\Performance\QualificationEngine;
use Database\Seeders\ClientDemoSeeder;
use Database\Seeders\DemoPerformanceSeeder;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * DemoPerformanceSeeder on top of the client demo set: companies and
 * platoons, the placeholder areas, merits/demerits, attendance and a
 * midterm fitness test, written through the services (audited), giving a
 * varied qualification outcome, and created once however often it runs.
 */
class DemoPerformanceSeederTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The client demo set stores generated question images.
        Storage::fake('local');
        $this->travelTo('2026-10-01 09:00:00');
    }

    public function test_the_client_demo_set_gets_units_areas_conduct_attendance_and_a_varied_outcome(): void
    {
        $this->seed(ClientDemoSeeder::class);

        // Companies and platoons of Class A, audited as candidate changes (the North Campus has none).
        $units = Candidate::query()->where('candidate_number', 'like', 'student%')->orderBy('candidate_number')->get()
            ->mapWithKeys(fn (Candidate $candidate): array => [$candidate->candidate_number => "{$candidate->company} / {$candidate->platoon}"]);
        $this->assertCount(20, $units);
        $this->assertSame('Alpha Company / 1st Platoon', $units['student01']);
        $this->assertSame('Alpha Company / 1st Platoon', $units['student05']);
        $this->assertSame('Alpha Company / 2nd Platoon', $units['student06']);
        $this->assertSame('Bravo Company / 1st Platoon', $units['student11']);
        $this->assertSame('Bravo Company / 2nd Platoon', $units['student20']);
        $this->assertSame(20, AuditLog::query()->where('action', AuditAction::CandidateUpdated->value)->count());

        // The placeholder areas of the contract, in order, all must pass.
        $areas = PerformanceArea::query()->ordered()->get();
        $this->assertSame(['Academic', 'Military Skills', 'Physical Fitness', 'Conduct', 'Attendance'], $areas->pluck('name')->all());
        $this->assertSame(['subjects', 'subjects', 'fitness', 'conduct', 'attendance'], $areas->map(fn (PerformanceArea $area): string => $area->source->value)->all());
        $this->assertSame(['40.00', '20.00', '20.00', '10.00', '10.00'], $areas->pluck('weight')->map(fn (mixed $weight): string => (string) $weight)->all());
        $this->assertSame(['75.00', '75.00', '60.00', '75.00', '90.00'], $areas->pluck('passing_grade')->map(fn (mixed $grade): string => (string) $grade)->all());
        $this->assertTrue($areas->every(fn (PerformanceArea $area): bool => $area->must_pass && $area->is_active));
        $conduct = $areas->firstWhere('name', 'Conduct');
        $this->assertSame(['85.00', '1.00', '1.00'], [(string) $conduct->base_rating, (string) $conduct->merit_value, (string) $conduct->demerit_value]);
        $this->assertSame('Academic', PerformanceArea::query()->whereKey(Subject::query()->where('code', 'SUBJ-1')->value('performance_area_id'))->value('name'));
        $this->assertSame('Military Skills', PerformanceArea::query()->whereKey(Subject::query()->where('code', 'SUBJ-2')->value('performance_area_id'))->value('name'));
        $this->assertSame(5, AuditLog::query()->where('action', AuditAction::PerformanceAreaCreated->value)->count());

        // Merits/demerits, six sessions with every student recorded, and the midterm fitness test, by the administrator.
        $admin = User::query()->where('username', 'admin')->sole();
        $this->assertSame(19, ConductEntry::query()->count());
        $this->assertSame([$admin->id], ConductEntry::query()->distinct()->pluck('recorded_by')->all());
        $this->assertSame(19, AuditLog::query()->where('action', AuditAction::ConductEntryRecorded->value)->count());
        $this->assertSame(6, AttendanceSession::query()->count());
        $this->assertSame(120, AttendanceRecord::query()->count());
        $this->assertSame(6, AuditLog::query()->where('action', AuditAction::AttendanceRecorded->value)->count());
        $fitnessTests = fn (string $class): array => FitnessTest::query()->whereHas('classBatch', fn ($classes) => $classes->where('name', $class))->orderBy('tested_on')->pluck('title')->all();
        $this->assertSame(['Diagnostic Fitness Test', 'Midterm Fitness Test'], $fitnessTests('Class A'));
        // The classes of the other campuses have the diagnostic test only.
        foreach (['Class B', 'Class C', 'Class D'] as $class) {
            $this->assertSame(['Diagnostic Fitness Test'], $fitnessTests($class));
        }

        // Records describe what has happened: no date after today.
        $this->assertFalse(ConductEntry::query()->where('occurred_on', '>', '2026-10-01')->exists());
        $this->assertFalse(AttendanceSession::query()->where('held_on', '>', '2026-10-01')->exists());

        // A varied outcome: Qualified, Pending, and Not Qualified for different reasons.
        $results = collect(app(QualificationEngine::class)->forClass(ClassBatch::query()->where('name', 'Class A')->sole()))
            ->keyBy(fn (CandidateQualification $qualification): string => $qualification->candidate->candidate_number);
        $status = fn (QualificationStatus $status): array => $results
            ->filter(fn (CandidateQualification $qualification): bool => $qualification->qualification->status === $status)
            ->keys()->sort()->values()->all();

        $this->assertSame(['student02', 'student03', 'student06', 'student11', 'student13', 'student17'], $status(QualificationStatus::Qualified));
        $this->assertSame(['student01', 'student12', 'student18'], $status(QualificationStatus::Pending));
        $this->assertCount(11, $status(QualificationStatus::NotQualified));
        $this->assertSame(['Conduct requirement not met'], $results['student07']->qualification->reasons);
        $this->assertSame(['Attendance requirement not met'], $results['student08']->qualification->reasons);
        $this->assertSame(['Physical Fitness requirement not met'], $results['student16']->qualification->reasons);
        $this->assertSame(['Academic requirement not met', 'Military Skills requirement not met'], $results['student05']->qualification->reasons);
        $this->assertSame(['Military Skills'], $results['student01']->qualification->pending);
        $this->assertSame(['Physical Fitness'], $results['student12']->qualification->pending);
        $this->assertSame(1, $results['student06']->rank);
    }

    public function test_running_again_creates_nothing_and_keeps_changes_made_by_administrators(): void
    {
        $this->seed(ClientDemoSeeder::class);
        $first = Candidate::query()->where('candidate_number', 'student01')->sole();
        app(CandidateService::class)->assignCompanyAndPlatoon($first, 'Charlie Company', null);
        $counts = fn (): array => [
            PerformanceArea::query()->count(),
            ConductEntry::query()->count(),
            AttendanceSession::query()->count(),
            AttendanceRecord::query()->count(),
            FitnessTest::query()->count(),
            AuditLog::query()->count(),
        ];
        $before = $counts();

        $this->seed(DemoPerformanceSeeder::class);

        $this->assertSame($before, $counts());
        $this->assertSame(['Charlie Company', null], [$first->fresh()->company, $first->fresh()->platoon]);
    }

    public function test_demo_performance_data_is_never_seeded_in_production(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');

        $this->expectException(RuntimeException::class);

        $this->app->make(DemoPerformanceSeeder::class)->run();
    }
}
