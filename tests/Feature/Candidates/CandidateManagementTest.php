<?php

namespace Tests\Feature\Candidates;

use App\Enums\AuditAction;
use App\Enums\CandidateStatus;
use App\Enums\SystemRole;
use App\Models\AcademicPeriod;
use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\ClassBatch;
use App\Models\Subject;
use App\Models\User;
use App\Services\ClassBatchService;
use App\Services\InstructorAssignmentService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CandidateManagementTest extends TestCase
{
    private User $admin;

    private ClassBatch $classBatch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->userWithRole(SystemRole::AcademicAdministrator);
        $this->classBatch = ClassBatch::factory()->for(AcademicPeriod::factory()->active())->create(['name' => 'Sample Batch A']);
    }

    public function test_administrator_creates_a_candidate_with_a_sign_in_account(): void
    {
        $response = $this->actingAs($this->admin)->post('/candidates', $this->payload());

        $candidate = Candidate::query()->with('user.role')->where('candidate_number', 'OC-0214')->sole();
        $response->assertRedirect(route('candidates.show', $candidate));

        $this->assertSame('Juana', $candidate->first_name);
        $this->assertSame($this->classBatch->id, $candidate->class_batch_id);
        $this->assertSame(CandidateStatus::Enrolled, $candidate->status);
        $this->assertSame('oc-0214', $candidate->user->username);
        $this->assertSame('Juana Example', $candidate->user->name);
        $this->assertSame(SystemRole::Candidate->value, $candidate->user->role->code);
        $this->assertTrue(Hash::check('tablet-password-1', $candidate->user->password));

        $entry = AuditLog::query()->where('action', AuditAction::CandidateCreated->value)->sole();
        $this->assertSame('OC-0214', $entry->new_values['candidate_number']);
        $this->assertSame('Sample Batch A', $entry->new_values['class']);
        $this->assertArrayNotHasKey('password', $entry->new_values);
    }

    public function test_new_candidate_signs_in_with_the_candidate_number(): void
    {
        $this->actingAs($this->admin)->post('/candidates', $this->payload());
        $this->post('/logout');

        $this->post('/login', ['username' => 'OC-0214', 'password' => 'tablet-password-1']);

        $this->get(route('home'))->assertRedirect(route('portal.home'));
    }

    public function test_candidate_numbers_must_be_unique_and_not_clash_with_usernames(): void
    {
        Candidate::factory()->create(['candidate_number' => 'OC-0214']);
        $this->userWithRole(SystemRole::Instructor, ['username' => 'oc-0500']);

        $this->actingAs($this->admin)
            ->post('/candidates', $this->payload(['candidate_number' => 'oc-0214']))
            ->assertSessionHasErrors(['candidate_number' => 'This candidate number is already assigned to another candidate.']);

        $this->actingAs($this->admin)
            ->post('/candidates', $this->payload(['candidate_number' => 'OC-0500']))
            ->assertSessionHasErrors(['candidate_number' => 'This candidate number is already used as the username of another account.']);

        $this->actingAs($this->admin)
            ->post('/candidates', $this->payload(['candidate_number' => 'OC 0214/A']))
            ->assertSessionHasErrors(['candidate_number' => 'Use only letters, numbers, periods, hyphens, and underscores.']);
    }

    public function test_create_requires_names_and_a_valid_password(): void
    {
        $this->actingAs($this->admin)
            ->post('/candidates', $this->payload(['first_name' => '', 'last_name' => '', 'password' => 'short', 'password_confirmation' => 'short']))
            ->assertSessionHasErrors(['first_name', 'last_name', 'password']);

        $this->assertSame(0, Candidate::query()->count());
        $this->assertDatabaseMissing('users', ['username' => 'oc-0214']);
    }

    public function test_index_searches_by_number_and_full_name_and_filters(): void
    {
        Candidate::factory()->create(['candidate_number' => '2026-0001', 'first_name' => 'Candidate', 'last_name' => '001', 'class_batch_id' => $this->classBatch->id]);
        Candidate::factory()->create(['candidate_number' => '2026-0002', 'first_name' => 'Candidate', 'last_name' => '002', 'status' => CandidateStatus::Withdrawn->value]);

        $this->actingAs($this->admin)
            ->get('/candidates?search=0002')
            ->assertInertia(fn (Assert $page) => $page->component('staff/candidates/index')->has('candidates.data', 1)->where('candidates.data.0.candidateNumber', '2026-0002'));

        $this->actingAs($this->admin)
            ->get('/candidates?search=Candidate 001')
            ->assertInertia(fn (Assert $page) => $page->has('candidates.data', 1)->where('candidates.data.0.name', 'Candidate 001'));

        $this->actingAs($this->admin)
            ->get("/candidates?class={$this->classBatch->id}")
            ->assertInertia(fn (Assert $page) => $page->has('candidates.data', 1)->where('candidates.data.0.className', 'Sample Batch A'));

        $this->actingAs($this->admin)
            ->get('/candidates?status=withdrawn')
            ->assertInertia(fn (Assert $page) => $page->has('candidates.data', 1)->where('candidates.data.0.status.label', 'Withdrawn'));

        $this->actingAs($this->admin)
            ->get('/candidates?status=graduated&class=abc')
            ->assertInertia(fn (Assert $page) => $page->where('filters.status', '')->where('filters.class', '')->has('candidates.data', 2));
    }

    public function test_profile_shows_class_subjects_and_instructors(): void
    {
        $offering = $this->app->make(ClassBatchService::class)->addSubject($this->classBatch, Subject::factory()->create(['name' => 'Subject 1']));
        $this->app->make(InstructorAssignmentService::class)->assign($offering, $this->userWithRole(SystemRole::Instructor, ['name' => 'Instructor Alpha']));
        $candidate = Candidate::factory()->create(['class_batch_id' => $this->classBatch->id]);

        $this->actingAs($this->admin)
            ->get("/candidates/{$candidate->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('staff/candidates/show')
                ->where('candidate.classBatch.name', 'Sample Batch A')
                ->where('subjects.0.name', 'Subject 1')
                ->where('subjects.0.instructors', ['Instructor Alpha'])
                ->where('candidate.account.isActive', true)
                ->where('canEdit', true));
    }

    public function test_updates_keep_the_account_in_sync_and_are_audited(): void
    {
        $candidate = Candidate::factory()->create(['candidate_number' => 'OC-0214', 'first_name' => 'Juana', 'last_name' => 'Example']);
        $this->storeSession($candidate->user);

        $this->actingAs($this->admin)
            ->put("/candidates/{$candidate->id}", $this->updatePayload($candidate, [
                'candidate_number' => 'OC-0215',
                'last_name' => 'Sample',
                'class_batch_id' => $this->classBatch->id,
                'status' => CandidateStatus::OnLeave->value,
            ]))
            ->assertRedirect(route('candidates.show', $candidate));

        $account = $candidate->user->fresh();
        $this->assertSame('oc-0215', $account->username);
        $this->assertSame('Juana Sample', $account->name);
        $this->assertSame(CandidateStatus::OnLeave, $candidate->fresh()->status);
        // Changing the sign-in username signs the candidate out.
        $this->assertSame(0, DB::table('sessions')->where('user_id', $account->id)->count());

        $entry = AuditLog::query()->where('action', AuditAction::CandidateUpdated->value)->sole();
        $this->assertSame(['candidate_number' => 'OC-0214', 'last_name' => 'Example', 'class' => null, 'status' => 'enrolled'], $entry->old_values);
        $this->assertSame(['candidate_number' => 'OC-0215', 'last_name' => 'Sample', 'class' => 'Sample Batch A', 'status' => 'on_leave'], $entry->new_values);
    }

    /**
     * Milestone 19: moving a candidate between classes, and refusing a class
     * that does not exist.
     */
    public function test_a_candidate_moves_to_another_class_and_unknown_classes_are_refused(): void
    {
        $candidate = Candidate::factory()->create(['class_batch_id' => $this->classBatch->id]);
        $other = ClassBatch::factory()->for($this->classBatch->academicPeriod)->create(['name' => 'Sample Batch B']);

        $this->actingAs($this->admin)
            ->put("/candidates/{$candidate->id}", $this->updatePayload($candidate, ['class_batch_id' => $other->id]))
            ->assertRedirect(route('candidates.show', $candidate));
        $this->assertSame($other->id, $candidate->fresh()->class_batch_id);
        $entry = AuditLog::query()->where('action', AuditAction::CandidateUpdated->value)->sole();
        $this->assertSame(['class' => 'Sample Batch A'], $entry->old_values);
        $this->assertSame(['class' => 'Sample Batch B'], $entry->new_values);

        $this->actingAs($this->admin)
            ->put("/candidates/{$candidate->id}", $this->updatePayload($candidate->fresh(), ['class_batch_id' => 999999]))
            ->assertSessionHasErrors('class_batch_id');
        $this->assertSame($other->id, $candidate->fresh()->class_batch_id);
    }

    public function test_deactivated_candidate_accounts_cannot_sign_in(): void
    {
        $candidate = Candidate::factory()->create(['candidate_number' => 'OC-0214']);

        $this->actingAs($this->admin)->put("/candidates/{$candidate->id}", $this->updatePayload($candidate, ['account_active' => false]));
        $this->post('/logout');

        $this->assertFalse($candidate->user->fresh()->is_active);
        $this->post('/login', ['username' => 'oc-0214', 'password' => 'password'])
            ->assertSessionHasErrors(['username' => 'This account has been deactivated. Contact an administrator.']);
    }

    public function test_password_reset_is_audited_without_the_password(): void
    {
        $candidate = Candidate::factory()->create();
        $this->storeSession($candidate->user);

        $this->actingAs($this->admin)->put("/candidates/{$candidate->id}", $this->updatePayload($candidate, [
            'password' => 'new-tablet-password',
            'password_confirmation' => 'new-tablet-password',
        ]));

        $this->assertTrue(Hash::check('new-tablet-password', $candidate->user->fresh()->password));
        $entry = AuditLog::query()->where('action', AuditAction::CandidatePasswordReset->value)->sole();
        $this->assertNull($entry->new_values);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $candidate->user_id)->count());
    }

    public function test_the_database_rejects_unknown_statuses(): void
    {
        $candidate = Candidate::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('candidates')->where('id', $candidate->id)->update(['status' => 'graduated']);
    }

    public function test_instructors_and_candidates_cannot_open_candidate_records(): void
    {
        $candidate = Candidate::factory()->create();

        foreach ([$this->userWithRole(SystemRole::Instructor), $candidate->user] as $user) {
            $this->actingAs($user)->get('/candidates')->assertForbidden();
            $this->actingAs($user)->get("/candidates/{$candidate->id}")->assertForbidden();
            $this->actingAs($user)->post('/candidates', $this->payload())->assertForbidden();
        }

        $this->assertDatabaseMissing('candidates', ['candidate_number' => 'OC-0214']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'candidate_number' => 'OC-0214',
            'first_name' => 'Juana',
            'last_name' => 'Example',
            'class_batch_id' => $this->classBatch->id,
            'password' => 'tablet-password-1',
            'password_confirmation' => 'tablet-password-1',
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function updatePayload(Candidate $candidate, array $overrides = []): array
    {
        return [
            'candidate_number' => $candidate->candidate_number,
            'first_name' => $candidate->first_name,
            'last_name' => $candidate->last_name,
            'class_batch_id' => $candidate->class_batch_id ?? '',
            'status' => $candidate->status->value,
            'account_active' => true,
            'password' => '',
            'password_confirmation' => '',
            ...$overrides,
        ];
    }

    private function storeSession(User $user): void
    {
        DB::table('sessions')->insert([
            'id' => 'session-'.$user->id,
            'user_id' => $user->id,
            'ip_address' => null,
            'user_agent' => null,
            'payload' => '',
            'last_activity' => now()->timestamp,
        ]);
    }
}
