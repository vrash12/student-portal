<?php

namespace Tests\Feature\Nutrition;

use App\Enums\AuditAction;
use App\Enums\SystemRole;
use App\Models\AuditLog;
use App\Models\CandidateDietaryProfile;
use App\Models\NutritionAssessment;
use App\Models\NutritionStandards;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Teaching\BuildsTeachingFixtures;
use Tests\TestCase;

/**
 * Recording, correcting and deleting nutrition assessments, the dietary
 * profile, the standards, and who needs attention on the Nutrition list.
 */
class NutritionAssessmentTest extends TestCase
{
    use BuildsTeachingFixtures;

    private User $dietitian;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildTeachingFixtures();
        $this->dietitian = $this->userWithRole(SystemRole::Dietitian);
    }

    /**
     * @return array<string, mixed>
     */
    private function form(array $overrides = []): array
    {
        return [
            'assessed_on' => now()->subDays(2)->toDateString(),
            'height_cm' => '170.0',
            'weight_kg' => '66.2',
            'waist_cm' => '80',
            'activity_level' => 'heavy',
            'meals_per_day' => '3',
            'diet_history' => 'Rice with every meal.',
            'diagnosis' => 'Adequate intake.',
            'goal' => 'maintain',
            'energy_target_kcal' => '3000',
            'plan' => 'Keep the current meals.',
            ...$overrides,
        ];
    }

    public function test_an_assessment_is_recorded_classified_and_audited_without_its_values(): void
    {
        $this->actingAs($this->dietitian)->post(route('nutrition.assessments.store', $this->candidateInA), $this->form())
            ->assertRedirect(route('nutrition.show', $this->candidateInA));

        $assessment = NutritionAssessment::query()->sole();
        $this->assertSame($this->dietitian->id, (int) $assessment->assessed_by);
        // No review date given: the standards' 30 days after the assessment.
        $this->assertSame(now()->subDays(2)->addDays(30)->toDateString(), $assessment->next_review_on->toDateString());

        $this->actingAs($this->dietitian)->get(route('nutrition.show', $this->candidateInA))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('staff/nutrition/show')
                ->where('assessments.0.bmi', 22.9)
                ->where('assessments.0.status.value', 'normal')
                ->where('assessments.0.waistToHeight', 0.47)
                ->where('assessments.0.waistAtRisk', false)
                ->where('can.manage', true));

        $entry = AuditLog::query()->where('action', AuditAction::NutritionAssessmentRecorded->value)->sole();
        $this->assertSame($this->candidateInA->id, (int) $entry->auditable_id);
        $stored = json_encode($entry->getAttributes());
        foreach (['66.2', 'Rice with every meal', 'Adequate intake', 'Keep the current meals'] as $value) {
            $this->assertStringNotContainsString($value, (string) $stored);
        }
    }

    public function test_measurements_and_dates_are_validated(): void
    {
        $this->actingAs($this->dietitian)->post(route('nutrition.assessments.store', $this->candidateInA), $this->form([
            'height_cm' => '17', 'weight_kg' => '', 'waist_cm' => '500', 'activity_level' => 'extreme',
            'energy_target_kcal' => '90000', 'next_review_on' => now()->subDays(10)->toDateString(),
        ]))->assertSessionHasErrors(['height_cm', 'weight_kg', 'waist_cm', 'activity_level', 'energy_target_kcal', 'next_review_on']);

        $this->actingAs($this->dietitian)->post(route('nutrition.assessments.store', $this->candidateInA), $this->form(['assessed_on' => now()->addDay()->toDateString()]))
            ->assertSessionHasErrors('assessed_on');
        $this->assertSame(0, NutritionAssessment::query()->count());
    }

    public function test_a_correction_records_which_fields_changed_and_a_deletion_needs_a_reason(): void
    {
        $this->actingAs($this->dietitian)->post(route('nutrition.assessments.store', $this->candidateInA), $this->form());
        $assessment = NutritionAssessment::query()->sole();

        $this->actingAs($this->dietitian)->put(route('nutrition.assessments.update', $assessment), $this->form(['weight_kg' => '67.0', 'plan' => 'More water.']))
            ->assertRedirect(route('nutrition.show', $this->candidateInA));
        $this->assertSame('67.0', (string) $assessment->fresh()->weight_kg);
        $correction = AuditLog::query()->where('action', AuditAction::NutritionAssessmentUpdated->value)->sole();
        $this->assertEqualsCanonicalizing(['weight_kg', 'plan'], $correction->new_values['fields_changed']);
        $this->assertStringNotContainsString('More water', (string) json_encode($correction->getAttributes()));

        $this->actingAs($this->dietitian)->delete(route('nutrition.assessments.destroy', $assessment), ['reason' => ''])->assertSessionHasErrors('reason');
        $this->assertNotNull($assessment->fresh());
        $this->actingAs($this->dietitian)->delete(route('nutrition.assessments.destroy', $assessment), ['reason' => 'Entered for the wrong candidate'])
            ->assertRedirect(route('nutrition.show', $this->candidateInA));
        $this->assertNull($assessment->fresh());
        $this->assertSame('Entered for the wrong candidate', AuditLog::query()->where('action', AuditAction::NutritionAssessmentDeleted->value)->sole()->reason);
    }

    public function test_another_campus_dietitian_and_instructors_cannot_change_an_assessment(): void
    {
        $this->actingAs($this->dietitian)->post(route('nutrition.assessments.store', $this->candidateInA), $this->form());
        $assessment = NutritionAssessment::query()->sole();

        $this->actingAs($this->alpha)->put(route('nutrition.assessments.update', $assessment), $this->form())->assertForbidden();
        $this->actingAs($this->alpha)->delete(route('nutrition.assessments.destroy', $assessment), ['reason' => 'Not mine to delete'])->assertForbidden();
        $this->assertNotNull($assessment->fresh());
    }

    public function test_the_dietary_profile_is_saved_and_audited_by_field_name_only(): void
    {
        $this->actingAs($this->dietitian)->put(route('nutrition.dietary-profile.update', $this->candidateInA), [
            'food_allergies' => '  Shrimp   and crab ', 'dietary_restrictions' => '', 'supplements' => 'Vitamin D',
        ])->assertRedirect(route('nutrition.show', $this->candidateInA));

        $profile = CandidateDietaryProfile::query()->sole();
        $this->assertSame('Shrimp and crab', $profile->food_allergies);
        $this->assertNull($profile->dietary_restrictions);
        $entry = AuditLog::query()->where('action', AuditAction::DietaryProfileUpdated->value)->sole();
        $this->assertEqualsCanonicalizing(['food_allergies', 'supplements'], $entry->new_values['fields_changed']);
        $this->assertStringNotContainsString('Shrimp', (string) json_encode($entry->getAttributes()));

        // Saving the same values changes nothing and records nothing.
        $this->actingAs($this->dietitian)->put(route('nutrition.dietary-profile.update', $this->candidateInA), ['food_allergies' => 'Shrimp and crab', 'supplements' => 'Vitamin D']);
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::DietaryProfileUpdated->value)->count());
    }

    public function test_the_list_shows_who_needs_attention(): void
    {
        // Normal, recent: no attention.
        $this->actingAs($this->dietitian)->post(route('nutrition.assessments.store', $this->candidateInA), $this->form());
        // Underweight and review due.
        $this->actingAs($this->dietitian)->post(route('nutrition.assessments.store', $this->candidateInB), $this->form([
            'assessed_on' => now()->subDays(40)->toDateString(), 'weight_kg' => '50', 'waist_cm' => '',
        ]));

        $this->actingAs($this->dietitian)->get(route('nutrition.index'))->assertInertia(fn (Assert $page) => $page
            ->where('counts.total', 3)
            ->where('counts.assessed', 2)
            ->where('counts.notAssessed', 1)
            ->where('counts.reviewDue', 1)
            ->where('counts.needsAttention', 2)
            ->where('counts.byStatus.normal', 1)
            ->where('counts.byStatus.underweight', 1));

        $this->actingAs($this->dietitian)->get(route('nutrition.index', ['show' => 'attention']))->assertInertia(fn (Assert $page) => $page
            ->has('candidates.data', 2)
            ->where('candidates.data', fn ($rows) => collect($rows)->pluck('id')->doesntContain($this->candidateInA->id)));
        $this->actingAs($this->dietitian)->get(route('nutrition.index', ['show' => 'underweight']))->assertInertia(fn (Assert $page) => $page
            ->has('candidates.data', 1)->where('candidates.data.0.id', $this->candidateInB->id)->where('candidates.data.0.reviewDue', true));
        $this->actingAs($this->dietitian)->get(route('nutrition.index', ['show' => 'nonsense']))->assertInertia(fn (Assert $page) => $page
            ->where('filters.show', '')->has('candidates.data', 3));
    }

    public function test_the_weight_change_compares_with_the_assessment_before(): void
    {
        $this->actingAs($this->dietitian)->post(route('nutrition.assessments.store', $this->candidateInA), $this->form(['assessed_on' => now()->subDays(40)->toDateString(), 'weight_kg' => '68.0']));
        $this->actingAs($this->dietitian)->post(route('nutrition.assessments.store', $this->candidateInA), $this->form(['weight_kg' => '66.5']));

        $this->actingAs($this->dietitian)->get(route('nutrition.index', ['search' => $this->candidateInA->candidate_number]))->assertInertia(fn (Assert $page) => $page
            ->where('candidates.data.0.weightKg', 66.5)
            ->where('candidates.data.0.weightChange', -1.5)
            ->where('candidates.data.0.assessments', 2));
    }

    public function test_institution_wide_administrators_set_the_standards_and_categories_follow_them(): void
    {
        $this->actingAs($this->dietitian)->post(route('nutrition.assessments.store', $this->candidateInA), $this->form(['weight_kg' => '68.0']));
        $admin = $this->userWithRole(SystemRole::SuperAdministrator);

        $this->actingAs($admin)->put(route('nutrition.standards.update'), [
            'underweight_below' => '18.5', 'overweight_from' => '18', 'obese_from' => '30', 'waist_to_height_risk' => '0.5', 'review_interval_days' => '30',
        ])->assertSessionHasErrors('overweight_from');

        $this->actingAs($admin)->put(route('nutrition.standards.update'), [
            'underweight_below' => '18.5', 'overweight_from' => '25', 'obese_from' => '30', 'waist_to_height_risk' => '0.55', 'review_interval_days' => '60',
        ])->assertRedirect(route('nutrition.index'));

        $this->assertSame(25.0, (float) NutritionStandards::current()->overweight_from);
        // BMI 23.5: overweight under the Asian cut-offs, normal under the new ones.
        $this->actingAs($admin)->get(route('nutrition.show', $this->candidateInA))->assertInertia(fn (Assert $page) => $page->where('assessments.0.status.value', 'normal'));
        $entry = AuditLog::query()->where('action', AuditAction::NutritionStandardsUpdated->value)->sole();
        $this->assertEquals(23.0, $entry->old_values['overweightFrom']);
        $this->assertEquals(25.0, $entry->new_values['overweightFrom']);

        $this->actingAs($this->dietitian)->put(route('nutrition.standards.update'), ['underweight_below' => '18.5', 'overweight_from' => '23', 'obese_from' => '27.5', 'waist_to_height_risk' => '0.5', 'review_interval_days' => '30'])
            ->assertForbidden();
    }
}
