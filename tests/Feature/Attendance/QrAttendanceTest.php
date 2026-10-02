<?php

namespace Tests\Feature\Attendance;

use App\Enums\AttendanceStatus;
use App\Enums\CandidateStatus;
use App\Enums\SystemRole;
use App\Models\AcademicPeriod;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\ClassBatch;
use App\Models\Subject;
use App\Models\User;
use App\Services\Attendance\AttendanceService;
use App\Services\ClassBatchService;
use App\Services\InstructorAssignmentService;
use App\Support\CandidateQrCode;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Attendance by QR code (owner request, 2026-10-02): every candidate has a
 * random QR code on their profile; instructors scan it with the camera on a
 * session's scanner to mark the candidate present or late; the address in
 * the code opens the profile only for staff who may see the candidate.
 */
class QrAttendanceTest extends TestCase
{
    private User $admin;

    private User $alpha;

    private User $bravo;

    private ClassBatch $classA;

    private Candidate $first;

    private Candidate $second;

    private Candidate $other;

    private AttendanceSession $session;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-01 08:00:00');

        $this->admin = $this->userWithRole(SystemRole::AcademicAdministrator);
        $period = AcademicPeriod::factory()->active()->create(['name' => 'Period Current']);
        $this->classA = ClassBatch::factory()->for($period)->create(['name' => 'Class A']);
        $classB = ClassBatch::factory()->for($period)->create(['name' => 'Class B']);
        $this->first = Candidate::factory()->create(['class_batch_id' => $this->classA->id, 'candidate_number' => 'C-001']);
        $this->second = Candidate::factory()->create(['class_batch_id' => $this->classA->id, 'candidate_number' => 'C-002']);
        $this->other = Candidate::factory()->create(['class_batch_id' => $classB->id, 'candidate_number' => 'C-101']);

        // Instructor Alpha teaches Class A; Instructor Bravo teaches Class B.
        $subject = Subject::factory()->create(['code' => 'SUBJ-1', 'name' => 'Subject 1']);
        $this->alpha = $this->userWithRole(SystemRole::Instructor, ['name' => 'Instructor Alpha']);
        $this->bravo = $this->userWithRole(SystemRole::Instructor, ['name' => 'Instructor Bravo']);
        $classes = $this->app->make(ClassBatchService::class);
        $assignments = $this->app->make(InstructorAssignmentService::class);
        $assignments->assign($classes->addSubject($this->classA, $subject), $this->alpha);
        $assignments->assign($classes->addSubject($classB, $subject), $this->bravo);

