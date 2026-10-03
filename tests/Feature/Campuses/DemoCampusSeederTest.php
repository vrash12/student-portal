<?php

namespace Tests\Feature\Campuses;

use App\Enums\CampusCode;
use App\Enums\CandidateStatus;
use App\Enums\SystemRole;
use App\Models\AcademicPeriod;
use App\Models\Candidate;
use App\Models\ClassBatch;
use App\Models\InstructorAssignment;
use App\Models\User;
use Database\Factories\CampusFactory;
use Database\Seeders\DemoCampusSeeder;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The demo set of the North, East and West campuses (owner requests
 * 2026-10-03 and 2026-10-04): on each, a class of 20 candidates, an
 * instructor teaching it and an administrator limited to the campus.
 */
class DemoCampusSeederTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        AcademicPeriod::factory()->active()->create();
        $this->seed(DemoCampusSeeder::class);
    }

    public function test_every_other_campus_gets_a_class_of_twenty_candidates(): void
    {
        foreach (DemoCampusSeeder::CAMPUSES as $code => $set) {
            $campus = CampusFactory::fixed(CampusCode::from($code));
            $class = ClassBatch::query()->where('campus_id', $campus->id)->sole();
            $this->assertSame($set['class'], $class->name);

            $candidates = Candidate::query()->with('user')->where('class_batch_id', $class->id)->orderBy('candidate_number')->get();
            $this->assertCount(DemoCampusSeeder::PER_CAMPUS, $candidates);
            $this->assertSame($set['prefix'].'01', $candidates->first()->candidate_number);
            $this->assertSame($set['prefix'].'20', $candidates->last()->candidate_number);
            $this->assertTrue($candidates->every(fn (Candidate $candidate): bool => $candidate->campus_id === $campus->id
                && $candidate->status === CandidateStatus::Enrolled
                && $candidate->user->username === $candidate->candidate_number
                && $candidate->user->is_active));

            // The instructor teaches both subjects of the class, on the same campus.
            $instructor = User::query()->where('username', $set['instructor'][0])->sole();
            $this->assertSame([$campus->id, SystemRole::Instructor->value], [$instructor->campus_id, $instructor->role->code]);
            $this->assertSame(2, InstructorAssignment::query()->where('instructor_id', $instructor->id)->count());

            // The administrator sees only the campus's candidates.
            $admin = User::query()->where('username', $set['admin'][0])->sole();
            $this->assertSame($campus->id, $admin->campus_id);
            $this->actingAs($admin)->get('/candidates')
                ->assertInertia(fn (Assert $page) => $page->where('candidates.total', DemoCampusSeeder::PER_CAMPUS));
        }

        // The first North Campus candidates keep their names; no two candidates share a name.
        $this->assertSame('Joshua Reyes Lacson', Candidate::query()->where('candidate_number', 'north01')->sole()->full_name);
        $names = Candidate::query()->get()->map(fn (Candidate $candidate): string => $candidate->full_name);
        $this->assertCount(60, $names);
        $this->assertSame(60, $names->unique()->count());
    }

    public function test_names_are_fictional_filipino_names_composed_the_same_way_every_time(): void
    {
        $names = DemoCampusSeeder::candidates();

        $this->assertCount(60, $names);
        $this->assertSame(DemoCampusSeeder::CANDIDATES['north05'], $names['north05']);
        $this->assertSame($names, DemoCampusSeeder::candidates());
        $this->assertSame(['east01', 'east20', 'west01', 'west20'], array_values(array_intersect(['east01', 'east20', 'west01', 'west20'], array_keys($names))));
        // Every second seat is a woman (her picture is drawn accordingly).
        $this->assertTrue($names['west02'][3]);
        $this->assertFalse($names['west03'][3]);
    }

    public function test_running_again_adds_nothing(): void
    {
        $counts = fn (): array => [Candidate::query()->count(), User::query()->count(), ClassBatch::query()->count(), InstructorAssignment::query()->count()];
        $before = $counts();

        $this->seed(DemoCampusSeeder::class);

        $this->assertSame($before, $counts());
        $this->assertSame([60, 66, 3, 6], $before);
    }
}
