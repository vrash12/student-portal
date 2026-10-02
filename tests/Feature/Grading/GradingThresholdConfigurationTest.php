<?php

namespace Tests\Feature\Grading;

use App\Enums\AuditAction;
use App\Enums\Permission as PermissionCode;
use App\Enums\SystemRole;
use App\Models\AcademicPeriod;
use App\Models\Assessment;
use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Configuring the passing and warning grades of an academic period: the
 * thresholds page and its authorization, validation, the reason rule, audit
 * entries, database constraints, mass-assignment protection, and the places
 * that point administrators to the page (periods list, dashboard).
 *
 * Fixtures: see BuildsGradingFixtures. "Period Current" (active, starts
 * 2026-08-03, Batch A and Batch B) and "Period Past" (starts 2026-01-05,
 * Batch Old). Neither period has thresholds until a test sets them.
 */
class GradingThresholdConfigurationTest extends TestCase
{
    use BuildsGradingFixtures;

    private const REASON_REQUIRED = 'Explain why the passing or warning grade is changing. Academic standings in this period will be recalculated.';

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildGradingFixtures();
    }

    // ------------------------------------------------------------------
    // Authorization
    // ------------------------------------------------------------------

    /**
     * @return array<string, array{SystemRole}>
     */
    public static function administratorRoles(): array
    {
        return [
            'super administrator' => [SystemRole::SuperAdministrator],
            'academic administrator' => [SystemRole::AcademicAdministrator],
        ];
    }

    #[DataProvider('administratorRoles')]
    public function test_administrators_open_the_thresholds_page(SystemRole $role): void
    {
        $this->actingAs($this->userWithRole($role))
            ->get($this->thresholdsUrl($this->activePeriod))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('staff/academic-periods/thresholds')
                ->where('period.id', $this->activePeriod->id)
                ->where('can', ['managePeriods' => true]));
    }

    #[DataProvider('administratorRoles')]
    public function test_administrators_save_thresholds_and_return_to_the_periods_list(SystemRole $role): void
    {
        $this->actingAs($this->userWithRole($role))
            ->put($this->thresholdsUrl($this->activePeriod), $this->payload('75', '80'))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('academic-periods.index'))
            ->assertInertiaFlash('toast.type', 'success')
            ->assertInertiaFlash('toast.message', 'Passing and warning grades for Period Current saved.');

        $this->assertSame(['75.00', '80.00'], $this->storedThresholds($this->activePeriod));
        $this->assertSame([null, null], $this->storedThresholds($this->pastPeriod));
    }

    public function test_instructors_cannot_open_or_change_thresholds(): void
    {
        $this->setThresholds($this->activePeriod, '75', '80');

        // Alpha teaches in this period; teaching does not allow configuring grading.
        foreach ([$this->alpha, $this->bravo] as $instructor) {
            $this->actingAs($instructor)->get($this->thresholdsUrl($this->activePeriod))->assertForbidden();
            $this->actingAs($instructor)
                ->put($this->thresholdsUrl($this->activePeriod), $this->payload('50', '60', 'Instructor attempt'))
                ->assertForbidden();
        }

        $this->assertSame(['75.00', '80.00'], $this->storedThresholds($this->activePeriod));
        $this->assertSame(0, $this->thresholdAuditCount());
    }

    public function test_candidates_cannot_open_or_change_thresholds(): void
    {
        $candidate = $this->userWithRole(SystemRole::Candidate);

        $this->actingAs($candidate)
            ->get($this->thresholdsUrl($this->activePeriod))
            ->assertForbidden()
            ->assertInertia(fn (Assert $page) => $page->component('errors/error')->where('status', 403));
        $this->actingAs($candidate)
            ->put($this->thresholdsUrl($this->activePeriod), $this->payload('75', '80'))
            ->assertForbidden();

        $this->assertSame([null, null], $this->storedThresholds($this->activePeriod));
        $this->assertSame(0, $this->thresholdAuditCount());
    }

    public function test_deactivated_administrator_is_signed_out_instead_of_changing_thresholds(): void
    {
        $this->actingAs($this->academicAdmin);
        $this->academicAdmin->forceFill(['is_active' => false])->save();

        $this->put($this->thresholdsUrl($this->activePeriod), $this->payload('75', '80'))
            ->assertRedirect(route('login'))
            ->assertInertiaFlash('toast.type', 'error');
        $this->assertGuest();

        $this->actingAs($this->academicAdmin)
            ->get($this->thresholdsUrl($this->activePeriod))
            ->assertRedirect(route('login'));
        $this->assertGuest();

        $this->assertSame([null, null], $this->storedThresholds($this->activePeriod));
        $this->assertSame(0, $this->thresholdAuditCount());
    }

    public function test_guests_are_redirected_to_sign_in(): void
    {
        $this->get($this->thresholdsUrl($this->activePeriod))->assertRedirect(route('login'));
        $this->put($this->thresholdsUrl($this->activePeriod), $this->payload('75', '80'))->assertRedirect(route('login'));

        $this->assertSame([null, null], $this->storedThresholds($this->activePeriod));
    }

    public function test_grading_configuration_alone_opens_the_page_and_the_save_returns_to_it(): void
    {
        $gradingOfficer = $this->userWithPermissions('grading_officer', [
            PermissionCode::AccessStaffArea,
            PermissionCode::ConfigureGrading,
        ]);

        $this->actingAs($gradingOfficer)
            ->get($this->thresholdsUrl($this->activePeriod))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('staff/academic-periods/thresholds')
                ->where('can', ['managePeriods' => false]));

        $this->actingAs($gradingOfficer)
            ->put($this->thresholdsUrl($this->activePeriod), $this->payload('70', '75'))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('academic-periods.thresholds.edit', $this->activePeriod))
            ->assertInertiaFlash('toast.message', 'Passing and warning grades for Period Current saved.');

        $this->assertSame(['70.00', '75.00'], $this->storedThresholds($this->activePeriod));

        // The periods list itself still needs academic_periods.manage.
        $this->actingAs($gradingOfficer)->get('/academic-periods')->assertForbidden();
    }

    public function test_managing_periods_without_grading_configuration_does_not_open_the_page(): void
    {
        $periodManager = $this->userWithPermissions('period_manager', [
            PermissionCode::AccessStaffArea,
            PermissionCode::ManageAcademicPeriods,
        ]);

        $this->actingAs($periodManager)->get($this->thresholdsUrl($this->activePeriod))->assertForbidden();
        $this->actingAs($periodManager)
            ->put($this->thresholdsUrl($this->activePeriod), $this->payload('75', '80'))
            ->assertForbidden();

        $this->assertSame([null, null], $this->storedThresholds($this->activePeriod));
    }

    public function test_unknown_period_is_not_found(): void
    {
        $missingId = AcademicPeriod::query()->max('id') + 100;

        $this->actingAs($this->academicAdmin)->get("/academic-periods/{$missingId}/grading-thresholds")->assertNotFound();
        $this->actingAs($this->academicAdmin)
            ->put("/academic-periods/{$missingId}/grading-thresholds", $this->payload('75', '80'))
            ->assertNotFound();

        $this->assertSame(0, $this->thresholdAuditCount());
    }

    // ------------------------------------------------------------------
    // Page props
    // ------------------------------------------------------------------

    public function test_page_of_a_period_without_thresholds_shows_the_period_and_no_values(): void
    {
        $this->actingAs($this->academicAdmin)
            ->get($this->thresholdsUrl($this->activePeriod))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('staff/academic-periods/thresholds')
                ->where('period', [
                    'id' => $this->activePeriod->id,
                    'name' => 'Period Current',
                    'startsOn' => '2026-08-03',
                    'endsOn' => '2026-12-18',
                    'isActive' => true,
                    'classCount' => 2,
                ])
                ->where('thresholds', null)
                ->where('suggestion', null)
                ->where('requiresReason', false));
    }

    public function test_page_shows_saved_thresholds_as_entered_and_offers_no_suggestion(): void
    {
        $this->setThresholds($this->activePeriod, '75.00', '82.50');
        $this->setThresholds($this->pastPeriod, '70.00', '75.00');

        $this->actingAs($this->academicAdmin)
            ->get($this->thresholdsUrl($this->activePeriod))
            ->assertInertia(fn (Assert $page) => $page
                ->where('thresholds', ['passingGrade' => '75', 'warningGrade' => '82.5'])
                ->where('suggestion', null));

        $this->actingAs($this->academicAdmin)
            ->get($this->thresholdsUrl($this->pastPeriod))
            ->assertInertia(fn (Assert $page) => $page
                ->where('period', [
                    'id' => $this->pastPeriod->id,
                    'name' => 'Period Past',
                    'startsOn' => '2026-01-05',
                    'endsOn' => '2026-05-29',
                    'isActive' => false,
                    'classCount' => 1,
                ])
                ->where('thresholds', ['passingGrade' => '70', 'warningGrade' => '75']));
    }

    public function test_suggestion_comes_from_the_most_recent_other_period_with_thresholds(): void
    {
        // Created after Period Past (higher id) but starts earlier: the start
        // date decides which period is the most recent.
        $older = AcademicPeriod::factory()->create(['name' => 'Period Older', 'starts_on' => '2025-01-06', 'ends_on' => '2025-05-30']);
        $this->setThresholds($older, '60', '65');
        $this->setThresholds($this->pastPeriod, '70.5', '78');
        // A later period without thresholds has nothing to suggest.
        AcademicPeriod::factory()->create(['name' => 'Period Next', 'starts_on' => '2027-01-04', 'ends_on' => '2027-05-28']);

        $this->actingAs($this->academicAdmin)
            ->get($this->thresholdsUrl($this->activePeriod))
            ->assertInertia(fn (Assert $page) => $page
                ->where('thresholds', null)
                ->where('suggestion', [
                    'fromPeriod' => 'Period Past',
                    'passingGrade' => '70.5',
                    'warningGrade' => '78',
                ]));

        // A suggestion is only offered; nothing is saved by opening the page.
        $this->assertSame([null, null], $this->storedThresholds($this->activePeriod));
    }

    public function test_page_does_not_require_a_reason_before_thresholds_are_first_set(): void
    {
        $this->finalizedExamination();

        $this->actingAs($this->academicAdmin)
            ->get($this->thresholdsUrl($this->activePeriod))
            ->assertInertia(fn (Assert $page) => $page->where('requiresReason', false));
    }

    public function test_page_requires_a_reason_once_thresholds_are_set_and_an_assessment_is_finalized(): void
    {
        $this->setThresholds($this->activePeriod, '75', '80');

        // A draft assessment with scores does not make grades official.
        $draft = $this->createAssessment($this->quizzes, 'Quiz 1');
        $this->recordScores($draft, [$this->candidateInA->id => '40']);

        $this->actingAs($this->academicAdmin)
            ->get($this->thresholdsUrl($this->activePeriod))
            ->assertInertia(fn (Assert $page) => $page->where('requiresReason', false));

        $this->finalize($draft);

        $this->actingAs($this->academicAdmin)
            ->get($this->thresholdsUrl($this->activePeriod))
            ->assertInertia(fn (Assert $page) => $page->where('requiresReason', true));
    }

    public function test_finalized_assessments_of_another_period_do_not_require_a_reason(): void
    {
        $this->finalizedAssessmentInThePastPeriod();
        $this->setThresholds($this->activePeriod, '75', '80');

        $this->actingAs($this->academicAdmin)
            ->get($this->thresholdsUrl($this->activePeriod))
            ->assertInertia(fn (Assert $page) => $page->where('requiresReason', false));

        $this->actingAs($this->academicAdmin)
            ->put($this->thresholdsUrl($this->activePeriod), $this->payload('70', '78'))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('academic-periods.index'));

        $this->assertSame(['70.00', '78.00'], $this->storedThresholds($this->activePeriod));
    }

    // ------------------------------------------------------------------
    // Validation
    // ------------------------------------------------------------------

    /**
     * @return array<string, array{string, mixed, string}>
     */
    public static function invalidGrades(): array
    {
        $cases = [];

        foreach (['passing_grade' => 'passing grade', 'warning_grade' => 'warning grade'] as $field => $name) {
            $cases += [
                "{$field} empty" => [$field, '', "Enter the {$name}."],
                "{$field} only spaces" => [$field, '   ', "Enter the {$name}."],
                "{$field} null" => [$field, null, "Enter the {$name}."],
                "{$field} not a number" => [$field, 'seventy', 'Enter the grade as a number, for example 75.'],
                "{$field} array" => [$field, ['75'], 'Enter the grade as a number, for example 75.'],
                "{$field} three decimals" => [$field, '75.125', 'Use at most two decimal places.'],
                "{$field} zero" => [$field, '0', 'Enter a grade greater than 0.'],
                "{$field} zero with decimals" => [$field, '0.00', 'Enter a grade greater than 0.'],
                "{$field} negative" => [$field, '-5', 'Enter a grade greater than 0.'],
                "{$field} above 100" => [$field, '100.01', 'A grade cannot be more than 100.'],
            ];
        }

        return $cases;
    }

    #[DataProvider('invalidGrades')]
    public function test_invalid_grades_are_rejected_with_a_clear_message(string $field, mixed $value, string $message): void
    {
        $payload = [...$this->payload('75', '100'), $field => $value];
        $otherField = $field === 'passing_grade' ? 'warning_grade' : 'passing_grade';

        $this->actingAs($this->academicAdmin)
            ->from($this->thresholdsUrl($this->activePeriod))
            ->put($this->thresholdsUrl($this->activePeriod), $payload)
            ->assertRedirect($this->thresholdsUrl($this->activePeriod))
            ->assertSessionHasErrors([$field => $message])
            ->assertSessionDoesntHaveErrors([$otherField, 'reason']);

        $this->assertSame($message, session('errors')->first($field));
        $this->assertSame([null, null], $this->storedThresholds($this->activePeriod));
        $this->assertSame(0, $this->thresholdAuditCount());
    }

    public function test_both_grades_are_required(): void
    {
        $this->actingAs($this->academicAdmin)
            ->put($this->thresholdsUrl($this->activePeriod), [])
            ->assertSessionHasErrors([
                'passing_grade' => 'Enter the passing grade.',
                'warning_grade' => 'Enter the warning grade.',
            ])
            ->assertSessionDoesntHaveErrors(['reason']);

        $this->assertSame([null, null], $this->storedThresholds($this->activePeriod));
    }

    public function test_warning_grade_below_the_passing_grade_is_rejected_and_nothing_changes(): void
    {
        $this->setThresholds($this->activePeriod, '75', '80');

        $this->actingAs($this->academicAdmin)
            ->from($this->thresholdsUrl($this->activePeriod))
            ->put($this->thresholdsUrl($this->activePeriod), $this->payload('82.5', '82.49', 'Stricter policy'))
            ->assertRedirect($this->thresholdsUrl($this->activePeriod))
            ->assertSessionHasErrors([
                'warning_grade' => 'The warning grade must be equal to or higher than the passing grade (82.50).',
            ])
            ->assertSessionDoesntHaveErrors(['passing_grade', 'reason']);

        $this->assertStringContainsString('82.50', session('errors')->first('warning_grade'));
        $this->assertSame(['75.00', '80.00'], $this->storedThresholds($this->activePeriod));
        $this->assertSame(0, $this->thresholdAuditCount());
    }

    public function test_equal_passing_and_warning_grades_are_accepted(): void
    {
        $this->actingAs($this->academicAdmin)
            ->put($this->thresholdsUrl($this->activePeriod), $this->payload('75', '75.00'))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('academic-periods.index'));

        $this->assertSame(['75.00', '75.00'], $this->storedThresholds($this->activePeriod));

        $this->actingAs($this->academicAdmin)
            ->put($this->thresholdsUrl($this->pastPeriod), $this->payload('100', '100'))
            ->assertSessionHasNoErrors();

        $this->assertSame(['100.00', '100.00'], $this->storedThresholds($this->pastPeriod));
    }

    public function test_numbers_sent_as_json_are_accepted_and_normalized(): void
    {
        // Inertia submits forms as JSON; numeric values may arrive as numbers.
        $this->actingAs($this->academicAdmin)
            ->putJson($this->thresholdsUrl($this->activePeriod), ['passing_grade' => 75, 'warning_grade' => 82.5])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('academic-periods.index'));

        $this->assertSame(['75.00', '82.50'], $this->storedThresholds($this->activePeriod));
    }

    public function test_reason_must_be_text_of_at_most_500_characters(): void
    {
        $this->setThresholds($this->activePeriod, '75', '80');
        $this->finalizedExamination();

        $this->actingAs($this->academicAdmin)
            ->put($this->thresholdsUrl($this->activePeriod), $this->payload('70', '78', str_repeat('r', 501)))
            ->assertSessionHasErrors(['reason' => 'Use at most 500 characters.']);

        $this->actingAs($this->academicAdmin)
            ->put($this->thresholdsUrl($this->activePeriod), [...$this->payload('70', '78'), 'reason' => ['Policy revised']])
            ->assertSessionHasErrors(['reason']);

        $this->assertSame(['75.00', '80.00'], $this->storedThresholds($this->activePeriod));
        $this->assertSame(0, $this->thresholdAuditCount());

        $this->actingAs($this->academicAdmin)
            ->put($this->thresholdsUrl($this->activePeriod), $this->payload('70', '78', str_repeat('r', 500)))
            ->assertSessionHasNoErrors();

        $this->assertSame(['70.00', '78.00'], $this->storedThresholds($this->activePeriod));
    }

    // ------------------------------------------------------------------
    // Reason rule
    // ------------------------------------------------------------------

    public function test_first_time_thresholds_need_no_reason_even_with_finalized_assessments(): void
    {
        $this->finalizedExamination();

        $this->actingAs($this->academicAdmin)
            ->put($this->thresholdsUrl($this->activePeriod), $this->payload('75', '80'))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('academic-periods.index'));

        $this->assertSame(['75.00', '80.00'], $this->storedThresholds($this->activePeriod));
        $entry = $this->latestThresholdAudit();
        $this->assertNotNull($entry);
        $this->assertNull($entry->reason);
    }

    public function test_changing_thresholds_after_an_assessment_is_finalized_requires_a_reason(): void
    {
        $this->setThresholds($this->activePeriod, '75', '80');
        $this->finalizedExamination();

        $this->actingAs($this->academicAdmin)
            ->from($this->thresholdsUrl($this->activePeriod))
            ->put($this->thresholdsUrl($this->activePeriod), $this->payload('70', '78'))
            ->assertRedirect($this->thresholdsUrl($this->activePeriod))
            ->assertSessionHasErrors(['reason' => self::REASON_REQUIRED])
            ->assertSessionDoesntHaveErrors(['passing_grade', 'warning_grade']);

        $this->assertSame(['75.00', '80.00'], $this->storedThresholds($this->activePeriod));
        $this->assertSame(0, $this->thresholdAuditCount());
    }

    public function test_a_reason_of_only_spaces_counts_as_no_reason(): void
    {
        $this->setThresholds($this->activePeriod, '75', '80');
        $this->finalizedExamination();

        $this->actingAs($this->academicAdmin)
            ->put($this->thresholdsUrl($this->activePeriod), $this->payload('70', '78', "   \t  "))
            ->assertSessionHasErrors(['reason' => self::REASON_REQUIRED]);

        $this->assertSame(['75.00', '80.00'], $this->storedThresholds($this->activePeriod));
        $this->assertSame(0, $this->thresholdAuditCount());
    }

    public function test_changing_thresholds_with_a_reason_saves_and_records_the_trimmed_reason(): void
    {
        $this->setThresholds($this->activePeriod, '75', '80');
        $this->finalizedExamination();

        $this->actingAs($this->academicAdmin)
            ->put($this->thresholdsUrl($this->activePeriod), $this->payload('70', '77.5', '  Policy revised by the academic board.  '))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('academic-periods.index'))
            ->assertInertiaFlash('toast.message', 'Passing and warning grades for Period Current saved.');

        $this->assertSame(['70.00', '77.50'], $this->storedThresholds($this->activePeriod));

        $entry = $this->latestThresholdAudit();
        $this->assertSame('Policy revised by the academic board.', $entry->reason);
        $this->assertSame(['passing_grade' => '75.00', 'warning_grade' => '80.00'], $entry->old_values);
        $this->assertSame(['passing_grade' => '70.00', 'warning_grade' => '77.50'], $entry->new_values);
    }

    public function test_changing_thresholds_without_finalized_assessments_needs_no_reason(): void
    {
        $this->setThresholds($this->activePeriod, '75', '80');
        $draft = $this->createAssessment($this->quizzes, 'Quiz 1');
        $this->recordScores($draft, [$this->candidateInA->id => '45']);

        $this->actingAs($this->academicAdmin)
            ->put($this->thresholdsUrl($this->activePeriod), $this->payload('72', '79'))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('academic-periods.index'));

        $this->assertSame(['72.00', '79.00'], $this->storedThresholds($this->activePeriod));
        $this->assertNull($this->latestThresholdAudit()->reason);
    }

    public function test_saving_unchanged_values_writes_no_audit_entry(): void
    {
        $this->setThresholds($this->activePeriod, '75', '80');
        // With finalized grades a change would need a reason; an unchanged
        // save is not a change, so it succeeds without one.
        $this->finalizedExamination();

        $this->actingAs($this->academicAdmin)
            ->put($this->thresholdsUrl($this->activePeriod), $this->payload('75', '80.0'))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('academic-periods.index'))
            ->assertInertiaFlash('toast.type', 'success')
            ->assertInertiaFlash('toast.message', 'Passing and warning grades for Period Current saved.');

        $this->actingAs($this->academicAdmin)
            ->put($this->thresholdsUrl($this->activePeriod), $this->payload('75.00', '80', 'Nothing changes'))
            ->assertSessionHasNoErrors();

        $this->assertSame(['75.00', '80.00'], $this->storedThresholds($this->activePeriod));
        $this->assertSame(0, $this->thresholdAuditCount());
    }

    // ------------------------------------------------------------------
    // Audit
    // ------------------------------------------------------------------

    public function test_saving_thresholds_records_an_audit_entry_with_normalized_values(): void
    {
        $this->actingAs($this->academicAdmin)
            ->put($this->thresholdsUrl($this->activePeriod), $this->payload('82.5', '90', ' First policy '))
            ->assertSessionHasNoErrors();

        $entry = AuditLog::query()->where('action', 'grading_thresholds.updated')->sole();
        $this->assertSame(AuditAction::GradingThresholdsUpdated->value, $entry->action);
        $this->assertSame('academic_period', $entry->auditable_type);
        $this->assertSame($this->activePeriod->id, (int) $entry->auditable_id);
        $this->assertSame($this->academicAdmin->id, (int) $entry->actor_id);
        $this->assertSame(['passing_grade' => null, 'warning_grade' => null], $entry->old_values);
        $this->assertSame(['passing_grade' => '82.50', 'warning_grade' => '90.00'], $entry->new_values);
        $this->assertSame('First policy', $entry->reason);

        // A second change records the values it replaced.
        $this->actingAs($this->academicAdmin)
            ->put($this->thresholdsUrl($this->activePeriod), $this->payload('80', '85'))
            ->assertSessionHasNoErrors();

        $this->assertSame(2, $this->thresholdAuditCount());
        $latest = $this->latestThresholdAudit();
        $this->assertSame(['passing_grade' => '82.50', 'warning_grade' => '90.00'], $latest->old_values);
        $this->assertSame(['passing_grade' => '80.00', 'warning_grade' => '85.00'], $latest->new_values);
        $this->assertNull($latest->reason);
    }

    // ------------------------------------------------------------------
    // Database
    // ------------------------------------------------------------------

    public function test_threshold_columns_are_nullable_two_decimal_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('academic_periods', ['passing_grade', 'warning_grade']));

        $columns = collect(Schema::getColumns('academic_periods'))->keyBy('name');
        foreach (['passing_grade', 'warning_grade'] as $column) {
            $this->assertSame('decimal(5,2)', $columns[$column]['type'], "{$column} type");
            $this->assertTrue($columns[$column]['nullable'], "{$column} must be nullable");
        }
    }

    /**
     * @return array<string, array{string|null, string|null}>
     */
    public static function invalidStoredThresholds(): array
    {
        return [
            'passing grade without warning grade' => ['75.00', null],
            'warning grade without passing grade' => [null, '80.00'],
            'warning grade below passing grade' => ['80.00', '79.99'],
            'passing grade of zero' => ['0.00', '80.00'],
            'negative passing grade' => ['-1.00', '80.00'],
            'warning grade above 100' => ['75.00', '100.01'],
        ];
    }

    #[DataProvider('invalidStoredThresholds')]
    public function test_database_rejects_invalid_threshold_values(?string $passing, ?string $warning): void
    {
        $this->assertQueryFails(fn () => DB::table('academic_periods')
            ->where('id', $this->activePeriod->id)
            ->update(['passing_grade' => $passing, 'warning_grade' => $warning]));

        $this->assertQueryFails(fn () => DB::table('academic_periods')->insert([
            'name' => 'Period Inserted',
            'starts_on' => '2027-01-04',
            'ends_on' => '2027-05-28',
            'passing_grade' => $passing,
            'warning_grade' => $warning,
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        $this->assertSame([null, null], $this->storedThresholds($this->activePeriod));
        $this->assertFalse(DB::table('academic_periods')->where('name', 'Period Inserted')->exists());
    }

    public function test_database_accepts_valid_threshold_values(): void
    {
        foreach ([['0.01', '0.01'], ['75.00', '75.00'], ['75.00', '80.00'], ['100.00', '100.00'], [null, null]] as [$passing, $warning]) {
            DB::table('academic_periods')
                ->where('id', $this->activePeriod->id)
                ->update(['passing_grade' => $passing, 'warning_grade' => $warning]);

            $this->assertSame([$passing, $warning], $this->storedThresholds($this->activePeriod));
        }
    }

    // ------------------------------------------------------------------
    // Mass assignment
    // ------------------------------------------------------------------

    public function test_ordinary_period_edit_form_cannot_change_thresholds(): void
    {
        $this->setThresholds($this->activePeriod, '75', '80');

        $this->actingAs($this->academicAdmin)
            ->put("/academic-periods/{$this->activePeriod->id}", [
                'name' => 'Period Current Renamed',
                'starts_on' => '2026-08-03',
                'ends_on' => '2026-12-18',
                'passing_grade' => '10',
                'warning_grade' => '20',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('academic-periods.index'));

        $this->assertSame('Period Current Renamed', $this->activePeriod->fresh()->name);
        $this->assertSame(['75.00', '80.00'], $this->storedThresholds($this->activePeriod));
        $this->assertSame(0, $this->thresholdAuditCount());

        // Also for a period without thresholds.
        $this->actingAs($this->academicAdmin)
            ->put("/academic-periods/{$this->pastPeriod->id}", [
                'name' => 'Period Past',
                'starts_on' => '2026-01-05',
                'ends_on' => '2026-05-29',
                'passing_grade' => '60',
                'warning_grade' => '70',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame([null, null], $this->storedThresholds($this->pastPeriod));
        $this->assertSame(0, $this->thresholdAuditCount());
    }

    public function test_thresholds_are_not_mass_assignable(): void
    {
        $period = new AcademicPeriod;
        $this->assertFalse($period->isFillable('passing_grade'));
        $this->assertFalse($period->isFillable('warning_grade'));

        // Where silently discarded attributes are allowed (production), the
        // thresholds are ignored by create() and fill().
        $wasPreventing = Model::preventsSilentlyDiscardingAttributes();
        Model::preventSilentlyDiscardingAttributes(false);

        try {
            $created = AcademicPeriod::query()->create([
                'name' => 'Period Mass Assigned',
                'starts_on' => '2027-01-04',
                'ends_on' => '2027-05-28',
                'passing_grade' => '75',
                'warning_grade' => '80',
            ]);
            $this->assertSame([null, null], $this->storedThresholds($created));

            $this->activePeriod->fill(['name' => 'Period Current Filled', 'passing_grade' => '75', 'warning_grade' => '80'])->save();
            $this->assertSame('Period Current Filled', $this->activePeriod->fresh()->name);
            $this->assertSame([null, null], $this->storedThresholds($this->activePeriod));
        } finally {
            Model::preventSilentlyDiscardingAttributes($wasPreventing);
        }
    }

    public function test_mass_assigning_thresholds_fails_loudly_outside_production(): void
    {
        // AppServiceProvider enables strict models outside production.
        $this->assertTrue(Model::preventsSilentlyDiscardingAttributes());

        try {
            $this->pastPeriod->fill(['passing_grade' => '75', 'warning_grade' => '80']);
            $this->fail('Mass assigning thresholds must be rejected.');
        } catch (MassAssignmentException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame([null, null], $this->storedThresholds($this->pastPeriod));
    }

    // ------------------------------------------------------------------
    // Academic periods list
    // ------------------------------------------------------------------

    public function test_periods_list_includes_each_periods_thresholds(): void
    {
        $this->setThresholds($this->activePeriod, '75', '82.5');

        $this->actingAs($this->academicAdmin)
            ->get('/academic-periods')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('staff/academic-periods/index')
                ->has('periods', 2)
                ->where('periods.0.name', 'Period Current')
                ->where('periods.0.thresholds', ['passingGrade' => 75, 'warningGrade' => 82.5])
                ->where('periods.1.name', 'Period Past')
                ->where('periods.1.thresholds', null)
                ->where('can', ['configureGrading' => true]));

        $this->actingAs($this->userWithRole(SystemRole::SuperAdministrator))
            ->get('/academic-periods')
            ->assertInertia(fn (Assert $page) => $page->where('can', ['configureGrading' => true]));
    }

    public function test_periods_list_without_grading_configuration_does_not_offer_the_thresholds_page(): void
    {
        $periodManager = $this->userWithPermissions('period_manager', [
            PermissionCode::AccessStaffArea,
            PermissionCode::ManageAcademicPeriods,
        ]);
        $this->setThresholds($this->pastPeriod, '70', '75');

        $this->actingAs($periodManager)
            ->get('/academic-periods')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('can', ['configureGrading' => false])
                ->where('periods.1.thresholds', ['passingGrade' => 70, 'warningGrade' => 75]));
    }

    // ------------------------------------------------------------------
    // Dashboard
    // ------------------------------------------------------------------

    public function test_dashboard_points_administrators_to_missing_thresholds_of_the_active_period(): void
    {
        foreach ([$this->academicAdmin, $this->userWithRole(SystemRole::SuperAdministrator)] as $administrator) {
            $this->actingAs($administrator)
                ->get('/dashboard')
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('staff/dashboard')
                    ->where('thresholdSetup', [
                        'periodId' => $this->activePeriod->id,
                        'periodName' => 'Period Current',
                    ]));
        }
    }

    public function test_dashboard_shows_no_threshold_setup_once_the_active_period_has_thresholds(): void
    {
        // Another period without thresholds does not matter.
        $this->setThresholds($this->activePeriod, '75', '80');

        $this->actingAs($this->academicAdmin)
            ->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page->where('thresholdSetup', null));
    }

    public function test_dashboard_shows_no_threshold_setup_without_an_active_period(): void
    {
        $this->activePeriod->forceFill(['is_active' => false])->save();

        $this->actingAs($this->academicAdmin)
            ->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page->where('thresholdSetup', null));
    }

    public function test_dashboard_threshold_setup_follows_the_permission_not_the_role(): void
    {
        $this->actingAs($this->alpha)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('thresholdSetup', null));

        $gradingOfficer = $this->userWithPermissions('grading_officer', [
            PermissionCode::AccessStaffArea,
            PermissionCode::ConfigureGrading,
        ]);

        $this->actingAs($gradingOfficer)
            ->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page->where('thresholdSetup', [
                'periodId' => $this->activePeriod->id,
                'periodName' => 'Period Current',
            ]));
    }

    // ------------------------------------------------------------------
    // Permission catalogue
    // ------------------------------------------------------------------

    public function test_grading_permission_describes_passing_and_warning_grades(): void
    {
        $description = PermissionCode::ConfigureGrading->description();

        $this->assertStringContainsString('passing and warning grades', $description);
        $this->assertSame($description, Permission::query()->where('code', 'grading.configure')->value('description'));
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function thresholdsUrl(AcademicPeriod $period): string
    {
        return "/academic-periods/{$period->id}/grading-thresholds";
    }

    /**
     * @return array{passing_grade: string, warning_grade: string, reason: string|null}
     */
    private function payload(string $passing, string $warning, ?string $reason = null): array
    {
        return ['passing_grade' => $passing, 'warning_grade' => $warning, 'reason' => $reason];
    }

    /**
     * Sets thresholds directly (test setup only; no audit entry).
     */
    private function setThresholds(AcademicPeriod $period, string $passing, string $warning): void
    {
        DB::table('academic_periods')
            ->where('id', $period->id)
            ->update(['passing_grade' => $passing, 'warning_grade' => $warning]);
        $period->refresh();
    }

    /**
     * The stored values as the database returns them.
     *
     * @return array{string|null, string|null}
     */
    private function storedThresholds(AcademicPeriod $period): array
    {
        $row = DB::table('academic_periods')->where('id', $period->id)->first(['passing_grade', 'warning_grade']);

        return [
            $row->passing_grade === null ? null : (string) $row->passing_grade,
            $row->warning_grade === null ? null : (string) $row->warning_grade,
        ];
    }

    private function thresholdAuditCount(): int
    {
        return AuditLog::query()->where('action', AuditAction::GradingThresholdsUpdated->value)->count();
    }

    private function latestThresholdAudit(): ?AuditLog
    {
        return AuditLog::query()
            ->where('action', AuditAction::GradingThresholdsUpdated->value)
            ->where('auditable_type', 'academic_period')
            ->where('auditable_id', $this->activePeriod->id)
            ->latest('id')
            ->first();
    }

    /**
     * A finalized examination in Batch A Subject 1 (Period Current).
     */
    private function finalizedExamination(): Assessment
    {
        $midterm = $this->createAssessment($this->examinations, 'Midterm Examination', '100');
        $this->recordScores($midterm, [$this->candidateInA->id => '80']);
        $this->finalize($midterm);

        return $midterm;
    }

    /**
     * A finalized assessment in Batch Old Subject 1 (Period Past).
     */
    private function finalizedAssessmentInThePastPeriod(): Assessment
    {
        $offering = $this->offering($this->batchOld, Subject::query()->where('code', 'SUBJ-1')->sole());
        $this->setScheme($offering, ['Written Work' => '100']);
        $category = $offering->assessmentCategories()->sole();

        $final = $this->createAssessment($category, 'Final Examination', '100');
        $graduate = Candidate::query()->where('class_batch_id', $this->batchOld->id)->sole();
        $this->recordScores($final, [$graduate->id => '90']);
        $this->finalize($final);

        return $final;
    }

    /**
     * @param  list<PermissionCode>  $permissions
     */
    private function userWithPermissions(string $roleCode, array $permissions): User
    {
        $role = Role::query()->create(['code' => $roleCode, 'name' => Str::headline($roleCode)]);
        $role->permissions()->sync(Permission::query()
            ->whereIn('code', array_map(fn (PermissionCode $permission): string => $permission->value, $permissions))
            ->pluck('id'));

        return User::factory()->create(['role_id' => $role->id]);
    }

    private function assertQueryFails(callable $statement, string $message = 'The database must reject this statement.'): void
    {
        try {
            $statement();
        } catch (QueryException) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail($message);
    }
}
