<?php

namespace Tests\Feature\Medical;

use App\Enums\CampusCode;
use App\Enums\MedicalDownloadStatus;
use App\Enums\SystemRole;
use App\Models\AuditLog;
use App\Models\CandidateMedicalDocument;
use App\Models\InstructorAssignment;
use App\Models\MedicalDownloadRequest;
use App\Models\User;
use Database\Factories\CampusFactory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Teaching\BuildsTeachingFixtures;
use Tests\TestCase;

/**
 * Instructors' downloads of uploaded medical documents (owner requests,
 * 2026-10-02): instructors of the candidate's class view a document only; a
 * copy needs a download request approved by the medical staff, valid for a
 * few days, every download recorded. Print Screen presses in the viewer are
 * recorded.
 */
class MedicalDownloadRequestTest extends TestCase
{
    use BuildsTeachingFixtures;

    private const REASON = 'Needed for the medical clearance file of the field exercise.';

    private User $admin;

    private CandidateMedicalDocument $document;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->buildTeachingFixtures();
        $this->admin = $this->userWithRole(SystemRole::SuperAdministrator, ['name' => 'Medical Admin']);

        $this->actingAs($this->candidateInA->user)->post(route('portal.medical.documents.store'), [
            'category' => 'checkup_findings',
            'title' => 'Check-up findings',
            'document_date' => '2026-09-15',
            'file' => UploadedFile::fake()->createWithContent('findings.pdf', "%PDF-1.4\n%%EOF\n"),
        ])->assertSessionHasNoErrors();
        $this->document = CandidateMedicalDocument::query()->sole();
        $this->actingAs($this->admin)->post(route('medical.documents.accept', $this->document))->assertSessionHasNoErrors();
    }

    public function test_a_copy_needs_an_approved_download_request(): void
    {
        // Viewing gives no download.
        $this->profileDocument($this->alpha)
            ->where('medical.documents.0.download.request', null)
            ->where('medical.documents.0.download.fileUrl', null)
            ->where('medical.documents.0.download.canRequest', true)
            ->where('medical.documents.0.fileUrl', null);
        $this->actingAs($this->alpha)->get(route('medical.documents.file', $this->document))->assertForbidden();

        $this->requestDownload(['reason' => 'Too short'])->assertSessionHasErrors('reason');
        $this->requestDownload()->assertSessionHasNoErrors();
        $this->requestDownload()->assertSessionHasErrors('reason');
        $download = MedicalDownloadRequest::query()->sole();
        $this->assertSame(MedicalDownloadStatus::Pending, $download->status);
        $this->assertSame($this->candidateInA->id, (int) $download->candidate_id);

        $this->profileDocument($this->alpha)
            ->where('medical.documents.0.download.request.status.value', 'pending')
            ->where('medical.documents.0.download.canCancel', true)
            ->where('medical.documents.0.download.canRequest', false)
            ->where('medical.documents.0.download.fileUrl', null);
        $this->actingAs($this->alpha)->get(route('medical.downloads.file', $download))->assertForbidden();

        // Medical staff see it, with the count on Medical Records.
        $this->actingAs($this->admin)->get(route('medical.downloads.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('staff/medical/download-requests')
                ->has('requests.data', 1)
                ->where('requests.data.0.reason', self::REASON)
                ->where('requests.data.0.can.decide', true)
                ->where('counts.pending', 1));
        $this->actingAs($this->admin)->get(route('medical.records.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('pendingDownloadRequests', 1));

        $this->actingAs($this->admin)->post(route('medical.downloads.approve', $download), ['note' => 'For the clearance file only.'])->assertSessionHasNoErrors();
        $download->refresh();
        $this->assertSame(MedicalDownloadStatus::Approved, $download->status);
        $this->assertTrue($download->expires_at->between(now()->addDays(3)->subMinute(), now()->addDays(3)->addMinute()));

        $this->profileDocument($this->alpha)
            ->where('medical.documents.0.download.request.status.value', 'approved')
            ->where('medical.documents.0.download.fileUrl', route('medical.downloads.file', $download, false));

        $response = $this->actingAs($this->alpha)->get(route('medical.downloads.file', $download))->assertOk();
        $this->assertStringStartsWith('attachment;', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame(1, $download->fresh()->download_count);

        $audit = AuditLog::query()->where('action', 'medical_document.downloaded')->sole();
        $this->assertSame($this->alpha->id, (int) $audit->actor_id);
        $this->assertSame($download->id, $audit->new_values['download_request']);
        $this->assertStringNotContainsString('Check-up', json_encode($audit->getAttributes()) ?: '');

        // Another instructor of the class cannot use it.
        $this->actingAs($this->bravo)->get(route('medical.downloads.file', $download))->assertForbidden();

        // The approval ends after its days.
        $this->travel(3)->days();
        $this->travel(1)->minutes();
        $this->actingAs($this->alpha)->get(route('medical.downloads.file', $download))->assertForbidden();
        $this->assertSame(1, $download->fresh()->download_count);
    }

    public function test_a_rejection_needs_a_reason_and_the_instructor_may_ask_again(): void
    {
        $this->requestDownload()->assertSessionHasNoErrors();
        $download = MedicalDownloadRequest::query()->sole();

        $this->actingAs($this->alpha)->post(route('medical.downloads.approve', $download))->assertForbidden();
        $this->actingAs($this->admin)->post(route('medical.downloads.reject', $download), ['note' => ''])->assertSessionHasErrors('note');
        $this->actingAs($this->admin)->post(route('medical.downloads.reject', $download), ['note' => 'Viewing is enough for planning.'])->assertSessionHasNoErrors();
        $this->assertSame(MedicalDownloadStatus::Rejected, $download->fresh()->status);

        $this->profileDocument($this->alpha)
            ->where('medical.documents.0.download.request.status.value', 'rejected')
            ->where('medical.documents.0.download.request.decisionNote', 'Viewing is enough for planning.')
            ->where('medical.documents.0.download.canRequest', true);

        // Decided once only.
        $this->actingAs($this->admin)->post(route('medical.downloads.approve', $download))->assertSessionHasErrors('download');

        // A new request can be cancelled by its requester only.
        $this->requestDownload()->assertSessionHasNoErrors();
        $second = MedicalDownloadRequest::query()->latest('id')->firstOrFail();
        $this->actingAs($this->bravo)->post(route('medical.downloads.cancel', $second))->assertForbidden();
        $this->actingAs($this->alpha)->post(route('medical.downloads.cancel', $second))->assertSessionHasNoErrors();
        $this->assertSame(MedicalDownloadStatus::Cancelled, $second->fresh()->status);
    }

    public function test_withdrawing_the_approval_or_leaving_the_class_ends_the_download(): void
    {
        $this->requestDownload()->assertSessionHasNoErrors();
        $download = MedicalDownloadRequest::query()->sole();
        $this->actingAs($this->admin)->post(route('medical.downloads.approve', $download))->assertSessionHasNoErrors();

        $this->actingAs($this->admin)->post(route('medical.downloads.revoke', $download))->assertSessionHasNoErrors();
        $this->assertSame(MedicalDownloadStatus::Revoked, $download->fresh()->status);
        $this->actingAs($this->alpha)->get(route('medical.downloads.file', $download))->assertForbidden();

        // A new approval no longer works once the instructor stops teaching the class.
        $this->requestDownload()->assertSessionHasNoErrors();
        $again = MedicalDownloadRequest::query()->latest('id')->firstOrFail();
        $this->actingAs($this->admin)->post(route('medical.downloads.approve', $again))->assertSessionHasNoErrors();
        InstructorAssignment::query()->where('instructor_id', $this->alpha->id)->delete();
        $this->actingAs($this->alpha)->get(route('medical.downloads.file', $again))->assertForbidden();
        $this->assertSame(0, $again->fresh()->download_count);

        // Nor can they ask again.
        $this->requestDownload()->assertForbidden();
    }

    public function test_only_instructors_of_the_class_request_and_print_screen_is_recorded(): void
    {
        $outsider = $this->userWithRole(SystemRole::Instructor);
        foreach ([$outsider, $this->candidateInA->user, $this->admin] as $user) {
            $this->actingAs($user)->post(route('medical.downloads.store', $this->document), ['reason' => self::REASON])->assertForbidden();
        }
        $this->assertSame(0, MedicalDownloadRequest::query()->count());

        $this->actingAs($outsider)->post(route('medical.documents.print-screen', $this->document))->assertForbidden();
        $this->actingAs($this->alpha)->post(route('medical.documents.print-screen', $this->document))->assertNoContent();

        $audit = AuditLog::query()->where('action', 'medical_document.print_screen')->sole();
        $this->assertSame($this->alpha->id, (int) $audit->actor_id);
        $this->assertStringNotContainsString('Check-up', json_encode($audit->getAttributes()) ?: '');
        $this->profileDocument($this->alpha)
            ->where('medical.documents.0.printScreenUrl', route('medical.documents.print-screen', $this->document, false));
    }

    public function test_dietitians_of_the_campus_view_documents_on_the_same_terms_as_instructors(): void
    {
        $dietitian = $this->userWithRole(SystemRole::Dietitian);
        $northDietitian = User::factory()->withRole(SystemRole::Dietitian)->onCampus(CampusFactory::fixed(CampusCode::North))->create();

        $this->actingAs($dietitian)->get(route('nutrition.show', $this->candidateInA))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('medical.scope', 'granted')
                ->where('medical.documents.0.fileUrl', null)
                ->where('medical.documents.0.download.canRequest', true));
        $this->actingAs($dietitian)->get(route('medical.documents.protected', $this->document), ['X-Medical-Viewer' => '1'])->assertOk();
        $this->actingAs($dietitian)->get(route('medical.documents.file', $this->document))->assertForbidden();

        $this->actingAs($dietitian)->post(route('medical.downloads.store', $this->document), ['reason' => self::REASON])->assertSessionHasNoErrors();
        $download = MedicalDownloadRequest::query()->sole();
        $this->actingAs($this->admin)->post(route('medical.downloads.approve', $download), ['note' => 'For the nutrition file.'])->assertSessionHasNoErrors();
        $this->actingAs($dietitian)->get(route('medical.downloads.file', $download))->assertOk();

        // A dietitian of another campus sees nothing of it.
        $this->actingAs($northDietitian)->get(route('medical.documents.protected', $this->document), ['X-Medical-Viewer' => '1'])->assertNotFound();
        $this->actingAs($northDietitian)->get(route('medical.downloads.file', $download))->assertNotFound();
    }

    private function requestDownload(array $data = ['reason' => self::REASON]): TestResponse
    {
        return $this->actingAs($this->alpha)->post(route('medical.downloads.store', $this->document), $data);
    }

    private function profileDocument(User $viewer): Assert
    {
        $assert = null;
        $this->actingAs($viewer)->get(route('candidates.show', $this->candidateInA))->assertOk()
            ->assertInertia(function (Assert $page) use (&$assert) {
                $assert = $page->where('medical.scope', 'granted');
            });

        return $assert;
    }
}
