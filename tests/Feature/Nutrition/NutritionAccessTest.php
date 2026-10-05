<?php

namespace Tests\Feature\Nutrition;

use App\Enums\CampusCode;
use App\Enums\SystemRole;
use App\Models\Candidate;
use App\Models\CandidateDietaryProfile;
use App\Models\ClassBatch;
use App\Models\NutritionAssessment;
use App\Models\Role;
use App\Models\User;
use Database\Factories\CampusFactory;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Teaching\BuildsTeachingFixtures;
use Tests\TestCase;

/**
 * Who sees what of nutrition records (owner decisions 2026-10-05):
 * dietitians and administrators of the campus see everything; instructors
 * of the class only the BMI category and allergies/restrictions; the
 * candidate their own measurements and plan; nobody another campus.
 */
class NutritionAccessTest extends TestCase
{
    use BuildsTeachingFixtures;

    private User $dietitian;

    private Candidate $northCandidate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildTeachingFixtures();
        $this->dietitian = $this->userWithRole(SystemRole::Dietitian, ['name' => 'Dietitian South']);
        $northClass = ClassBatch::factory()->for($this->activePeriod)->onCampus(CampusFactory::fixed(CampusCode::North))->create(['name' => 'North Batch']);
        $this->northCandidate = Candidate::factory()->create(['class_batch_id' => $northClass->id, 'last_name' => 'North1']);
    }

    private function assess(Candidate $candidate, array $overrides = []): NutritionAssessment
    {
        $this->actingAs($this->dietitian)->post(route('nutrition.assessments.store', $candidate), [
            'assessed_on' => now()->subDays(3)->toDateString(),
            'height_cm' => '170',
            'weight_kg' => '70',
            'waist_cm' => '80',
            'diagnosis' => 'Secret diagnosis text',
            'diet_history' => 'Secret diet history',
            'plan' => 'Eat breakfast every day.',
            ...$overrides,
        ])->assertSessionHasNoErrors();

        return NutritionAssessment::query()->where('candidate_id', $candidate->id)->latest('id')->firstOrFail();
    }

    public function test_a_dietitian_belongs_to_one_campus_and_sees_only_its_candidates(): void
    {
        $this->assertSame(CampusFactory::defaultCampusId(), $this->dietitian->campus_id);

        $this->actingAs($this->dietitian)->get(route('nutrition.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('staff/nutrition/index')
                // Batch A: 2 enrolled (the withdrawn one is left out); Batch B: 1. Not the north candidate.
                ->where('counts.total', 3)
                ->where('counts.notAssessed', 3)
                ->where('candidates.data', fn ($rows) => collect($rows)->pluck('id')->doesntContain($this->northCandidate->id)));

        $this->actingAs($this->dietitian)->get(route('nutrition.show', $this->northCandidate))->assertNotFound();
        $this->actingAs($this->dietitian)->post(route('nutrition.assessments.store', $this->northCandidate), ['assessed_on' => now()->toDateString(), 'height_cm' => 170, 'weight_kg' => 70])->assertNotFound();
        $this->assertSame(0, NutritionAssessment::query()->count());
    }

    public function test_a_dietitian_has_only_the_nutrition_area(): void
    {
        $this->actingAs($this->dietitian)->get(route('dashboard'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('nutritionOverview.total', 3)->where('administratorOverview', null)->where('teaching', null));
        $this->actingAs($this->dietitian)->get(route('candidates.index'))->assertForbidden();
        $this->actingAs($this->dietitian)->get(route('candidates.show', $this->candidateInA))->assertForbidden();
        $this->actingAs($this->dietitian)->get(route('medical.records.index'))->assertForbidden();
        $this->actingAs($this->dietitian)->get(route('nutrition.standards.edit'))->assertForbidden();
    }

    public function test_instructors_and_candidates_have_no_nutrition_area(): void
    {
        $this->actingAs($this->alpha)->get(route('nutrition.index'))->assertForbidden();
        $this->actingAs($this->alpha)->get(route('nutrition.show', $this->candidateInA))->assertForbidden();
        $this->actingAs($this->alpha)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('nutritionOverview', null));

        $candidateUser = $this->userWithRole(SystemRole::Candidate);
        $this->actingAs($candidateUser)->get(route('nutrition.index'))->assertForbidden();
    }

    public function test_instructors_of_the_class_see_only_the_category_and_what_not_to_eat(): void
    {
        $this->assess($this->candidateInA, ['weight_kg' => '75']);
        $this->actingAs($this->dietitian)->put(route('nutrition.dietary-profile.update', $this->candidateInA), [
            'food_allergies' => 'Shrimp', 'dietary_restrictions' => 'No pork', 'supplements' => 'Vitamin C',
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->alpha)->get(route('candidates.show', $this->candidateInA))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('nutrition.scope', 'summary')
                ->where('nutrition.status.value', 'overweight')
                ->where('nutrition.foodAllergies', 'Shrimp')
                ->where('nutrition.dietaryRestrictions', 'No pork')
                ->where('nutrition.bmi', null)
                ->where('nutrition.weightKg', null)
                ->where('nutrition.recordUrl', null)
                ->missing('nutrition.supplements'));

        $response = $this->actingAs($this->alpha)->get(route('candidates.show', $this->candidateInA));
        $this->assertStringNotContainsString('Secret diagnosis text', $response->getContent());
        $this->assertStringNotContainsString('Vitamin C', $response->getContent());

        // Bravo teaches Batch B as well, but Alpha does not: no panel for a class one does not teach.
        $this->actingAs($this->alpha)->get(route('candidates.show', $this->candidateInB))->assertForbidden();
    }

    public function test_administrators_see_the_record_but_do_not_record_assessments(): void
    {
        $this->assess($this->candidateInA);
        $admin = $this->userWithRole(SystemRole::SuperAdministrator);

        $this->actingAs($admin)->get(route('candidates.show', $this->candidateInA))
            ->assertInertia(fn (Assert $page) => $page->where('nutrition.scope', 'full')->where('nutrition.bmi', 24.2)
                ->where('nutrition.recordUrl', route('nutrition.show', $this->candidateInA, false)));
        $this->actingAs($admin)->get(route('nutrition.show', $this->candidateInA))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('can.manage', false)->where('assessments.0.diagnosis', 'Secret diagnosis text')
                // Administrators have the full medical record on its own pages, not this view-only copy.
                ->where('medical', null));
        $this->actingAs($admin)->get(route('nutrition.index'))->assertInertia(fn (Assert $page) => $page->where('counts.total', 4));
        $this->actingAs($admin)->get(route('nutrition.assessments.create', $this->candidateInA))->assertForbidden();
    }

    public function test_a_campus_limited_administrator_sees_only_their_campus(): void
    {
        $northAdmin = User::factory()->withRole(SystemRole::SuperAdministrator)->onCampus(CampusFactory::fixed(CampusCode::North))->create();

        $this->actingAs($northAdmin)->get(route('nutrition.index'))->assertInertia(fn (Assert $page) => $page->where('counts.total', 1));
        $this->actingAs($northAdmin)->get(route('nutrition.show', $this->candidateInA))->assertNotFound();
        $this->actingAs($northAdmin)->get(route('nutrition.standards.edit'))->assertForbidden();
    }

    public function test_the_candidate_sees_their_own_measurements_and_plan_without_the_dietitians_notes(): void
    {
        $this->assess($this->candidateInA);
        $this->assess($this->candidateInB, ['plan' => 'Plan for someone else']);
        $this->actingAs($this->dietitian)->put(route('nutrition.dietary-profile.update', $this->candidateInA), ['food_allergies' => 'Peanuts'])->assertSessionHasNoErrors();

        $response = $this->actingAs($this->candidateInA->user)->get(route('portal.nutrition'))->assertOk();
        $response->assertInertia(fn (Assert $page) => $page->component('portal/nutrition')
            ->has('assessments', 1)
            ->where('assessments.0.weightKg', 70)
            ->where('assessments.0.plan', 'Eat breakfast every day.')
            ->where('assessments.0.diagnosis', null)
            ->where('assessments.0.dietHistory', null)
            ->where('assessments.0.assessedBy', null)
            ->where('dietaryProfile.foodAllergies', 'Peanuts')
            ->where('dietaryProfile.updatedBy', null));
        $this->assertStringNotContainsString('Secret', $response->getContent());
        $this->assertStringNotContainsString('someone else', $response->getContent());
    }

    public function test_the_dietitian_sees_the_medical_record_view_only_and_each_view_is_recorded(): void
    {
        $this->actingAs($this->dietitian)->get(route('nutrition.show', $this->candidateInA))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('medical.scope', 'granted')->where('medical.canEdit', false)->where('medicalRecordUrl', null));

        $this->assertDatabaseHas('audit_logs', ['action' => 'medical_record.viewed', 'actor_id' => $this->dietitian->id, 'auditable_id' => $this->candidateInA->id]);
        $this->actingAs($this->dietitian)->get(route('medical.records.edit', $this->candidateInA))->assertForbidden();
        $this->assertTrue($this->dietitian->can('viewMedicalReadOnly', $this->candidateInA));
        $this->assertFalse($this->dietitian->can('viewMedicalReadOnly', $this->northCandidate));
    }

    public function test_a_dietitian_account_needs_a_campus(): void
    {
        $admin = $this->userWithRole(SystemRole::SuperAdministrator);
        $role = Role::query()->where('code', SystemRole::Dietitian->value)->firstOrFail();

        $this->actingAs($admin)->get(route('users.create'))->assertInertia(fn (Assert $page) => $page
            ->where('roles', fn ($roles) => collect($roles)->firstWhere('id', $role->id)['requiresCampus'] === true));

        $this->actingAs($admin)->post(route('users.store'), [
            'name' => 'Dietitian Two', 'username' => 'dietitian.two', 'role_id' => $role->id, 'campus_id' => '',
            'is_active' => true, 'password' => 'a-long-password-1', 'password_confirmation' => 'a-long-password-1',
        ])->assertSessionHasErrors('campus_id');

        $this->actingAs($admin)->post(route('users.store'), [
            'name' => 'Dietitian Two', 'username' => 'dietitian.two', 'role_id' => $role->id, 'campus_id' => CampusFactory::fixed(CampusCode::North)->id,
            'is_active' => true, 'password' => 'a-long-password-1', 'password_confirmation' => 'a-long-password-1',
        ])->assertSessionHasNoErrors();
        $this->assertSame(CampusFactory::fixed(CampusCode::North)->id, User::query()->where('username', 'dietitian.two')->value('campus_id'));
        $this->assertNull(CandidateDietaryProfile::query()->first());
    }
}