        $this->session = $this->app->make(AttendanceService::class)->create($this->classA, ['held_on' => '2026-10-01', 'title' => 'Morning Formation', 'hours' => '1', 'notes' => null], $this->alpha);
    }

    public function test_every_candidate_has_a_random_code_whose_address_opens_the_profile_for_staff_only(): void
    {
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{32}$/', (string) $this->first->qr_token);
        $this->assertNotSame($this->first->qr_token, $this->second->qr_token);
        $this->assertNotNull($this->first->qr_token_issued_at);
        $this->assertArrayNotHasKey('qr_token', $this->first->toArray());
        $address = CandidateQrCode::payload($this->first);
        $this->assertStringEndsWith('/q/'.$this->first->qr_token, $address);
        $this->assertStringNotContainsString('C-001', $address);

        $this->get($address)->assertRedirect(route('login'));
        $this->actingAs($this->admin)->get($address)->assertRedirect(route('candidates.show', $this->first));
        $this->actingAs($this->alpha)->get($address)->assertRedirect(route('candidates.show', $this->first));
        $this->actingAs($this->bravo)->get($address)->assertNotFound();
        $this->actingAs($this->first->user)->get($address)->assertRedirect(route('portal.profile'));
        $this->actingAs($this->second->user)->get($address)->assertNotFound();
        $this->actingAs($this->admin)->get('/q/'.str_repeat('a', 32))->assertNotFound();
    }

    public function test_profiles_show_the_code(): void
    {
        $this->actingAs($this->alpha)->get(route('candidates.show', $this->first))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('candidate.qrCodeUrl', route('candidates.qr', $this->first))
                ->where('qrReissueUrl', null));
        $image = $this->actingAs($this->alpha)->get(route('candidates.qr', $this->first))->assertOk();
        $this->assertSame('image/svg+xml', $image->headers->get('Content-Type'));
        $this->assertStringContainsString('<svg', (string) $image->getContent());
        $this->assertStringContainsString('no-store', (string) $image->headers->get('Cache-Control'));
        $this->actingAs($this->bravo)->get(route('candidates.qr', $this->first))->assertForbidden();

        $this->actingAs($this->first->user)->get(route('portal.profile'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('candidate.qrCodeUrl', route('portal.profile.qr')));
        $this->actingAs($this->first->user)->get(route('portal.profile.qr'))->assertOk()->assertHeader('Content-Type', 'image/svg+xml');
        $this->actingAs($this->first->user)->get(route('candidates.qr', $this->second))->assertForbidden();
    }

    public function test_reissuing_a_code_retires_the_old_one(): void
    {
        $old = $this->first->qr_token;
        $this->actingAs($this->alpha)->post(route('candidates.qr.reissue', $this->first))->assertForbidden();

        $this->actingAs($this->admin)->get(route('candidates.show', $this->first))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('qrReissueUrl', route('candidates.qr.reissue', $this->first)));
        $this->actingAs($this->admin)->post(route('candidates.qr.reissue', $this->first))->assertRedirect();

        $this->assertNotSame($old, $this->first->fresh()->qr_token);
        $this->actingAs($this->admin)->get('/q/'.$old)->assertNotFound();
        $this->assertSame('unknown', $this->scan($this->alpha, $old)->json('result'));
        $audit = AuditLog::query()->where('action', 'candidate.qr_reissued')->sole();
        $this->assertStringNotContainsString((string) $old, json_encode($audit->getAttributes()) ?: '');
    }

    public function test_a_scan_marks_the_candidate_present_once_with_the_time(): void
    {
        $response = $this->scan($this->alpha, CandidateQrCode::payload($this->first))->assertOk()
            ->assertJsonPath('result', 'recorded')
            ->assertJsonPath('candidate.name', $this->first->full_name)
            ->assertJsonPath('candidate.candidateNumber', 'C-001')
            ->assertJsonPath('candidate.status.value', 'present')
            ->assertJsonPath('counts.present', 1)
            ->assertJsonPath('counts.unrecorded', 1);
        // The time of the scan, in the institution's timezone.
        $this->assertStringContainsString(now()->timezone((string) config('institution.timezone'))->format('g:i A'), (string) $response->json('message'));

        $record = AttendanceRecord::query()->sole();
        $this->assertSame(AttendanceStatus::Present, $record->status);
        $this->assertSame($this->alpha->id, $record->recorded_by);
        $this->assertNotNull($record->scanned_at);
        $audit = AuditLog::query()->where('action', 'attendance_session.attendance_recorded')->sole();
        $this->assertSame('QR code', $audit->new_values['C-001']['via']);

        // The same code again, as the address or the bare token: nothing changes.
        $this->travel(3)->minutes();
        $this->scan($this->alpha, CandidateQrCode::payload($this->first))->assertJsonPath('result', 'already');
        $this->scan($this->alpha, (string) $this->first->qr_token)->assertJsonPath('result', 'already');
        $this->assertSame(1, AttendanceRecord::query()->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'attendance_session.attendance_recorded')->count());

        // A code printed on a card made on another server still reads.
        $this->scan($this->alpha, 'https://academic-system.local/q/'.$this->second->qr_token, 'late')
            ->assertJsonPath('result', 'recorded')
            ->assertJsonPath('candidate.status.value', 'late');

        // The roll call shows the time of the scan.
        $this->actingAs($this->alpha)->get(route('attendance.sessions.show', $this->session))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('rows.0.record.scannedAt', fn (?string $at) => $at !== null)
                ->where('scanUrl', route('attendance.sessions.scan', $this->session)));
    }

    public function test_a_scan_changes_absent_or_excused_but_keeps_present_or_late(): void
    {
        $this->app->make(AttendanceService::class)->record($this->session, [
            $this->first->id => ['status' => AttendanceStatus::Absent, 'remarks' => 'Not at formation'],
            $this->second->id => ['status' => AttendanceStatus::Late, 'remarks' => null],
        ], $this->alpha);

        $this->scan($this->admin, (string) $this->first->qr_token)->assertJsonPath('result', 'updated');
        $first = AttendanceRecord::query()->where('candidate_id', $this->first->id)->sole();
        $this->assertSame(AttendanceStatus::Present, $first->status);
        $this->assertSame('Not at formation', $first->remarks);

        $this->scan($this->admin, (string) $this->second->qr_token)->assertJsonPath('result', 'already');
        $this->assertSame(AttendanceStatus::Late, AttendanceRecord::query()->where('candidate_id', $this->second->id)->sole()->status);
    }

    public function test_codes_of_other_classes_withdrawn_candidates_or_unknown_codes_record_nothing(): void
    {
        $this->scan($this->alpha, CandidateQrCode::payload($this->other))
            ->assertJsonPath('result', 'not_in_class')
            ->assertJsonPath('candidate', null);
        $this->scan($this->alpha, 'https://example.com/not-a-code')->assertJsonPath('result', 'unknown');

        $this->second->forceFill(['status' => CandidateStatus::Withdrawn])->save();
        $this->scan($this->alpha, (string) $this->second->qr_token)->assertJsonPath('result', 'not_in_class');

        $this->assertSame(0, AttendanceRecord::query()->count());
    }

    public function test_only_staff_who_manage_the_session_scan(): void
    {
        $code = (string) $this->first->qr_token;
        $this->scan($this->bravo, $code)->assertForbidden();
        $this->scan($this->first->user, $code)->assertForbidden();
        $this->actingAs($this->alpha)->postJson(route('attendance.sessions.scan', $this->session), ['code' => $code, 'status' => 'excused'])->assertUnprocessable();
        $this->actingAs($this->alpha)->postJson(route('attendance.sessions.scan', $this->session), ['status' => 'present'])->assertUnprocessable();
        $this->assertSame(0, AttendanceRecord::query()->count());
    }

    public function test_the_camera_is_allowed_on_staff_pages_only(): void
    {
        $this->actingAs($this->alpha)->get(route('attendance.sessions.show', $this->session))->assertOk()
            ->assertHeader('Permissions-Policy', 'camera=(self), microphone=(), geolocation=(), payment=()');
        $this->actingAs($this->first->user)->get(route('portal.home'))->assertOk()
            ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
    }

    private function scan(User $user, string $code, string $status = 'present'): TestResponse
    {
        return $this->actingAs($user)->postJson(route('attendance.sessions.scan', $this->session), ['code' => $code, 'status' => $status]);
    }

    public function test_the_qr_card_is_saved_as_a_pdf_for_allowed_staff_and_the_candidate_only(): void
    {
        $this->actingAs($this->alpha)->get(route('candidates.show', $this->first))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('candidate.qrCardUrl', route('candidates.qr.pdf', $this->first)));
        $card = $this->actingAs($this->alpha)->get(route('candidates.qr.pdf', $this->first))->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')->assertHeader('Cache-Control', 'no-store, private');
        $this->assertStringStartsWith('%PDF-', (string) $card->getContent());
        $this->assertStringContainsString('attachment;', (string) $card->headers->get('Content-Disposition'));
        $this->actingAs($this->bravo)->get(route('candidates.qr.pdf', $this->first))->assertForbidden();

        $this->actingAs($this->first->user)->get(route('portal.profile'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('candidate.qrCardUrl', route('portal.profile.qr.pdf')));
        $this->actingAs($this->first->user)->get(route('portal.profile.qr.pdf'))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->actingAs($this->first->user)->get(route('candidates.qr.pdf', $this->second))->assertForbidden();
    }
}
