<?php

namespace Tests\Feature\Academic;

use App\Enums\AuditAction;
use App\Enums\SystemRole;
use App\Models\AcademicPeriod;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\QueryException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AcademicPeriodTest extends TestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->userWithRole(SystemRole::AcademicAdministrator);
    }

    public function test_administrator_lists_periods_with_class_counts(): void
    {
        AcademicPeriod::factory()->active()->create(['name' => 'Period Current', 'starts_on' => '2026-08-03']);
        AcademicPeriod::factory()->create(['name' => 'Period Earlier', 'starts_on' => '2026-01-05', 'ends_on' => '2026-05-29']);

        $this->actingAs($this->admin)
            ->get('/academic-periods')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('staff/academic-periods/index')
                ->has('periods', 2)
                ->where('periods.0.name', 'Period Current')
                ->where('periods.0.isActive', true)
                ->where('periods.0.classCount', 0));
    }

    public function test_administrator_creates_a_period(): void
    {
        $this->actingAs($this->admin)
            ->post('/academic-periods', ['name' => ' Period 2027-1 ', 'starts_on' => '2027-01-04', 'ends_on' => '2027-05-28'])
            ->assertRedirect(route('academic-periods.index'))
            ->assertInertiaFlash('toast.type', 'success');

        $period = AcademicPeriod::query()->where('name', 'Period 2027-1')->sole();
        $this->assertFalse($period->is_active);
        $this->assertSame('2027-01-04', $period->starts_on->toDateString());
        $this->assertDatabaseHas('audit_logs', ['action' => AuditAction::AcademicPeriodCreated->value, 'auditable_id' => $period->id]);
    }

    public function test_period_dates_and_name_are_validated(): void
    {
        AcademicPeriod::factory()->create(['name' => 'Taken Name']);

        $this->actingAs($this->admin)
            ->post('/academic-periods', ['name' => 'Taken Name', 'starts_on' => '2027-05-28', 'ends_on' => '2027-01-04'])
            ->assertSessionHasErrors([
                'name' => 'Another academic period already uses this name.',
                'ends_on' => 'The end date must be on or after the start date.',
            ]);

        $this->actingAs($this->admin)
            ->post('/academic-periods', ['name' => 'Bad Dates', 'starts_on' => '04/01/2027', 'ends_on' => ''])
            ->assertSessionHasErrors(['starts_on', 'ends_on']);
    }

    public function test_updating_a_period_records_previous_and_new_values(): void
    {
        $period = AcademicPeriod::factory()->create(['name' => 'Old Name']);

        $this->actingAs($this->admin)
            ->put("/academic-periods/{$period->id}", ['name' => 'New Name', 'starts_on' => '2026-08-03', 'ends_on' => '2026-12-18'])
            ->assertRedirect(route('academic-periods.index'));

        $entry = AuditLog::query()->where('action', AuditAction::AcademicPeriodUpdated->value)->sole();
        $this->assertSame(['name' => 'Old Name'], $entry->old_values);
        $this->assertSame(['name' => 'New Name'], $entry->new_values);
    }

    public function test_activating_a_period_deactivates_the_previous_one(): void
    {
        $current = AcademicPeriod::factory()->active()->create(['name' => 'Current']);
        $next = AcademicPeriod::factory()->create(['name' => 'Next']);

        $this->actingAs($this->admin)
            ->post("/academic-periods/{$next->id}/activate")
            ->assertRedirect(route('academic-periods.index'));

        $this->assertFalse($current->fresh()->is_active);
        $this->assertTrue($next->fresh()->is_active);
        $this->assertSame(1, AcademicPeriod::query()->active()->count());

        $entry = AuditLog::query()->where('action', AuditAction::AcademicPeriodActivated->value)->sole();
        $this->assertSame(['active_period' => 'Current'], $entry->old_values);
        $this->assertSame(['active_period' => 'Next'], $entry->new_values);
    }

    public function test_activating_the_active_period_changes_nothing(): void
    {
        $current = AcademicPeriod::factory()->active()->create();

        $this->actingAs($this->admin)->post("/academic-periods/{$current->id}/activate");

        $this->assertTrue($current->fresh()->is_active);
        $this->assertSame(0, AuditLog::query()->where('action', AuditAction::AcademicPeriodActivated->value)->count());
    }

    public function test_the_database_rejects_a_second_active_period(): void
    {
        AcademicPeriod::factory()->active()->create();

        $this->expectException(QueryException::class);

        AcademicPeriod::factory()->active()->create();
    }

    public function test_the_database_rejects_an_end_date_before_the_start_date(): void
    {
        $this->expectException(QueryException::class);

        AcademicPeriod::factory()->create(['starts_on' => '2026-12-18', 'ends_on' => '2026-08-03']);
    }

    public function test_instructors_cannot_manage_periods(): void
    {
        $instructor = $this->userWithRole(SystemRole::Instructor);
        $period = AcademicPeriod::factory()->create();

        $this->actingAs($instructor)->get('/academic-periods')->assertForbidden();
        $this->actingAs($instructor)
            ->post('/academic-periods', ['name' => 'X', 'starts_on' => '2027-01-04', 'ends_on' => '2027-05-28'])
            ->assertForbidden();
        $this->actingAs($instructor)->post("/academic-periods/{$period->id}/activate")->assertForbidden();

        $this->assertFalse($period->fresh()->is_active);
    }
}
