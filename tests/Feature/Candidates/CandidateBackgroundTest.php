<?php

namespace Tests\Feature\Candidates;

use App\Enums\AuditAction;
use App\Enums\SystemRole;
use App\Models\AuditLog;
use App\Models\CandidateEducation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Teaching\BuildsTeachingFixtures;
use Tests\TestCase;

/**
 * The candidate's background record (owner request, 2026-10-03): personal
 * details and emergency contact for administrators and the candidate only;
 * education and service background also for instructors of the class.
 */
class CandidateBackgroundTest extends TestCase
{
    use BuildsTeachingFixtures;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-03 09:00:00'));
        $this->buildTeachingFixtures();
        $this->admin = $this->userWithRole(SystemRole::AcademicAdministrator);
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return [
            'date_of_birth' => '2000-05-14',
            'place_of_birth' => '  Tarlac City,   Tarlac ',
            'sex' => 'male',
            'civil_status' => 'single',
            'home_address' => '12 Sample Street, Tarlac City',
            'mobile_number' => '0917 555 0101',
            'personal_email' => 'candidate@example.org',
            'emergency_contact_name' => 'Maria Example',
            'emergency_contact_relationship' => 'Mother',
            'emergency_contact_phone' => '+63 918 555 0202',
            'eligibility' => 'Civil Service Professional',
            'prior_service' => 'ROTC Advance Course',
            'previous_occupation' => '',
            'education' => [
                ['level' => 'bachelor', 'degree' => 'BS Criminology', 'school' => 'State University of the North', 'year_graduated' => '2021', 'honors' => 'Cum Laude'],
                ['level' => 'master', 'degree' => 'Master in Public Administration', 'school' => 'State University of the North', 'year_graduated' => '', 'honors' => ''],
            ],
            ...$overrides,
        ];
    }

    private function save(array $payload, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->admin)->put("/candidates/{$this->candidateInA->id}/background", $payload);
    }

    public function test_an_administrator_records_the_background_and_sees_all_of_it(): void
    {
        $this->save($this->payload())->assertRedirect("/candidates/{$this->candidateInA->id}")->assertSessionHasNoErrors();

        $this->get("/candidates/{$this->candidateInA->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('background.scope', 'full')
            ->where('background.personal.dateOfBirth', '2000-05-14')
            ->where('background.personal.age', 26)
            ->where('background.personal.placeOfBirth', 'Tarlac City, Tarlac')
            ->where('background.personal.sex', 'Male')
            ->where('background.personal.civilStatus', 'Single')
            ->where('background.personal.emergencyContact', ['name' => 'Maria Example', 'relationship' => 'Mother', 'phone' => '+63 918 555 0202'])
            ->where('background.service', ['eligibility' => 'Civil Service Professional', 'priorService' => 'ROTC Advance Course', 'previousOccupation' => null])
            ->where('background.education.0.degree', 'BS Criminology')->where('background.education.0.levelLabel', "Bachelor's Degree")
            ->where('background.education.0.yearGraduated', 2021)->where('background.education.0.honors', 'Cum Laude')
            ->where('background.education.1.yearGraduated', null)->where('background.education.1.honors', null)
            ->where('backgroundEditUrl', route('candidates.background.edit', $this->candidateInA)));
    }

    public function test_changes_are_audited_without_contact_details_in_the_log(): void
    {
        $this->save($this->payload());
        $entry = AuditLog::query()->where('action', AuditAction::CandidateBackgroundUpdated->value)->sole();

        $this->assertSame('Civil Service Professional', $entry->new_values['eligibility']);
        $this->assertStringContainsString("Bachelor's Degree: BS Criminology, State University of the North (2021)", $entry->new_values['education']);
        foreach (['home_address', 'mobile_number', 'personal_email', 'emergency_contact_phone'] as $field) {
            $this->assertMatchesRegularExpression('/^recorded #[0-9a-f]{8}$/', $entry->new_values[$field]);
        }
        $this->assertStringNotContainsString('0917 555 0101', json_encode($entry->new_values));
        $this->assertStringNotContainsString('candidate@example.org', json_encode($entry->new_values));

        // Saving the same values records nothing; a new number is recorded as a change.
        $this->save($this->payload());
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::CandidateBackgroundUpdated->value)->count());
        $this->save($this->payload(['mobile_number' => '0917 555 0199']));
        $last = AuditLog::query()->where('action', AuditAction::CandidateBackgroundUpdated->value)->latest('id')->first();
        $this->assertSame(['mobile_number'], array_keys($last->new_values));
    }

    public function test_education_is_replaced_and_can_be_cleared(): void
    {
        $this->save($this->payload());
        $this->save($this->payload(['education' => [['level' => 'vocational', 'degree' => 'Automotive Servicing NC II', 'school' => 'Technical Institute', 'year_graduated' => '2018', 'honors' => '']]]));
        $this->assertSame(['Automotive Servicing NC II'], CandidateEducation::query()->where('candidate_id', $this->candidateInA->id)->pluck('degree')->all());

        $this->save($this->payload(['education' => []]))->assertSessionHasNoErrors();
        $this->assertSame(0, CandidateEducation::query()->where('candidate_id', $this->candidateInA->id)->count());
    }

    public function test_values_are_validated(): void
    {
        $this->actingAs($this->admin);
        $this->save($this->payload([
            'date_of_birth' => '2026-12-01',
            'sex' => 'other',
            'civil_status' => 'engaged',
            'mobile_number' => 'call me',
            'personal_email' => 'not-an-email',
            'education' => [['level' => 'bachelor', 'degree' => '', 'school' => '', 'year_graduated' => '2030', 'honors' => '']],
        ]))->assertSessionHasErrors([
            'date_of_birth', 'sex', 'civil_status', 'mobile_number', 'personal_email',
            'education.0.degree', 'education.0.school', 'education.0.year_graduated',
        ]);
        $this->save($this->payload(['education' => array_fill(0, 7, ['level' => 'other', 'degree' => 'Seminar', 'school' => 'School'])]))
            ->assertSessionHasErrors(['education' => 'Record at most 6 education entries.']);

        $this->assertSame(0, CandidateEducation::query()->count());
        $this->assertNull($this->candidateInA->background()->first());
    }

    public function test_instructors_see_education_and_service_but_no_personal_details_and_cannot_edit(): void
    {
        $this->save($this->payload());

        // Alpha teaches Batch A.
        $this->actingAs($this->alpha)->get("/candidates/{$this->candidateInA->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('background.scope', 'service')
            ->where('background.personal', null)
            ->where('background.service.eligibility', 'Civil Service Professional')
            ->has('background.education', 2)
            ->where('backgroundEditUrl', null));
        $response = $this->actingAs($this->alpha)->get("/candidates/{$this->candidateInA->id}");
        $this->assertStringNotContainsString('0917 555 0101', $response->getContent());
        $this->assertStringNotContainsString('Maria Example', $response->getContent());

        $this->actingAs($this->alpha)->get("/candidates/{$this->candidateInA->id}/background/edit")->assertForbidden();
        $this->save($this->payload(['eligibility' => 'Changed']), $this->alpha)->assertForbidden();
        $this->assertSame('Civil Service Professional', $this->candidateInA->background()->first()->eligibility);
    }

    public function test_the_candidate_sees_their_own_background_only_in_the_portal(): void
    {
        $this->save($this->payload());

        $this->actingAs($this->candidateInA->user)->get('/portal/profile')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('background.scope', 'full')
            ->where('background.personal.mobileNumber', '0917 555 0101')
            ->has('background.education', 2));
        $this->actingAs($this->candidateInB->user)->get('/portal/profile')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('background.personal.mobileNumber', null)
            ->has('background.education', 0));

        $this->actingAs($this->candidateInA->user)->get("/candidates/{$this->candidateInA->id}/background/edit")->assertForbidden();
    }

    public function test_the_edit_page_is_prefilled(): void
    {
        $this->save($this->payload());

        $this->actingAs($this->admin)->get("/candidates/{$this->candidateInA->id}/background/edit")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('staff/candidates/background')
            ->where('background.date_of_birth', '2000-05-14')
            ->where('background.previous_occupation', '')
            ->where('background.education.0.year_graduated', '2021')
            ->where('background.education.1.year_graduated', '')
            ->has('options.educationLevel', 5));
    }
}
