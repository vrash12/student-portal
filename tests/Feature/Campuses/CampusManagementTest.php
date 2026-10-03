<?php

namespace Tests\Feature\Campuses;

use App\Enums\AuditAction;
use App\Enums\CampusCode;
use App\Enums\Permission;
use App\Enums\SystemRole;
use App\Models\AuditLog;
use App\Models\Campus;
use App\Models\ClassBatch;
use App\Models\User;
use Database\Factories\CampusFactory;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Academics → Campuses: the institution has exactly four campuses, South,
 * North, East and West (owner decision 2026-10-04). None is added or
 * removed; the institution-wide Admin changes a campus's address and
 * switches it on or off.
 */
class CampusManagementTest extends TestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->userWithRole(SystemRole::SuperAdministrator);
    }

    public function test_the_four_campuses_exist_and_are_listed_in_the_fixed_order(): void
    {
        $north = CampusFactory::fixed(CampusCode::North);
        ClassBatch::factory()->onCampus($north)->create();
        User::factory()->withRole(SystemRole::Instructor)->onCampus($north)->create();

        $this->actingAs($this->admin)->get('/campuses')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('staff/campuses/index')
                ->has('campuses', 4)
                ->where('campuses.0.name', 'South Campus')
                ->where('campuses.1.code', 'NORTH')
                ->where('campuses.1.classCount', 1)
                ->where('campuses.1.staffCount', 1)
                ->where('campuses.2.code', 'EAST')
                ->where('campuses.3.code', 'WEST'));
    }

    public function test_no_campus_can_be_added_or_removed(): void
    {
        $south = CampusFactory::fixed(CampusCode::South);

        $this->actingAs($this->admin)->get('/campuses/create')->assertNotFound();
        $this->actingAs($this->admin)->post('/campuses', ['name' => 'Central Campus', 'code' => 'CENTRAL'])->assertMethodNotAllowed();
        $this->actingAs($this->admin)->delete("/campuses/{$south->id}")->assertMethodNotAllowed();

        // The database refuses a fifth campus too.
        $this->assertThrows(
            fn () => DB::table('campuses')->insert(['name' => 'Central Campus', 'code' => 'CENTRAL', 'is_active' => true]),
            QueryException::class,
        );
        $this->assertSame(4, Campus::query()->count());
        $this->assertModelExists($south);
    }

    public function test_the_admin_changes_the_address_and_switches_a_campus_off(): void
    {
        $campus = CampusFactory::fixed(CampusCode::East);

        $this->actingAs($this->admin)
            ->put("/campuses/{$campus->id}", ['address' => ' Sample Road ', 'is_active' => false])
            ->assertRedirect(route('campuses.index'));

        $campus->refresh();
        $this->assertSame('Sample Road', $campus->address);
        $this->assertFalse($campus->is_active);
        $this->assertSame(['East Campus', 'EAST'], [$campus->name, $campus->code]);
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::CampusUpdated->value)->count());

        // A campus switched off takes no new classes.
        $this->assertNotContains($campus->id, $this->admin->campusScope()->assignableIds());
    }

    public function test_names_and_codes_are_fixed(): void
    {
        $campus = CampusFactory::fixed(CampusCode::West);

        $this->actingAs($this->admin)
            ->put("/campuses/{$campus->id}", ['name' => 'Renamed', 'code' => 'RENAMED', 'address' => '', 'is_active' => true])
            ->assertSessionHasErrors(['name' => 'The names of the four campuses are fixed.', 'code' => 'The codes of the four campuses are fixed.']);

        $this->assertSame(['West Campus', 'WEST'], [$campus->fresh()->name, $campus->fresh()->code]);
    }

    public function test_only_the_institution_wide_admin_manages_campuses(): void
    {
        $campus = CampusFactory::fixed(CampusCode::North);
        $campusAdmin = $this->userWithRole(SystemRole::SuperAdministrator, ['campus_id' => $campus->id]);
        $academicAdmin = $this->userWithRole(SystemRole::AcademicAdministrator);

        // A campus-limited Admin never holds the permission, whatever the role grants.
        $this->assertFalse($campusAdmin->hasPermission(Permission::ManageCampuses));
        $this->assertNotContains(Permission::ManageCampuses->value, $campusAdmin->permissionCodes());

        foreach ([$campusAdmin, $academicAdmin] as $user) {
            $this->actingAs($user)->get('/campuses')->assertForbidden();
            $this->actingAs($user)->get("/campuses/{$campus->id}/edit")->assertForbidden();
            $this->actingAs($user)->put("/campuses/{$campus->id}", ['address' => 'Changed', 'is_active' => false])->assertForbidden();
        }

        $this->assertNull($campus->fresh()->address);
        $this->assertTrue($campus->fresh()->is_active);
    }
}
