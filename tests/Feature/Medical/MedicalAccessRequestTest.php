<?php

namespace Tests\Feature\Medical;

use App\Enums\MedicalAccessStatus;
use App\Enums\SystemRole;
use App\Models\AuditLog;
use App\Models\MedicalAccessRequest;
use App\Models\Subject;
use App\Models\User;
use App\Services\Medical\MedicalRecordService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Teaching\BuildsTeachingFixtures;
use Tests\TestCase;

/**
 * Instructors' requests to see a candidate's full medical record (owner
 * request, 2026-10-02): filed with a reason, approved by medical staff for
 * 1, 7 or 30 days, rejected with a reason, cancelled, or withdrawn early.
 */
class MedicalAccessRequestTest extends TestCase
{
    use BuildsTeachingFixtures;

    private const REASON = 'Planning the endurance march; I need the full health picture.';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildTeachingFixtures();
        $this->admin = $this->userWithRole(SystemRole::SuperAdministrator, ['name' => 'Medical Admin']);

        $service = app(MedicalRecordService::class);
        $shared = $service->createField(['name' => 'Allergies', 'field_type' => 'long_text', 'options' => null, 'help_text' => null, 'sort_order' => 1, 'visible_to_candidate' => true], $this->admin);
        $private = $service->createField(['name' => 'Existing Conditions', 'field_type' => 'long_text', 'options' => null, 'help_text' => null, 'sort_order' => 2, 'visible_to_candidate' => true], $this->admin);
        $service->saveRecord($this->candidateInA, [$shared->id => 'Shellfish.', $private->id => 'Mild asthma, controlled.'], $this->admin);
    }

    public function test_an_approved_request_shows_the_full_record_until_it_ends(): void
    {
        $before = $this->profile($this->alpha)->assertInertia(fn (Assert $page) => $page
            ->where('medical.scope', 'instructor')
            ->has('medical.entries', 0)
            ->where('medical.access.canRequest', true));
        $this->assertStringNotContainsString('Shellfish.', $before->getContent());

        $this->file()->assertSessionHasNoErrors()->assertRedirect(route('candidates.show', $this->candidateInA));
        $request = MedicalAccessRequest::query()->sole();
        $this->assertSame(MedicalAccessStatus::Pending, $request->status);
        $this->profile($this->alpha)->assertInertia(fn (Assert $page) => $page
            ->where('medical.scope', 'instructor')
            ->where('medical.access.pending.requestId', $request->id)
            ->where('medical.access.canRequest', false));

        $this->actingAs($this->admin)->post(route('medical.access.approve', $request), ['days' => 7, 'note' => 'For the march only.'])->assertSessionHasNoErrors();
        $request->refresh();
        $this->assertSame(MedicalAccessStatus::Approved, $request->status);
        $this->assertTrue($request->expires_at->between(now()->addDays(7)->subMinute(), now()->addDays(7)->addMinute()));

        $response = $this->profile($this->alpha)->assertInertia(fn (Assert $page) => $page
            ->where('medical.scope', 'granted')
            ->has('medical.entries', 2)
            ->where('medical.canEdit', false)
            ->where('medical.access.grant.requestId', $request->id)
            ->where('medical.access.grant.grantedBy', 'Medical Admin'));
        $this->assertStringContainsString('Mild asthma, controlled.', $response->getContent());
        // Each view through approved access is recorded, without values.
        $view = AuditLog::query()->where('action', 'medical_record.viewed')->sole();
        $this->assertSame($this->alpha->id, (int) $view->actor_id);
        $this->assertStringNotContainsString('asthma', json_encode($view->getAttributes()) ?: '');

        // Still read-only.
        $this->actingAs($this->alpha)->get(route('medical.records.edit', $this->candidateInA))->assertForbidden();

        // After the end date only the shared fields remain, and the instructor is told.
        $this->travel(8)->days();
        $response = $this->profile($this->alpha)->assertInertia(fn (Assert $page) => $page
            ->where('medical.scope', 'instructor')
            ->has('medical.entries', 0)
            ->where('medical.access.lastDecision.status.value', 'expired')
            ->where('medical.access.canRequest', true));
        $this->assertStringNotContainsString('Mild asthma, controlled.', $response->getContent());
    }

    public function test_a_rejection_needs_a_reason_that_the_instructor_sees(): void
    {
        $this->file();
        $request = MedicalAccessRequest::query()->sole();

        $this->actingAs($this->admin)->post(route('medical.access.reject', $request), ['note' => ''])->assertSessionHasErrors('note');
        $this->actingAs($this->admin)->post(route('medical.access.reject', $request), ['note' => 'Ask the clinic directly.'])->assertSessionHasNoErrors();

        $this->profile($this->alpha)->assertInertia(fn (Assert $page) => $page
            ->where('medical.scope', 'instructor')
            ->where('medical.access.lastDecision.status.value', 'rejected')
            ->where('medical.access.lastDecision.note', 'Ask the clinic directly.'));
        $this->assertSame(1, AuditLog::query()->where('action', 'medical_access.rejected')->count());

        // Decided requests cannot be decided again.
        $this->actingAs($this->admin)->post(route('medical.access.approve', $request), ['days' => 7])->assertSessionHasErrors('access');
    }

    public function test_access_can_be_withdrawn_early_and_requests_cancelled(): void
    {
        $this->file();
        $request = MedicalAccessRequest::query()->sole();
        $this->actingAs($this->admin)->post(route('medical.access.cancel', $request))->assertForbidden();
        $this->actingAs($this->alpha)->post(route('medical.access.cancel', $request))->assertSessionHasNoErrors();
        $this->assertSame(MedicalAccessStatus::Cancelled, $request->fresh()->status);

        $this->file()->assertSessionHasNoErrors();
        $second = MedicalAccessRequest::query()->latest('id')->firstOrFail();
        $this->actingAs($this->admin)->post(route('medical.access.approve', $second), ['days' => 1])->assertSessionHasNoErrors();
        $this->actingAs($this->alpha)->post(route('medical.access.revoke', $second))->assertForbidden();
        $this->actingAs($this->admin)->post(route('medical.access.revoke', $second))->assertSessionHasNoErrors();

        $this->assertSame(MedicalAccessStatus::Revoked, $second->fresh()->status);
        $this->profile($this->alpha)->assertInertia(fn (Assert $page) => $page
            ->where('medical.scope', 'instructor')
            ->where('medical.access.lastDecision.status.value', 'revoked'));
    }

    public function test_only_instructors_of_the_class_may_ask_and_only_once_at_a_time(): void
    {
        $this->file()->assertSessionHasNoErrors();
        $this->file()->assertSessionHasErrors('reason');
        $this->file(reason: 'Too short.')->assertSessionHasErrors('reason');

        // Not teaching the candidate's class, medical staff (who already see it), candidates.
        $this->file(actor: $this->bravoOutsideClass())->assertForbidden();
        $this->file(actor: $this->admin)->assertForbidden();
        $this->file(actor: $this->candidateInA->user)->assertForbidden();

        $request = MedicalAccessRequest::query()->sole();
        $this->actingAs($this->alpha)->post(route('medical.access.approve', $request), ['days' => 7])->assertForbidden();
        $this->actingAs($this->alpha)->get(route('medical.access.index'))->assertForbidden();
        $this->actingAs($this->admin)->post(route('medical.access.approve', $request), ['days' => 3])->assertSessionHasErrors('days');
    }

    public function test_the_medical_staff_list_shows_pending_and_active_requests(): void
    {
        $this->file();
        $this->actingAs($this->admin)->get(route('medical.access.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('staff/medical/access-requests')
                ->where('status', 'pending')
                ->where('counts.pending', 1)
                ->has('requests.data', 1)
                ->where('requests.data.0.reason', self::REASON)
                ->where('requests.data.0.can.decide', true));
        $this->actingAs($this->admin)->get(route('medical.records.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('pendingAccessRequests', 1));

        $this->actingAs($this->admin)->post(route('medical.access.approve', MedicalAccessRequest::query()->sole()), ['days' => 30]);
        $this->actingAs($this->admin)->get(route('medical.access.index', ['status' => 'active']))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('counts.active', 1)->where('requests.data.0.can.revoke', true));
    }

    public function test_the_database_allows_one_pending_request_per_instructor_and_candidate(): void
    {
        $this->file();
        $row = (array) DB::table('medical_access_requests')->first();
        unset($row['id'], $row['pending_candidate_id']);

        $this->expectException(QueryException::class);
        DB::table('medical_access_requests')->insert($row);
    }

    // ---------------------------------------------------------------

    private function file(?User $actor = null, string $reason = self::REASON): TestResponse
    {
        return $this->actingAs($actor ?? $this->alpha)->post(route('medical.access.store', $this->candidateInA), ['reason' => $reason]);
    }

    private function profile(User $viewer): TestResponse
    {
        return $this->actingAs($viewer)->get(route('candidates.show', $this->candidateInA))->assertOk();
    }

    /** An instructor who teaches only Batch B. */
    private function bravoOutsideClass(): User
    {
        $instructor = $this->userWithRole(SystemRole::Instructor, ['name' => 'Instructor Charlie']);
        $this->teach($instructor, $this->offering($this->batchB, $this->offeringSubject()));

        return $instructor;
    }

    private function offeringSubject(): Subject
    {
        return Subject::query()->orderBy('id')->firstOrFail();
    }
}
