<?php

namespace Tests\Feature\Campuses;

use App\Enums\AuditAction;
use App\Enums\Permission;
use App\Enums\SystemRole;
use App\Models\AuditLog;
use App\Models\Campus;
use App\Models\ClassBatch;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Academics → Campuses (owner decision 2026-10-03): the institution-wide
 * Admin adds, renames and deactivates campuses; a campus is removed only
 * when nothing ever used it.
 */
class CampusManagementTest extends TestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->userWithRole(SystemRole::SuperAdministrator);
    }

    public function test_the_admin_creates_a_campus_and_it_is_audited(): void
    {
        $this->actingAs($this->admin)
            ->post('/campuses', ['name' => ' North Campus ', 'code' => 'north', 'address' => ''])
            ->assertRedirect(route('campuses.index'));

        $campus = Campus::query()->where('code', 'NORTH')->sole();
        $this->assertSame('North Campus', $campus->name);
        $this->assertNull($campus->address);
        $this->assertTrue($campus->is_active);

        $entry = AuditLog::query()->where('action', AuditAction::CampusCreated->value)->sole();
        $this->assertSame($this->admin->id, $entry->actor_id);
        $this->assertSame($campus->id, $entry->auditable_id);
    }

    public function test_names_and_codes_are_unique(): void
    {
        Campus::factory()->create(['name' => 'North Campus', 'code' => 'NORTH']);

        $this->actingAs($this->admin)
            ->post('/campuses', ['name' => 'North Campus', 'code' => 'north'])
            ->assertSessionHasErrors(['name' => 'Another campus already uses this name.', 'code' => 'Another campus already uses this code.']);

        $this->actingAs($this->admin)
            ->post('/campuses', ['name' => 'South Campus', 'code' => 'SOUTH CAMPUS'])
            ->assertSessionHasErrors(['code' => 'Use only letters, numbers, hyphens and underscores.']);

        $this->assertSame(1, Campus::query()->count());
    }

    public function test_the_list_counts_classes_candidates_and_staff(): void
    {
        $campus = Campus::factory()->create(['name' => 'North Campus', 'code' => 'NORTH']);
        ClassBatch::factory()->onCampus($campus)->create();
        User::factory()->withRole(SystemRole::Instructor)->onCampus($campus)->create();

        $this->actingAs($this->admin)->get('/campuses')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('staff/campuses/index')
                ->where('campuses.0.code', 'NORTH')
                ->where('campuses.0.classCount', 1)
                ->where('campuses.0.staffCount', 1));
    }

    public function test_a_campus_in_use_is_deactivated_not_removed(): void
    {
        $campus = Campus::factory()->create(['name' => 'North Campus', 'code' => 'NORTH']);
        ClassBatch::factory()->onCampus($campus)->create();

        $this->actingAs($this->admin)
            ->delete("/campuses/{$campus->id}")
            ->assertRedirect(route('campuses.edit', $campus));
        $this->assertModelExists($campus);

        $this->actingAs($this->admin)
            ->put("/campuses/{$campus->id}", ['name' => 'North Campus', 'code' => 'NORTH', 'address' => 'Sample Road', 'is_active' => false])
            ->assertRedirect(route('campuses.index'));
        $this->assertFalse($campus->fresh()->is_active);
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::CampusUpdated->value)->count());

        // An inactive campus takes no new classes.
        $this->assertNotContains($campus->id, $this->admin->campusScope()->assignableIds());
    }

    public function test_an_unused_campus_can_be_removed(): void
    {
        $campus = Campus::factory()->create(['name' => 'Spare Campus', 'code' => 'SPARE']);

        $this->actingAs($this->admin)
            ->delete("/campuses/{$campus->id}")
            ->assertRedirect(route('campuses.index'));

        $this->assertModelMissing($campus);
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::CampusDeleted->value)->count());
    }

    public function test_only_the_institution_wide_admin_manages_campuses(): void
    {
        $campus = Campus::factory()->create(['code' => 'NORTH']);
        $campusAdmin = $this->userWithRole(SystemRole::SuperAdministrator, ['campus_id' => $campus->id]);
        $academicAdmin = $this->userWithRole(SystemRole::AcademicAdministrator);

        // A campus-limited Admin never holds the permission, whatever the role grants.
        $this->assertFalse($campusAdmin->hasPermission(Permission::ManageCampuses));
        $this->assertNotContains(Permission::ManageCampuses->value, $campusAdmin->permissionCodes());

        foreach ([$campusAdmin, $academicAdmin] as $user) {
            $this->actingAs($user)->get('/campuses')->assertForbidden();
            $this->actingAs($user)->post('/campuses', ['name' => 'South Campus', 'code' => 'SOUTH'])->assertForbidden();
            $this->actingAs($user)->put("/campuses/{$campus->id}", ['name' => 'Renamed', 'code' => 'NORTH', 'is_active' => true])->assertForbidden();
            $this->actingAs($user)->delete("/campuses/{$campus->id}")->assertForbidden();
        }

        $this->assertSame(1, Campus::query()->count());
        $this->assertNotSame('Renamed', $campus->fresh()->name);
    }
}
