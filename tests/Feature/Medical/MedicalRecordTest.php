<?php

namespace Tests\Feature\Medical;

use App\Enums\SystemRole;
use App\Models\AuditLog;
use App\Models\CandidateMedicalRevision;
use App\Models\CandidateMedicalValue;
use App\Models\MedicalField;
use App\Models\User;
use Database\Seeders\DemoMedicalSeeder;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Teaching\BuildsTeachingFixtures;
use Tests\TestCase;

/**
 * Candidate medical records (owner request, 2026-10-02): administrators
 * define the fields and record the values; instructors see only the fields
 * shared with instructors, for candidates of the classes they teach; the
 * candidate sees the fields shared with candidates. Medical values never
 * reach the general audit log.
 */
class MedicalRecordTest extends TestCase
{
    use BuildsTeachingFixtures;

    private User $admin;

    private MedicalField $bloodType;

    private MedicalField $allergies;

    private MedicalField $remarks;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildTeachingFixtures();
        $this->admin = $this->userWithRole(SystemRole::SuperAdministrator, ['name' => 'Medical Admin']);

        $this->bloodType = $this->createField(['name' => 'Blood Type', 'field_type' => 'choice', 'options' => "A+\nB+\nO+", 'section' => 'General Information']);
        $this->allergies = $this->createField(['name' => 'Allergies', 'field_type' => 'long_text', 'section' => 'Medical History']);
        $this->remarks = $this->createField(['name' => "Physician's Remarks", 'field_type' => 'long_text', 'visible_to_candidate' => false]);
    }

    public function test_administrators_configure_the_fields(): void
    {
        $this->assertSame(['A+', 'B+', 'O+'], $this->bloodType->choiceOptions());
        $this->assertSame('General Information', $this->bloodType->section);
        $this->assertFalse($this->remarks->visible_to_candidate);
        $this->assertSame(3, AuditLog::query()->where('action', 'medical_field.created')->count());

        // A choice field needs at least two different choices.
        $this->postField(['name' => 'Cleared', 'field_type' => 'choice', 'options' => 'Yes'])->assertSessionHasErrors('options');
        $this->postField(['name' => 'Cleared', 'field_type' => 'choice', 'options' => "Yes\nyes"])->assertSessionHasErrors('options');
        $this->postField(['name' => 'Blood Type', 'field_type' => 'text'])->assertSessionHasErrors('name');

        $this->saveRecord([$this->bloodType->id => 'O+']);

        // In use: the type is fixed and a recorded choice cannot be removed.
        $this->putField($this->bloodType, ['field_type' => 'text'])->assertSessionHasErrors('field_type');
        $this->putField($this->bloodType, ['options' => "A+\nB+"])->assertSessionHasErrors('options');
        $this->putField($this->bloodType, ['options' => "O+\nAB+"])->assertSessionHasNoErrors();
        $this->assertSame(['O+', 'AB+'], $this->bloodType->fresh()->choiceOptions());

        // Used fields are deactivated, unused ones may be deleted.
        $this->actingAs($this->admin)->delete(route('medical.fields.destroy', $this->bloodType))->assertSessionHasErrors('field');
        $this->actingAs($this->admin)->delete(route('medical.fields.destroy', $this->remarks))->assertSessionHasNoErrors();
        $this->assertNull($this->remarks->fresh());
    }

    public function test_recording_keeps_a_medical_history_and_audits_field_names_only(): void
    {
        $this->saveRecord([$this->bloodType->id => 'A+', $this->allergies->id => 'Penicillin (rash).'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('medical.records.edit', $this->candidateInA));

        $this->assertSame('Penicillin (rash).', $this->value($this->allergies));
        $this->assertSame(2, CandidateMedicalRevision::query()->count());

        $audit = AuditLog::query()->where('action', 'medical_record.updated')->sole();
        $this->assertSame(['Blood Type', 'Allergies'], $audit->new_values['fields']);
        $this->assertStringNotContainsString('Penicillin', json_encode($audit->getAttributes()) ?: '');

        // Clearing a value removes it and is kept in the history too.
        $this->saveRecord([$this->bloodType->id => 'A+', $this->allergies->id => ''])->assertSessionHasNoErrors();
        $this->assertNull($this->value($this->allergies));
        $latest = CandidateMedicalRevision::query()->latest('id')->firstOrFail();
        $this->assertSame('Penicillin (rash).', $latest->previous_value);
        $this->assertNull($latest->new_value);
        $this->assertSame($this->admin->id, (int) $latest->changed_by);

        $this->actingAs($this->admin)->get(route('medical.records.edit', $this->candidateInA))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('staff/medical/edit')->has('fields', 3)->has('history', 3)->where('history.0.field', 'Allergies'));
    }

    public function test_values_are_checked_against_their_kind_of_answer(): void
    {
        $date = $this->createField(['name' => 'Last Physical Examination', 'field_type' => 'date']);
        $cleared = $this->createField(['name' => 'Cleared', 'field_type' => 'yes_no']);
        $physician = $this->createField(['name' => 'Physician', 'field_type' => 'text']);

        $this->saveRecord([$this->bloodType->id => 'Z+'])->assertSessionHasErrors("values.{$this->bloodType->id}");
        $this->saveRecord([$date->id => '2026-02-30'])->assertSessionHasErrors("values.{$date->id}");
        $this->saveRecord([$cleared->id => 'maybe'])->assertSessionHasErrors("values.{$cleared->id}");
        $this->saveRecord([$physician->id => str_repeat('x', 256)])->assertSessionHasErrors("values.{$physician->id}");
        $this->assertSame(0, CandidateMedicalValue::query()->count());

        $this->saveRecord([$date->id => '2026-08-12', $cleared->id => 'yes', $physician->id => 'Dr. Placeholder'])->assertSessionHasNoErrors();
        $this->assertSame('yes', $this->value($cleared));
    }

    public function test_instructors_see_no_medical_information_without_approved_access(): void
    {
        $this->saveRecord([$this->bloodType->id => 'O+', $this->allergies->id => 'Shellfish.', $this->remarks->id => 'Staff-only remark.']);

        $this->actingAs($this->admin)->get(route('candidates.show', $this->candidateInA))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('medical.scope', 'full')->has('medical.entries', 3)->where('medical.canEdit', true));

        // Owner decision (2026-10-02): no field at all, only the way to request access.
        $response = $this->actingAs($this->alpha)->get(route('candidates.show', $this->candidateInA))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('medical.scope', 'instructor')
                ->has('medical.entries', 0)
                ->where('medical.canEdit', false)
                ->where('medical.updatedAt', null)
                ->where('medical.access.canRequest', true));
        foreach (['Shellfish.', 'Staff-only remark.', "Physician's Remarks", 'Blood Type', 'O+'] as $hidden) {
            $this->assertStringNotContainsString($hidden, $response->getContent());
        }

        // Nothing medical to manage or browse for instructors.
        $this->actingAs($this->alpha)->get(route('medical.records.index'))->assertForbidden();
        $this->actingAs($this->alpha)->get(route('medical.records.edit', $this->candidateInA))->assertForbidden();
        $this->actingAs($this->alpha)->put(route('medical.records.update', $this->candidateInA), ['values' => [$this->bloodType->id => 'A+']])->assertForbidden();
        $this->actingAs($this->alpha)->get(route('medical.fields.index'))->assertForbidden();
        $this->assertSame('O+', $this->value($this->bloodType));

        // Any instructor of a candidate's class gets the same view of that candidate.
        $this->actingAs($this->bravo)->get(route('candidates.show', $this->candidateInB))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('medical.scope', 'instructor'));
    }

    public function test_candidates_see_their_own_shared_fields_only(): void
    {
        $this->saveRecord([$this->bloodType->id => 'B+', $this->remarks->id => 'Staff-only remark.']);

        $response = $this->actingAs($this->candidateInA->user)->get('/portal/profile')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('portal/profile')
                ->has('medical', 2)
                ->where('medical.0.name', 'Blood Type')
                ->where('medical.0.value', 'B+')
                ->where('medical.1.value', null));
        $this->assertStringNotContainsString('Staff-only remark.', $response->getContent());

        // Another candidate sees their own (empty) record, never this one.
        $this->actingAs($this->candidateInB->user)->get('/portal/profile')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('medical.0.value', null));
        $this->actingAs($this->candidateInA->user)->get(route('medical.records.index'))->assertForbidden();
    }

    public function test_number_fields_take_measurements_with_a_unit(): void
    {
        $height = $this->createField(['name' => 'Height', 'field_type' => 'number', 'unit' => 'cm', 'section' => 'General Information']);
        $notes = $this->createField(['name' => 'Notes', 'field_type' => 'text', 'unit' => 'cm']);
        $this->assertSame('cm', $height->unit);
        $this->assertNull($notes->unit, 'Only number fields keep a unit.');

        foreach (['tall', '170.555', '-5', '100000'] as $bad) {
            $this->saveRecord([$height->id => $bad])->assertSessionHasErrors("values.{$height->id}");
        }
        $this->saveRecord([$height->id => '171.5'])->assertSessionHasNoErrors();

        $this->actingAs($this->admin)->get(route('candidates.show', $this->candidateInA))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('medical.entries.3.name', 'Height')
                ->where('medical.entries.3.section', 'General Information')
                ->where('medical.entries.3.unit', 'cm')
                ->where('medical.entries.3.value', '171.5'));
    }

    public function test_inactive_fields_are_hidden_and_ignored(): void
    {
        $this->saveRecord([$this->allergies->id => 'None known.']);
        $this->putField($this->allergies, ['is_active' => false]);

        $this->saveRecord([$this->allergies->id => 'Changed while inactive.', $this->bloodType->id => 'A+'])->assertSessionHasNoErrors();
        $this->assertSame('None known.', $this->value($this->allergies));

        $this->actingAs($this->admin)->get(route('candidates.show', $this->candidateInA))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('medical.entries', 2));
        $this->actingAs($this->admin)->get(route('medical.records.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('staff/medical/index')->where('fieldCount', 2));
    }

    public function test_the_demo_seeder_is_repeatable(): void
    {
        $this->seed(DemoMedicalSeeder::class);
        $this->seed(DemoMedicalSeeder::class);

        // Blood Type, Allergies and Physician's Remarks already existed and were kept.
        $this->assertSame(count(array_merge(...array_values(DemoMedicalSeeder::SECTIONS))), MedicalField::query()->count());
        $this->assertSame('cm', MedicalField::query()->where('name', 'Height')->sole()->unit);
        $this->assertSame('Fitness for Training', MedicalField::query()->where('name', 'Medical Classification')->sole()->section);
        $this->assertSame(['A+', 'B+', 'O+'], MedicalField::query()->where('name', 'Blood Type')->sole()->choiceOptions());
    }

    // ---------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $data
     */
    private function createField(array $data): MedicalField
    {
        $this->postField($data)->assertSessionHasNoErrors();

        return MedicalField::query()->where('name', $data['name'])->sole();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function postField(array $data): TestResponse
    {
        return $this->actingAs($this->admin)->post(route('medical.fields.store'), [
            'options' => null,
            'help_text' => null,
            'sort_order' => (string) (MedicalField::query()->count() + 1),
            'visible_to_candidate' => true,
            ...$data,
        ]);
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function putField(MedicalField $field, array $changes): TestResponse
    {
        $field->refresh();

        return $this->actingAs($this->admin)->put(route('medical.fields.update', $field), [
            'name' => $field->name,
            'field_type' => $field->field_type->value,
            'options' => implode("\n", $field->choiceOptions()),
            'help_text' => $field->help_text,
            'sort_order' => (string) $field->sort_order,
            'section' => $field->section,
            'unit' => $field->unit,
            'visible_to_candidate' => $field->visible_to_candidate,
            'is_active' => $field->is_active,
            ...$changes,
        ]);
    }

    /**
     * @param  array<int, string>  $values
     */
    private function saveRecord(array $values): TestResponse
    {
        return $this->actingAs($this->admin)->put(route('medical.records.update', $this->candidateInA), ['values' => $values]);
    }

    private function value(MedicalField $field): ?string
    {
        return CandidateMedicalValue::query()->where('candidate_id', $this->candidateInA->id)->where('medical_field_id', $field->id)->value('value');
    }
}
