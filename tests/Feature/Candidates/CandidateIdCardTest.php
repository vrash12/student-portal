<?php

namespace Tests\Feature\Candidates;

use App\Enums\AuditAction;
use App\Enums\CampusCode;
use App\Enums\SystemRole;
use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\CandidateBackground;
use App\Models\ClassBatch;
use App\Models\User;
use App\Support\CandidateIdCard;
use App\Support\IdCardArtwork;
use Database\Factories\CampusFactory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Teaching\BuildsTeachingFixtures;
use Tests\TestCase;

/**
 * Candidate ID cards (owner request, 2026-10-05): a vertical card of the
 * standard ID size, on the administrator side only for now.
 */
class CandidateIdCardTest extends TestCase
{
    use BuildsTeachingFixtures;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildTeachingFixtures();
        $this->admin = $this->userWithRole(SystemRole::SuperAdministrator);
        config(['institution.id_card.role_label' => 'Officer Candidate', 'institution.organization_name' => 'Sample Training School', 'institution.login.core_values' => ['Discipline', 'Valor']]);
    }

    /** Page count and the size of the first page (points) of a PDF. */
    private function pages(TestResponse $response): array
    {
        $pdf = (string) $response->getContent();
        preg_match('/\/MediaBox\s*\[\s*[\d.]+\s+[\d.]+\s+([\d.]+)\s+([\d.]+)\s*\]/', $pdf, $box);

        return [preg_match_all('/\/Type\s*\/Page[^s]/', $pdf), (float) ($box[1] ?? 0), (float) ($box[2] ?? 0)];
    }

    public function test_the_card_shows_the_candidates_details_and_what_the_record_is_missing(): void
    {
        $this->candidateInA->forceFill(['first_name' => 'Juan', 'middle_name' => 'Santos', 'last_name' => 'Dela Cruz', 'suffix' => 'Jr.'])->save();

        $this->actingAs($this->admin)->get(route('candidates.id-card', $this->candidateInA))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('staff/candidates/id-card')
                ->where('card.number', $this->candidateInA->candidate_number)
                ->where('card.lastName', 'Dela Cruz')
                ->where('card.givenNames', 'Juan Santos Jr.')
                ->where('card.roleLabel', 'Officer Candidate')
                ->where('card.organization', 'Sample Training School')
                // The back names the card and the core values instead of repeating the organization.
                ->where('card.coreValues', ['Discipline', 'Valor'])
                ->where('card.className', 'Sample Batch A')
                ->where('card.validUntil', $this->activePeriod->ends_on->toDateString())
                ->where('card.photoUrl', null)
                ->where('card.qrUrl', route('candidates.qr', $this->candidateInA, false))
                ->where('card.emergencyContact', null)
                ->where('card.textSizes.lastName', 10.5)
                ->where('missing', fn ($missing) => collect($missing)->contains(fn (string $item) => str_starts_with($item, 'No picture'))
                    && collect($missing)->contains(fn (string $item) => str_starts_with($item, 'No emergency contact')))
                ->where('pdfUrl', route('candidates.id-card.pdf', $this->candidateInA, false))
                ->where('artwork', IdCardArtwork::dataUris()));
    }

    public function test_the_emergency_contact_comes_from_the_background_record(): void
    {
        $background = new CandidateBackground(['emergency_contact_name' => 'Maria Dela Cruz', 'emergency_contact_relationship' => 'Mother', 'emergency_contact_phone' => '0917 555 0101']);
        $background->candidate()->associate($this->candidateInA);
        $background->save();

        $this->actingAs($this->admin)->get(route('candidates.id-card', $this->candidateInA))
            ->assertInertia(fn (Assert $page) => $page->where('card.emergencyContact', ['name' => 'Maria Dela Cruz', 'relationship' => 'Mother', 'phone' => '0917 555 0101']));
    }

    public function test_the_army_artwork_is_the_same_on_every_card_and_valid_svg(): void
    {
        $artwork = IdCardArtwork::dataUris();

        $this->assertSame(['frontHeader', 'frontFooter', 'backHeader', 'backFooter', 'frontTerrain', 'backTerrain'], array_keys($artwork));
        foreach ($artwork as $uri) {
            $this->assertStringStartsWith('data:image/svg+xml;base64,', $uri);
            $svg = simplexml_load_string(base64_decode(substr($uri, strlen('data:image/svg+xml;base64,'))));
            $this->assertNotFalse($svg);
            $this->assertGreaterThan(5, count($svg->path));
        }
        // Generated from fixed seeds: the same pattern on every card and every print.
        $this->assertSame(IdCardArtwork::camouflage(153, 64, 11), IdCardArtwork::camouflage(153, 64, 11));
        $this->assertNotSame(IdCardArtwork::camouflage(153, 64, 11), IdCardArtwork::camouflage(153, 64, 12));
        $this->assertStringContainsString(IdCardArtwork::CAMOUFLAGE[0], IdCardArtwork::camouflage(153, 15.5, 23));
        // The PDF gets the same pieces as PNG (embedded once per file instead of redrawn on every page).
        foreach (IdCardArtwork::dataUris(forPdf: true) as $uri) {
            $this->assertStringStartsWith('data:image/png;base64,', $uri);
        }
        [$width, $height] = getimagesizefromstring(base64_decode(substr(IdCardArtwork::dataUris(forPdf: true)['frontHeader'], strlen('data:image/png;base64,'))));
        $this->assertSame([612, 256], [$width, $height]);
    }

    public function test_long_names_get_smaller_type_so_they_stay_on_one_line(): void
    {
        $this->candidateInA->forceFill(['last_name' => 'Dela Cruz-Villanueva Santos', 'first_name' => 'Maria Angelica Concepcion', 'middle_name' => 'Villareal Bautista'])->save();

        $sizes = CandidateIdCard::data($this->candidateInA->fresh())['textSizes'];

        $this->assertSame(7.0, $sizes['lastName']);
        $this->assertSame(5.6, $sizes['givenNames']);
    }

    public function test_the_pdf_is_two_card_sized_pages_and_is_recorded(): void
    {
        $response = $this->actingAs($this->admin)->get(route('candidates.id-card.pdf', $this->candidateInA))->assertOk();

        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('attachment;', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        // Front and back at 153 x 243 pt (CR80, 54 x 85.6 mm).
        $this->assertSame([2, 153.0, 243.0], $this->pages($response));

        $entry = AuditLog::query()->where('action', AuditAction::CandidateIdCardDownloaded->value)->sole();
        $this->assertSame($this->candidateInA->id, (int) $entry->auditable_id);
        $this->assertSame(['cards' => 1], $entry->new_values);
    }

    public function test_the_class_pdf_has_a_card_for_every_candidate_in_training(): void
    {
        // Batch A: two enrolled candidates and one withdrawn.
        $response = $this->actingAs($this->admin)->get(route('classes.id-cards', $this->batchA))->assertOk();
        $this->assertSame([4, 153.0, 243.0], $this->pages($response));
        $entry = AuditLog::query()->where('action', AuditAction::CandidateIdCardDownloaded->value)->sole();
        $this->assertSame(['cards' => 2], $entry->new_values);

        $empty = ClassBatch::factory()->for($this->activePeriod)->create(['name' => 'Empty Batch']);
        $this->actingAs($this->admin)->get(route('classes.id-cards', $empty))->assertNotFound();

        $this->actingAs($this->admin)->get(route('classes.show', $this->batchA))
            ->assertInertia(fn (Assert $page) => $page->where('can.printIdCards', true));
    }

    public function test_the_picture_is_cropped_to_the_card_frame(): void
    {
        Storage::fake('local');
        $path = UploadedFile::fake()->image('wide.jpg', 800, 400)->store('candidate-photos', 'local');
        $this->candidateInA->forceFill(['profile_photo_path' => $path])->save();

        $uri = CandidateIdCard::photoDataUri($this->candidateInA->fresh());
        $this->assertNotNull($uri);
        $this->assertStringStartsWith('data:image/jpeg;base64,', $uri);
        [$width, $height] = getimagesizefromstring(base64_decode(substr($uri, strlen('data:image/jpeg;base64,'))));
        $this->assertEqualsWithDelta(17 / 20, $width / $height, 0.01);

        $this->actingAs($this->admin)->get(route('candidates.id-card', $this->candidateInA))
            ->assertInertia(fn (Assert $page) => $page->where('card.photoUrl', route('candidates.photo', $this->candidateInA, false)));
        $this->actingAs($this->admin)->get(route('candidates.id-card.pdf', $this->candidateInA))->assertOk();
    }

    public function test_only_administrators_of_the_campus_reach_id_cards(): void
    {
        $this->actingAs($this->admin)->get(route('candidates.show', $this->candidateInA))
            ->assertInertia(fn (Assert $page) => $page->where('idCardUrl', route('candidates.id-card', $this->candidateInA, false)));

        // Instructors of the class: the profile, but no ID card (the administrator side for now).
        $this->actingAs($this->alpha)->get(route('candidates.show', $this->candidateInA))
            ->assertInertia(fn (Assert $page) => $page->where('idCardUrl', null));
        $this->actingAs($this->alpha)->get(route('candidates.id-card', $this->candidateInA))->assertForbidden();
        $this->actingAs($this->alpha)->get(route('candidates.id-card.pdf', $this->candidateInA))->assertForbidden();
        $this->actingAs($this->alpha)->get(route('classes.id-cards', $this->batchA))->assertForbidden();

        $this->actingAs($this->candidateInA->user)->get(route('candidates.id-card', $this->candidateInA))->assertForbidden();
        $this->actingAs($this->userWithRole(SystemRole::Dietitian))->get(route('candidates.id-card', $this->candidateInA))->assertForbidden();

        // An administrator of another campus: the card does not exist for them.
        $northAdmin = User::factory()->withRole(SystemRole::SuperAdministrator)->onCampus(CampusFactory::fixed(CampusCode::North))->create();
        $this->actingAs($northAdmin)->get(route('candidates.id-card', $this->candidateInA))->assertNotFound();
        $this->actingAs($northAdmin)->get(route('classes.id-cards', $this->batchA))->assertNotFound();

        $this->assertSame(0, AuditLog::query()->where('action', AuditAction::CandidateIdCardDownloaded->value)->count());
        $this->assertInstanceOf(Candidate::class, $this->candidateInA);
    }
}
