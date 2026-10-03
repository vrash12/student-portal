<?php

namespace Tests\Feature\Performance;

use App\Models\Candidate;
use App\Models\Examination;
use App\Models\ExaminationAttempt;
use App\Models\User;
use Database\Seeders\ClientDemoSeeder;
use Database\Seeders\DemoActivitySeeder;
use Database\Seeders\DemoPeopleSeeder;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The client demo set with fictional Filipino names, illustrated profile
 * pictures and a completed diagnostic quiz per subject (2026-10-02).
 */
class DemoPeopleAndActivitySeederTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(ClientDemoSeeder::class);
    }

    public function test_demo_accounts_get_fictional_filipino_names_and_keep_their_usernames(): void
    {
        $first = Candidate::query()->with('user')->where('candidate_number', 'student01')->sole();
        $this->assertSame('Mark Anthony Dizon Villanueva', $first->full_name);
        $this->assertSame('student01', $first->user->username);
        $this->assertSame($first->full_name, $first->user->name);
        $this->assertSame('Noel Bautista Francisco Jr.', Candidate::query()->where('candidate_number', 'student19')->sole()->full_name);
        $this->assertSame(0, Candidate::query()->where('first_name', 'Student')->count());

        $staff = User::query()->whereIn('username', array_keys(DemoPeopleSeeder::STAFF))->pluck('name', 'username')->all();
        ksort($staff);
        $expected = DemoPeopleSeeder::STAFF;
        ksort($expected);
        $this->assertSame($expected, $staff);
        $this->assertSame('Ramon S. Estrada', User::query()->where('username', 'instructor1')->value('name'));
    }

    public function test_every_demo_candidate_has_an_illustrated_picture(): void
    {
        // 20 on every campus: student01 … student20 (South), north01 … north20,
        // east01 … east20 and west01 … west20.
        $candidates = Candidate::query()->with('campus')->get();
        $this->assertCount(80, $candidates);
        $this->assertSame(['EAST' => 20, 'NORTH' => 20, 'SOUTH' => 20, 'WEST' => 20], $candidates->countBy(fn (Candidate $candidate): string => $candidate->campus->code)->sortKeys()->all());

        foreach ($candidates as $candidate) {
            $this->assertNotNull($candidate->profile_photo_path);
            Storage::disk('local')->assertExists($candidate->profile_photo_path);
            $size = getimagesizefromstring(Storage::disk('local')->get($candidate->profile_photo_path));
            $this->assertSame([480, 480, 'image/png'], [$size[0], $size[1], $size['mime']]);
        }

        // The picture is served to staff through the authorized photo route.
        $this->actingAs(User::query()->where('username', 'admin')->sole())
            ->get('/candidates/'.$candidates->first()->id.'/photo')
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');
    }

    public function test_each_subject_has_a_completed_diagnostic_quiz_with_graded_results(): void
    {
        $quizzes = Examination::query()->where('title', 'like', 'Diagnostic Quiz — %')->get();
        $this->assertCount(2, $quizzes);

        foreach ($quizzes as $quiz) {
            $this->assertTrue($quiz->release_results);
            $this->assertTrue($quiz->closes_at->isPast());
            $this->assertSame(8, $quiz->examinationQuestions()->count());

            $attempts = ExaminationAttempt::query()->where('examination_id', $quiz->id)->get();
            // Two candidates were absent.
            $this->assertCount(18, $attempts);
            $this->assertTrue($attempts->every(fn (ExaminationAttempt $attempt): bool => $attempt->status === 'submitted' && $attempt->result_status === 'graded'));
            $this->assertGreaterThan(1, $attempts->pluck('percentage')->unique()->count());
            $this->assertTrue($attempts->every(fn (ExaminationAttempt $attempt): bool => $attempt->submitted_at->between($quiz->opens_at, $quiz->closes_at)));
        }
    }

    public function test_running_again_changes_nothing_and_keeps_edited_names_and_uploaded_photos(): void
    {
        $candidate = Candidate::query()->with('user')->where('candidate_number', 'student03')->sole();
        $candidate->forceFill(['first_name' => 'Edited', 'profile_photo_path' => 'candidate-photos/uploaded.jpg'])->save();
        $attempts = ExaminationAttempt::query()->count();

        $this->seed([DemoActivitySeeder::class, DemoPeopleSeeder::class]);

        $this->assertSame($attempts, ExaminationAttempt::query()->count());
        $this->assertSame(2, Examination::query()->where('title', 'like', 'Diagnostic Quiz — %')->count());
        $candidate->refresh();
        $this->assertSame('Edited', $candidate->first_name);
        $this->assertSame('candidate-photos/uploaded.jpg', $candidate->profile_photo_path);
    }
}
