<?php

namespace Tests\Feature\Medical;

use App\Enums\MedicalDocumentStatus;
use App\Enums\SystemRole;
use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\CandidateMedicalDocument;
use App\Models\User;
use Database\Seeders\DemoMedicalDocumentSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Teaching\BuildsTeachingFixtures;
use Tests\TestCase;

/**
 * Medical documents candidates upload (owner request, 2026-10-02):
 * certificates and check-up findings that the medical staff accept or return
 * with a reason; the candidate withdraws an upload only while it waits;
 * instructors of the candidate's class see them in the view-only viewer
 * (owner decision 2026-10-02: no request to view), and every view is recorded.
 */
class MedicalDocumentTest extends TestCase
{
    use BuildsTeachingFixtures;

    private const PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->buildTeachingFixtures();
        $this->admin = $this->userWithRole(SystemRole::SuperAdministrator, ['name' => 'Medical Admin']);
    }

    public function test_candidates_upload_documents_that_wait_for_review(): void
    {
        $this->upload(['title' => 'Annual physical examination findings', 'category' => 'checkup_findings', 'document_date' => '2026-09-15', 'notes' => 'Mild asthma noted.'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('portal.medical'));
        $this->upload(['title' => 'Medical certificate', 'category' => 'medical_certificate', 'file' => UploadedFile::fake()->image('certificate.jpg', 800, 600)])->assertSessionHasNoErrors();

        $pdf = CandidateMedicalDocument::query()->where('category', 'checkup_findings')->sole();
        $this->assertSame(MedicalDocumentStatus::Submitted, $pdf->status);
        $this->assertSame('application/pdf', $pdf->mime_type);
        $this->assertSame($this->candidateInA->user_id, (int) $pdf->uploaded_by);
        $this->assertStringStartsWith('medical-documents/'.$this->candidateInA->id.'/', $pdf->path);
        Storage::disk('local')->assertExists($pdf->path);
        $this->assertSame('image/jpeg', CandidateMedicalDocument::query()->where('category', 'medical_certificate')->value('mime_type'));

        // The audit log keeps the kind and size only, never the title, notes or file name.
        $audit = AuditLog::query()->where('action', 'medical_document.uploaded')->where('auditable_id', $pdf->id)->sole();
        $this->assertSame('checkup_findings', $audit->new_values['category']);
        foreach (['Annual physical', 'asthma', 'findings.pdf'] as $secret) {
            $this->assertStringNotContainsString($secret, json_encode($audit->getAttributes()) ?: '');
        }

        $this->actingAs($this->candidateInA->user)->get(route('portal.medical'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('portal/medical')
                ->has('documents', 2)
                ->where('documents.1.title', 'Annual physical examination findings')
                ->where('documents.1.status.value', 'submitted')
                ->where('documents.1.fileType', 'pdf')
                ->where('documents.1.can.withdraw', true)
                ->where('documents.1.protectedUrl', null)
                ->has('categories', 7)
                ->where('limits.remaining', 98));

        // The candidate opens and downloads their own file; nobody else's record shows it.
        $this->actingAs($this->candidateInA->user)->get(route('portal.medical.documents.file', $pdf))->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Disposition', 'inline; filename="annual-physical-examination-findings.pdf"');
        $download = $this->actingAs($this->candidateInA->user)->get(route('portal.medical.documents.file', $pdf).'?download=1')->assertOk();
        $this->assertStringStartsWith('attachment;', (string) $download->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', (string) $download->headers->get('Cache-Control'));

        $this->actingAs($this->candidateInB->user)->get(route('portal.medical.documents.file', $pdf))->assertForbidden();
        $this->actingAs($this->candidateInB->user)->get(route('portal.medical'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('documents', 0));
    }

    public function test_uploads_are_checked(): void
    {
        $this->upload(['category' => 'not_a_kind'])->assertSessionHasErrors('category');
        $this->upload(['title' => ''])->assertSessionHasErrors('title');
        $this->upload(['document_date' => now()->addDays(3)->toDateString()])->assertSessionHasErrors('document_date');
        $this->upload(['file' => null])->assertSessionHasErrors('file');
        $this->upload(['file' => UploadedFile::fake()->create('scan.pdf', 11 * 1024, 'application/pdf')])->assertSessionHasErrors('file');
        // The type comes from the contents, not the name.
        $this->upload(['file' => UploadedFile::fake()->createWithContent('certificate.pdf', 'Just some text, not a PDF.')])->assertSessionHasErrors('file');
        $this->upload(['file' => UploadedFile::fake()->createWithContent('drawing.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')])->assertSessionHasErrors('file');
        $this->upload(['file' => UploadedFile::fake()->createWithContent('photo.jpg', 'not really a photo')])->assertSessionHasErrors('file');

        $this->assertSame(0, CandidateMedicalDocument::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());

        // Staff do not upload on a candidate's behalf through the portal.
        $this->actingAs($this->admin)->post(route('portal.medical.documents.store'), $this->details())->assertForbidden();
    }

    public function test_candidates_withdraw_an_upload_only_while_it_waits(): void
    {
        $waiting = $this->uploaded('First copy');
        $reviewed = $this->uploaded('Second copy');

        $this->actingAs($this->candidateInB->user)->delete(route('portal.medical.documents.destroy', $waiting))->assertForbidden();
        $this->actingAs($this->candidateInA->user)->delete(route('portal.medical.documents.destroy', $waiting))->assertRedirect(route('portal.medical'));
        $this->assertNull($waiting->fresh());
        Storage::disk('local')->assertMissing($waiting->path);
        $this->assertSame(1, AuditLog::query()->where('action', 'medical_document.withdrawn')->count());

        $this->actingAs($this->admin)->post(route('medical.documents.accept', $reviewed))->assertSessionHasNoErrors();
        $this->actingAs($this->candidateInA->user)->delete(route('portal.medical.documents.destroy', $reviewed))->assertForbidden();
        $this->assertNotNull($reviewed->fresh());
        Storage::disk('local')->assertExists($reviewed->path);
    }

    public function test_medical_staff_accept_or_return_with_a_reason(): void
    {
        $first = $this->uploaded('Blurred certificate');
        $second = $this->uploaded('Laboratory result');

        $this->actingAs($this->admin)->get(route('medical.documents.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('staff/medical/documents')
                ->where('filters.tab', 'waiting')
                ->where('counts.waiting', 2)
                ->has('documents.data', 2)
                // Oldest first: first come, first reviewed.
                ->where('documents.data.0.id', $first->id)
                ->where('documents.data.0.candidate.number', $this->candidateInA->candidate_number)
                ->where('documents.data.0.can.review', true));
        $this->actingAs($this->admin)->get(route('medical.records.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('waitingDocuments', 2));

        // Medical staff open and download every file.
        $this->actingAs($this->admin)->get(route('medical.documents.file', $first))->assertOk()->assertHeader('Content-Type', 'application/pdf');

        // A return needs a reason the candidate sees.
        $this->actingAs($this->admin)->post(route('medical.documents.return', $first), ['reason' => ''])->assertSessionHasErrors('reason');
        $this->actingAs($this->admin)->post(route('medical.documents.return', $first), ['reason' => 'Too blurred to read; upload a clearer copy.'])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('medical.documents.accept', $second), ['note' => 'Filed.'])->assertSessionHasNoErrors();

        $first->refresh();
        $this->assertSame(MedicalDocumentStatus::Returned, $first->status);
        $this->assertSame($this->admin->id, (int) $first->reviewed_by);
        $this->assertSame(MedicalDocumentStatus::Accepted, $second->fresh()->status);

        // Reviewed once only.
        $this->actingAs($this->admin)->post(route('medical.documents.accept', $first))->assertSessionHasErrors('document');
        $this->assertSame(MedicalDocumentStatus::Returned, $first->fresh()->status);

        $this->actingAs($this->candidateInA->user)->get(route('portal.medical'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('documents.1.status.value', 'returned')
                ->where('documents.1.reviewNote', 'Too blurred to read; upload a clearer copy.')
                ->where('documents.1.can.withdraw', false));
        $this->actingAs($this->candidateInA->user)->get('/portal')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('sections.medical', ['documentCount' => 2, 'waitingCount' => 0, 'returnedCount' => 1]));
        $this->actingAs($this->candidateInB->user)->get('/portal')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('sections.medical', ['documentCount' => 0, 'waitingCount' => 0, 'returnedCount' => 0]));

        $this->actingAs($this->admin)->get(route('medical.documents.index', ['tab' => 'returned']))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('documents.data', 1)->where('counts.waiting', 0));

        $audit = AuditLog::query()->where('action', 'medical_document.returned')->sole();
        $this->assertStringNotContainsString('blurred', json_encode($audit->getAttributes()) ?: '');
    }

    public function test_only_medical_staff_review_or_open_staff_files(): void
    {
        $document = $this->uploaded('Medical certificate');

        foreach ([$this->alpha, $this->candidateInA->user] as $user) {
            $this->actingAs($user)->get(route('medical.documents.index'))->assertForbidden();
            $this->actingAs($user)->post(route('medical.documents.accept', $document))->assertForbidden();
            $this->actingAs($user)->post(route('medical.documents.return', $document), ['reason' => 'Not acceptable at all.'])->assertForbidden();
            $this->actingAs($user)->get(route('medical.documents.file', $document))->assertForbidden();
        }

        $this->assertSame(MedicalDocumentStatus::Submitted, $document->fresh()->status);
    }

    public function test_instructors_of_the_class_view_documents_but_never_download_them_directly(): void
    {
        $accepted = $this->uploaded('Check-up findings');
        $returned = $this->uploaded('Wrong file');
        $this->actingAs($this->admin)->post(route('medical.documents.accept', $accepted));
        $this->actingAs($this->admin)->post(route('medical.documents.return', $returned), ['reason' => 'This is not a medical document.']);

        // Owner decision (2026-10-02): no request to view. The documents, view only,
        // without review details and without returned ones.
        $this->actingAs($this->alpha)->get(route('candidates.show', $this->candidateInA))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('medical.scope', 'granted')
                ->has('medical.documents', 1)
                ->where('medical.documents.0.id', $accepted->id)
                ->where('medical.documents.0.fileUrl', null)
                ->where('medical.documents.0.downloadUrl', null)
                ->where('medical.documents.0.protectedUrl', route('medical.documents.protected', $accepted, false))
                ->where('medical.documents.0.reviewedBy', null)
                ->where('medical.documents.0.can.review', false)
                ->where('medical.documents.0.download.canRequest', true));

        // The file reaches the protected viewer only, never a tab or a download.
        $this->actingAs($this->alpha)->get(route('medical.documents.protected', $accepted))->assertNotFound();
        $this->actingAs($this->alpha)->get(route('medical.documents.file', $accepted))->assertForbidden();
        $this->actingAs($this->alpha)->get(route('medical.documents.file', $accepted).'?download=1')->assertForbidden();
        $this->viewProtected($this->alpha, $returned)->assertForbidden();

        $response = $this->viewProtected($this->alpha, $accepted)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertSame('inline', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('sandbox', (string) $response->headers->get('Content-Security-Policy'));

        // Every view is recorded, without the title.
        $view = AuditLog::query()->where('action', 'medical_document.viewed')->sole();
        $this->assertSame($this->alpha->id, (int) $view->actor_id);
        $this->assertStringNotContainsString('Check-up', json_encode($view->getAttributes()) ?: '');

        // Bravo teaches Class A too; an instructor outside the class sees nothing.
        $this->viewProtected($this->bravo, $accepted)->assertOk();
        $this->viewProtected($this->userWithRole(SystemRole::Instructor), $accepted)->assertForbidden();
    }

    public function test_staff_see_the_documents_on_the_candidate_profile(): void
    {
        $this->uploaded('Medical certificate');

        $this->actingAs($this->admin)->get(route('candidates.show', $this->candidateInA))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('medical.scope', 'full')
                ->has('medical.documents', 1)
                ->where('medical.waitingDocuments', 1)
                ->where('medical.documents.0.can.review', true)
                ->where('medical.documents.0.protectedUrl', null));
    }

    public function test_the_demo_documents_are_fictional_and_seeded_once(): void
    {
        $first = Candidate::factory()->create(['class_batch_id' => $this->batchA->id, 'candidate_number' => 'student01']);
        $sixth = Candidate::factory()->create(['class_batch_id' => $this->batchA->id, 'candidate_number' => 'student06']);

        $this->seed(DemoMedicalDocumentSeeder::class);
        $this->seed(DemoMedicalDocumentSeeder::class);

        $this->assertSame(2, $first->medicalDocuments()->count());
        $this->assertSame(1, $first->medicalDocuments()->where('status', 'submitted')->count());
        $this->assertSame(2, $sixth->medicalDocuments()->where('status', 'accepted')->count());
        foreach (CandidateMedicalDocument::query()->with('candidate')->get() as $document) {
            Storage::disk('local')->assertExists($document->path);
            $this->assertSame((int) $document->candidate->user_id, (int) $document->uploaded_by);
            $this->assertStringContainsString('%PDF-', (string) Storage::disk('local')->get($document->path));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function details(array $overrides = []): array
    {
        return [
            'category' => 'checkup_findings',
            'title' => 'Check-up findings',
            'document_date' => '2026-09-15',
            'notes' => null,
            'file' => UploadedFile::fake()->createWithContent('findings.pdf', self::PDF),
            ...$overrides,
        ];
    }

    private function upload(array $overrides = []): TestResponse
    {
        return $this->actingAs($this->candidateInA->user)->post(route('portal.medical.documents.store'), $this->details($overrides));
    }

    private function uploaded(string $title): CandidateMedicalDocument
    {
        $this->upload(['title' => $title])->assertSessionHasNoErrors();

        return CandidateMedicalDocument::query()->where('title', $title)->latest('id')->firstOrFail();
    }

    private function viewProtected(User $viewer, CandidateMedicalDocument $document): TestResponse
    {
        return $this->actingAs($viewer)->get(route('medical.documents.protected', $document), ['X-Medical-Viewer' => '1']);
    }
}
