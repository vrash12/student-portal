<?php

namespace Tests\Feature\Teaching;

use App\Enums\SystemRole;
use App\Models\Candidate;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InstructorCandidateAccessTest extends TestCase
{
    use BuildsTeachingFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildTeachingFixtures();
    }

    public function test_instructor_opens_a_candidate_in_a_class_they_teach_without_account_details(): void
    {
        $this->actingAs($this->alpha)
            ->get("/candidates/{$this->candidateInA->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('staff/candidates/show')
                ->where('candidate.account', null)
                ->where('canEdit', false)
                ->where('canBrowseCandidates', false)
                ->where('candidate.classBatch.name', 'Sample Batch A'));
    }

    public function test_instructor_sees_only_the_subjects_they_teach_on_a_candidate_profile(): void
    {
        // Batch A takes Subject 1 (Alpha) and Subject 2 (Bravo).
        $this->actingAs($this->alpha)
            ->get("/candidates/{$this->candidateInA->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('subjects', [['code' => 'SUBJ-1', 'name' => 'Subject 1', 'instructors' => ['Instructor Alpha']]]));

        $this->actingAs($this->userWithRole(SystemRole::AcademicAdministrator))
            ->get("/candidates/{$this->candidateInA->id}")
            ->assertInertia(fn (Assert $page) => $page->has('subjects', 2));
    }

    public function test_instructor_cannot_open_candidates_outside_their_classes(): void
    {
        $unassigned = Candidate::factory()->create(['class_batch_id' => null]);

        $this->actingAs($this->alpha)->get("/candidates/{$this->candidateInB->id}")->assertForbidden();
        $this->actingAs($this->alpha)->get("/candidates/{$unassigned->id}")->assertForbidden();
    }

    public function test_instructor_cannot_browse_or_change_candidate_records(): void
    {
        $this->actingAs($this->alpha)->get('/candidates')->assertForbidden();
        $this->actingAs($this->alpha)->get("/candidates/{$this->candidateInA->id}/edit")->assertForbidden();
        $this->actingAs($this->alpha)
            ->put("/candidates/{$this->candidateInA->id}", [
                'candidate_number' => $this->candidateInA->candidate_number,
                'first_name' => 'Changed',
                'last_name' => $this->candidateInA->last_name,
                'class_batch_id' => $this->batchA->id,
                'status' => 'enrolled',
                'account_active' => true,
            ])
            ->assertForbidden();

        $this->assertNotSame('Changed', $this->candidateInA->fresh()->first_name);
    }

    public function test_deactivated_instructors_lose_access(): void
    {
        $this->alpha->forceFill(['is_active' => false])->save();

        $this->actingAs($this->alpha)
            ->get("/candidates/{$this->candidateInA->id}")
            ->assertRedirect(route('login'));
    }

    public function test_administrators_still_see_account_details(): void
    {
        $this->actingAs($this->userWithRole(SystemRole::AcademicAdministrator))
            ->get("/candidates/{$this->candidateInB->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('candidate.account.username', $this->candidateInB->user->username)
                ->where('canEdit', true)
                ->where('canBrowseCandidates', true));
    }
}
