<?php

namespace Tests\Feature\Candidates;

use App\Enums\AuditAction;
use App\Enums\SystemRole;
use App\Models\AcademicPeriod;
use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\ClassBatch;
use App\Models\User;
use App\Services\CandidatePdfService;
use App\Services\CandidateService;
use App\Support\CandidateGroups;
use App\Support\CandidatePresenter;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Company and platoon (owner request, 2026-10-01): optional free-text unit
 * assignments on the candidate record, audited like the other fields and
 * offered as list filters built from the names in use.
 */
class CandidateGroupsTest extends TestCase
{
    private User $admin;

    private ClassBatch $classBatch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->userWithRole(SystemRole::AcademicAdministrator);
        $this->classBatch = ClassBatch::factory()->for(AcademicPeriod::factory()->active())->create(['name' => 'Sample Batch A']);
    }

    public function test_company_and_platoon_are_stored_normalized_and_audited_on_create(): void
    {
        $this->actingAs($this->admin)
            ->post('/candidates', $this->payload(['company' => '  Alpha   Company ', 'platoon' => ' 1st Platoon']))
            ->assertSessionHasNoErrors();

        $candidate = Candidate::query()->where('candidate_number', 'OC-0214')->sole();
        $this->assertSame('Alpha Company', $candidate->company);
        $this->assertSame('1st Platoon', $candidate->platoon);

        $entry = AuditLog::query()->where('action', AuditAction::CandidateCreated->value)->sole();
        $this->assertSame('Alpha Company', $entry->new_values['company']);
        $this->assertSame('1st Platoon', $entry->new_values['platoon']);
    }

    public function test_both_fields_are_optional_and_blank_values_are_stored_as_null(): void
    {
        $this->actingAs($this->admin)
            ->post('/candidates', $this->payload(['company' => '   ', 'platoon' => '']))
            ->assertSessionHasNoErrors();
        $this->actingAs($this->admin)
            ->post('/candidates', $this->payload(['candidate_number' => 'OC-0215']))
            ->assertSessionHasNoErrors();

        foreach (Candidate::query()->get() as $candidate) {
            $this->assertNull($candidate->company);
            $this->assertNull($candidate->platoon);
        }
    }

    public function test_changes_are_audited_with_previous_and_new_values(): void
    {
        $candidate = Candidate::factory()->create(['class_batch_id' => $this->classBatch->id, 'company' => 'Alpha Company', 'platoon' => '1st Platoon']);

        $this->actingAs($this->admin)
            ->put("/candidates/{$candidate->id}", $this->updatePayload($candidate, ['company' => 'Bravo Company', 'platoon' => '']))
            ->assertRedirect(route('candidates.show', $candidate));

        $candidate->refresh();
        $this->assertSame('Bravo Company', $candidate->company);
        $this->assertNull($candidate->platoon);

        $entry = AuditLog::query()->where('action', AuditAction::CandidateUpdated->value)->sole();
        $this->assertSame(['company' => 'Alpha Company', 'platoon' => '1st Platoon'], $entry->old_values);
        $this->assertSame(['company' => 'Bravo Company', 'platoon' => null], $entry->new_values);
    }

    public function test_an_update_without_the_fields_keeps_them(): void
    {
        $candidate = Candidate::factory()->create(['company' => 'Alpha Company', 'platoon' => '1st Platoon']);
        $payload = $this->updatePayload($candidate, ['last_name' => 'Sample']);
        unset($payload['company'], $payload['platoon']);

        $this->actingAs($this->admin)->put("/candidates/{$candidate->id}", $payload)->assertSessionHasNoErrors();

        $candidate->refresh();
        $this->assertSame('Alpha Company', $candidate->company);
        $this->assertSame('1st Platoon', $candidate->platoon);
        $entry = AuditLog::query()->where('action', AuditAction::CandidateUpdated->value)->sole();
        $this->assertSame(['last_name'], array_keys($entry->new_values));
    }

    public function test_names_longer_than_fifty_characters_or_not_text_are_refused(): void
    {
        $this->actingAs($this->admin)
            ->post('/candidates', $this->payload(['company' => str_repeat('A', 51), 'platoon' => ['1st Platoon']]))
            ->assertSessionHasErrors(['company', 'platoon']);
        $this->assertSame(0, Candidate::query()->count());

        // Fifty characters after trimming is accepted.
        $this->actingAs($this->admin)
            ->post('/candidates', $this->payload(['company' => '  '.str_repeat('A', 50).'  ']))
            ->assertSessionHasNoErrors();
        $this->assertSame(str_repeat('A', 50), Candidate::query()->sole()->company);
    }

    public function test_the_list_shows_and_filters_by_company_and_platoon(): void
    {
        $this->candidate('A-1', 'Alpha Company', '1st Platoon');
        $this->candidate('A-2', 'Alpha Company', '2nd Platoon');
        $this->candidate('B-1', 'Bravo Company', '1st Platoon');
        $this->candidate('B-10', 'Bravo Company', '10th Platoon');
        $this->candidate('N-1', null, null);

        $this->actingAs($this->admin)->get('/candidates')
            ->assertInertia(fn (Assert $page) => $page->component('staff/candidates/index')
                ->has('candidates.data', 5)
                ->where('companyOptions', ['Alpha Company', 'Bravo Company'])
                ->where('platoonOptions', ['1st Platoon', '2nd Platoon', '10th Platoon'])
                ->where('filters.company', '')
                ->where('filters.platoon', ''));

        $this->actingAs($this->admin)->get('/candidates?company=Alpha%20Company')
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.company', 'Alpha Company')
                ->where('candidates.data', fn ($rows): bool => collect($rows)->pluck('candidateNumber')->sort()->values()->all() === ['A-1', 'A-2']
                    && collect($rows)->every(fn (array $row): bool => $row['company'] === 'Alpha Company')));

        $this->actingAs($this->admin)->get('/candidates?company=Bravo%20Company&platoon=1st%20Platoon')
            ->assertInertia(fn (Assert $page) => $page
                ->has('candidates.data', 1)
                ->where('candidates.data.0.candidateNumber', 'B-1')
                ->where('candidates.data.0.company', 'Bravo Company')
                ->where('candidates.data.0.platoon', '1st Platoon'));

        // Names not in use (or arrays) are ignored like other invalid filters.
        $this->actingAs($this->admin)->get('/candidates?company=Charlie%20Company&platoon[]=x')
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.company', '')
                ->where('filters.platoon', '')
                ->has('candidates.data', 5));
    }

    public function test_candidate_groups_lists_distinct_names_in_use(): void
    {
        $other = ClassBatch::factory()->for($this->classBatch->academicPeriod)->create();
        $this->candidate('A-1', 'Alpha Company', '2nd Platoon');
        $this->candidate('A-2', 'Alpha Company', '10th Platoon');
        $this->candidate('B-1', 'bravo company', null, $other);
        // Rows written outside the request normalization: empty strings are not names.
        Candidate::factory()->create(['company' => '', 'platoon' => '']);

        $this->assertSame(['Alpha Company', 'bravo company'], CandidateGroups::companies());
        $this->assertSame(['2nd Platoon', '10th Platoon'], CandidateGroups::platoons());
        $this->assertSame(['Alpha Company'], CandidateGroups::companies($this->classBatch->id));
        $this->assertSame([], CandidateGroups::platoons($other->id));
    }

    public function test_profile_edit_and_create_pages_carry_the_fields(): void
    {
        $candidate = $this->candidate('A-1', 'Alpha Company', '1st Platoon');

        $details = CandidatePresenter::details($candidate);
        $this->assertSame('Alpha Company', $details['company']);
        $this->assertSame('1st Platoon', $details['platoon']);

        $this->actingAs($this->admin)->get("/candidates/{$candidate->id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('candidate.company', 'Alpha Company')->where('candidate.platoon', '1st Platoon'));

        $this->actingAs($this->admin)->get("/candidates/{$candidate->id}/edit")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('staff/candidates/edit')
                ->where('candidate.company', 'Alpha Company')
                ->where('candidate.platoon', '1st Platoon')
                ->where('companyOptions', ['Alpha Company'])
                ->where('platoonOptions', ['1st Platoon']));

        $this->actingAs($this->admin)->get('/candidates/create')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('staff/candidates/create')
                ->where('companyOptions', ['Alpha Company'])
                ->where('platoonOptions', ['1st Platoon']));
    }

    public function test_a_candidate_sees_only_their_own_company_and_platoon(): void
    {
        $candidate = $this->candidate('A-1', 'Alpha Company', '1st Platoon');
        $this->candidate('B-1', 'Bravo Company', '2nd Platoon');

        $this->actingAs($candidate->user)->get('/portal/profile')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('portal/profile')
                ->where('candidate.company', 'Alpha Company')
                ->where('candidate.platoon', '1st Platoon')
                ->missing('companyOptions')
                ->missing('platoonOptions'));
    }

    public function test_the_registration_record_prints_company_and_platoon(): void
    {
        $candidate = $this->candidate('A-1', 'Alpha Company', '1st Platoon');
        $unassigned = $this->candidate('N-1', null, null);
        $pdfs = app(CandidatePdfService::class);

        $html = view('pdf.candidate-record', [...$pdfs->data($this->admin, $candidate, 'registration'), 'logo' => null])->render();
        $this->assertStringContainsString('Company / Platoon:', $html);
        $this->assertStringContainsString('Alpha Company / 1st Platoon', $html);

        $html = view('pdf.candidate-record', [...$pdfs->data($this->admin, $unassigned, 'registration'), 'logo' => null])->render();
        $this->assertMatchesRegularExpression('~Company / Platoon:</td><td class="v">Not assigned~', $html);
    }

    public function test_the_service_assigns_company_and_platoon_with_an_audit_entry(): void
    {
        $candidate = $this->candidate('A-1', null, '1st Platoon');
        $service = app(CandidateService::class);

        $service->assignCompanyAndPlatoon($candidate, ' Alpha  Company ', '1st Platoon');

        $candidate->refresh();
        $this->assertSame('Alpha Company', $candidate->company);
        $this->assertSame('1st Platoon', $candidate->platoon);
        $entry = AuditLog::query()->where('action', AuditAction::CandidateUpdated->value)->sole();
        $this->assertSame(['company' => null], $entry->old_values);
        $this->assertSame(['company' => 'Alpha Company'], $entry->new_values);

        // No change, no entry; blank clears; too long is refused.
        $service->assignCompanyAndPlatoon($candidate, 'Alpha Company', '1st Platoon');
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::CandidateUpdated->value)->count());
        $service->assignCompanyAndPlatoon($candidate, 'Alpha Company', '  ');
        $this->assertNull($candidate->fresh()->platoon);

        $this->expectException(ValidationException::class);
        $service->assignCompanyAndPlatoon($candidate, str_repeat('A', 51), null);
    }

    public function test_instructors_cannot_change_company_or_platoon(): void
    {
        $candidate = $this->candidate('A-1', 'Alpha Company', '1st Platoon');

        $this->actingAs($this->userWithRole(SystemRole::Instructor))
            ->put("/candidates/{$candidate->id}", $this->updatePayload($candidate, ['company' => 'Bravo Company']))
            ->assertForbidden();

        $this->assertSame('Alpha Company', $candidate->fresh()->company);
    }

    private function candidate(string $number, ?string $company, ?string $platoon, ?ClassBatch $classBatch = null): Candidate
    {
        return Candidate::factory()->create([
            'candidate_number' => $number,
            'class_batch_id' => ($classBatch ?? $this->classBatch)->id,
            'company' => $company,
            'platoon' => $platoon,
        ]);
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
            'company' => $candidate->company ?? '',
            'platoon' => $candidate->platoon ?? '',
            'class_batch_id' => $candidate->class_batch_id ?? '',
            'status' => $candidate->status->value,
            'account_active' => true,
            'password' => '',
            'password_confirmation' => '',
            ...$overrides,
        ];
    }
}
