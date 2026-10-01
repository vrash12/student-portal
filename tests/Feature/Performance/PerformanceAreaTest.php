<?php

namespace Tests\Feature\Performance;

use App\Enums\AuditAction;
use App\Enums\PerformanceSource;
use App\Enums\SystemRole;
use App\Models\AuditLog;
use App\Models\PerformanceArea;
use App\Models\Subject;
use App\Models\User;
use App\Services\Performance\PerformanceAreaService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Performance areas (owner request, 2026-10-01): configurable areas with a
 * weight, passing grade and must-pass flag; subjects mapped to subject areas
 * only; at most one active fitness, conduct and attendance area; every
 * change audited; administrators only.
 */
class PerformanceAreaTest extends TestCase
{
    private User $admin;

    private Subject $subject1;

    private Subject $subject2;

    private Subject $subject3;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->userWithRole(SystemRole::AcademicAdministrator);
        $this->subject1 = Subject::factory()->create(['code' => 'SUBJ-1', 'name' => 'Subject 1']);
        $this->subject2 = Subject::factory()->create(['code' => 'SUBJ-2', 'name' => 'Subject 2']);
        $this->subject3 = Subject::factory()->create(['code' => 'SUBJ-3', 'name' => 'Subject 3']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'name' => 'Academic',
            'description' => '',
            'source' => 'subjects',
            'weight' => '40',
            'passing_grade' => '75',
            'must_pass' => true,
            'sort_order' => '1',
            'is_active' => true,
            'subject_ids' => [],
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function create(array $overrides = []): PerformanceArea
    {
        $this->actingAs($this->admin)
            ->from('/performance-areas/create')
            ->post('/performance-areas', $this->payload($overrides))
            ->assertRedirect('/performance-areas')
            ->assertSessionHasNoErrors();

        return PerformanceArea::query()->latest('id')->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function conductPayload(array $overrides = []): array
    {
        return $this->payload([
            'name' => 'Conduct',
            'source' => 'conduct',
            'weight' => '10',
            'base_rating' => '85',
            'merit_value' => '1',
            'demerit_value' => '1.5',
            ...$overrides,
        ]);
    }

    public function test_a_subject_area_is_created_with_its_subjects_and_audited(): void
    {
        $area = $this->create(['subject_ids' => [$this->subject2->id, $this->subject1->id], 'description' => '  Academic subjects  ']);

        $this->assertSame('Academic', $area->name);
        $this->assertSame('Academic subjects', $area->description);
        $this->assertSame(PerformanceSource::Subjects, $area->source);
        $this->assertSame('40.00', $area->weight);
        $this->assertSame('75.00', $area->passing_grade);
        $this->assertTrue($area->must_pass);
        $this->assertTrue($area->is_active);
        $this->assertNull($area->base_rating);
        $this->assertSame([$this->subject1->id, $this->subject2->id], $area->subjects()->orderBy('id')->pluck('id')->all());
        $this->assertNull($this->subject3->fresh()->performance_area_id);

        $log = AuditLog::query()->where('action', AuditAction::PerformanceAreaCreated->value)->sole();
        $this->assertSame('performance_area', $log->auditable_type);
        $this->assertSame($area->id, $log->auditable_id);
        $this->assertSame($this->admin->id, $log->actor_id);
        $this->assertSame(['SUBJ-1 Subject 1', 'SUBJ-2 Subject 2'], $log->new_values['subjects']);
        $this->assertSame('40.00', $log->new_values['weight']);
        $this->assertSame('subjects', $log->new_values['source']);
    }

    public function test_a_conduct_area_keeps_its_rating_rule(): void
    {
        $area = $this->create($this->conductPayload());

        $this->assertSame(PerformanceSource::Conduct, $area->source);
        $this->assertSame('85.00', $area->base_rating);
        $this->assertSame('1.00', $area->merit_value);
        $this->assertSame('1.50', $area->demerit_value);
    }

    public function test_updates_record_only_what_changed(): void
    {
        $area = $this->create(['subject_ids' => [$this->subject1->id]]);

        $this->actingAs($this->admin)->put("/performance-areas/{$area->id}", $this->payload([
            'passing_grade' => '77.5',
            'must_pass' => false,
            'subject_ids' => [$this->subject1->id, $this->subject3->id],
        ]))->assertRedirect('/performance-areas')->assertSessionHasNoErrors();

        $area->refresh();
        $this->assertSame('77.50', $area->passing_grade);
        $this->assertFalse($area->must_pass);

        $log = AuditLog::query()->where('action', AuditAction::PerformanceAreaUpdated->value)->sole();
        $this->assertSame(['passing_grade' => '75.00', 'must_pass' => true, 'subjects' => ['SUBJ-1 Subject 1']], $log->old_values);
        $this->assertSame(['passing_grade' => '77.50', 'must_pass' => false, 'subjects' => ['SUBJ-1 Subject 1', 'SUBJ-3 Subject 3']], $log->new_values);

        // Saving the same values changes nothing and records nothing.
        $this->actingAs($this->admin)->put("/performance-areas/{$area->id}", $this->payload([
            'passing_grade' => '77.50',
            'must_pass' => false,
            'subject_ids' => [$this->subject3->id, $this->subject1->id],
        ]))->assertSessionHasNoErrors();
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::PerformanceAreaUpdated->value)->count());
    }

    public function test_a_subject_moves_from_one_area_to_another_and_both_are_audited(): void
    {
        $academic = $this->create(['subject_ids' => [$this->subject1->id, $this->subject2->id]]);
        $skills = $this->create(['name' => 'Military Skills', 'weight' => '20', 'subject_ids' => [$this->subject2->id, $this->subject3->id]]);

        $this->assertSame($skills->id, $this->subject2->fresh()->performance_area_id);
        $this->assertSame([$this->subject1->id], $academic->subjects()->pluck('id')->all());

        $moved = AuditLog::query()
            ->where('action', AuditAction::PerformanceAreaUpdated->value)
            ->where('auditable_id', $academic->id)
            ->sole();
        $this->assertSame(['subjects' => ['SUBJ-1 Subject 1', 'SUBJ-2 Subject 2']], $moved->old_values);
        $this->assertSame(['subjects' => ['SUBJ-1 Subject 1']], $moved->new_values);
        $this->assertSame('Subjects moved to Military Skills.', $moved->reason);
    }

    public function test_only_subject_areas_can_hold_subjects(): void
    {
        $this->actingAs($this->admin)->post('/performance-areas', $this->payload([
            'name' => 'Attendance',
            'source' => 'attendance',
            'subject_ids' => [$this->subject1->id],
        ]))->assertSessionHasErrors(['subject_ids' => 'Only areas based on subject grades can hold subjects.']);
        $this->assertSame(0, PerformanceArea::query()->count());

        // The service refuses it too.
        try {
            $this->app->make(PerformanceAreaService::class)->create([
                'name' => 'Attendance', 'description' => null, 'source' => 'attendance', 'weight' => '10', 'passing_grade' => '90',
                'must_pass' => true, 'base_rating' => null, 'merit_value' => null, 'demerit_value' => null, 'sort_order' => 1, 'is_active' => true,
            ], [$this->subject1->id]);
            $this->fail('A non-subject area was given subjects.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('subject_ids', $exception->errors());
        }
        $this->assertSame(0, PerformanceArea::query()->count());

        // Changing a subject area to another source releases its subjects.
        $area = $this->create(['subject_ids' => [$this->subject1->id]]);
        $this->actingAs($this->admin)->put("/performance-areas/{$area->id}", $this->payload(['source' => 'attendance', 'passing_grade' => '90']))
            ->assertSessionHasNoErrors();
        $this->assertNull($this->subject1->fresh()->performance_area_id);
        $this->assertSame(['subjects' => []], array_intersect_key(
            AuditLog::query()->where('action', AuditAction::PerformanceAreaUpdated->value)->sole()->new_values,
            ['subjects' => true],
        ));
    }

    public function test_only_one_fitness_conduct_and_attendance_area_can_be_active(): void
    {
        $this->create(['name' => 'Physical Fitness', 'source' => 'fitness', 'weight' => '20', 'passing_grade' => '60']);

        $this->actingAs($this->admin)->post('/performance-areas', $this->payload(['name' => 'Physical Fitness 2', 'source' => 'fitness']))
            ->assertSessionHasErrors(['source' => 'Physical Fitness is already the active Military Fitness area. Only one can be active: deactivate it first, or save this area as inactive.']);

        // An inactive second one can be prepared, but not activated while the first is active.
        $spare = $this->create(['name' => 'Physical Fitness 2', 'source' => 'fitness', 'is_active' => false]);
        $this->assertFalse($spare->is_active);
        $this->actingAs($this->admin)->put("/performance-areas/{$spare->id}", $this->payload(['name' => 'Physical Fitness 2', 'source' => 'fitness', 'is_active' => true]))
            ->assertSessionHasErrors('is_active');

        // Several subject areas may be active together.
        $this->create(['name' => 'Academic']);
        $this->create(['name' => 'Military Skills']);

        // The service checks again under a lock.
        $this->expectException(ValidationException::class);
        try {
            $this->app->make(PerformanceAreaService::class)->update($spare, [
                'name' => 'Physical Fitness 2', 'description' => null, 'source' => 'fitness', 'weight' => '20', 'passing_grade' => '60',
                'must_pass' => true, 'base_rating' => null, 'merit_value' => null, 'demerit_value' => null, 'sort_order' => 1, 'is_active' => true,
            ]);
        } finally {
            $this->assertFalse($spare->fresh()->is_active);
        }
    }

    public function test_the_database_also_allows_a_single_active_area_per_source(): void
    {
        $this->create(['name' => 'Attendance', 'source' => 'attendance', 'passing_grade' => '90']);

        $this->expectException(QueryException::class);
        DB::table('performance_areas')->insert([
            'name' => 'Attendance 2', 'source' => 'attendance', 'weight' => 10, 'passing_grade' => 90, 'must_pass' => true,
            'sort_order' => 2, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_conduct_values_are_required_for_conduct_areas_and_refused_for_others(): void
    {
        $this->actingAs($this->admin)->post('/performance-areas', $this->conductPayload(['base_rating' => '', 'merit_value' => '101', 'demerit_value' => '-1']))
            ->assertSessionHasErrors(['base_rating' => 'Enter the base rating.', 'merit_value', 'demerit_value']);

        $this->actingAs($this->admin)->post('/performance-areas', $this->payload(['base_rating' => '85']))
            ->assertSessionHasErrors(['base_rating' => 'The conduct rating values apply to conduct areas only.']);

        $this->assertSame(0, PerformanceArea::query()->count());

        // Changing a conduct area to another source clears its rating rule.
        $area = $this->create($this->conductPayload());
        $this->actingAs($this->admin)->put("/performance-areas/{$area->id}", $this->payload(['name' => 'Conduct', 'source' => 'attendance']))
            ->assertSessionHasNoErrors();
        $area->refresh();
        $this->assertSame(PerformanceSource::Attendance, $area->source);
        $this->assertNull($area->base_rating);
        $this->assertNull($area->merit_value);
        $this->assertNull($area->demerit_value);
    }

    public function test_area_values_are_validated(): void
    {
        $this->create();

        $this->actingAs($this->admin)->post('/performance-areas', $this->payload([
            'name' => 'Academic',
            'weight' => '100.5',
            'passing_grade' => '0',
            'source' => 'grades',
            'must_pass' => 'maybe',
            'sort_order' => '1000',
        ]))->assertSessionHasErrors([
            'name' => 'Another performance area already uses this name.',
            'weight' => 'Enter the weight as a number from 0 to 100, for example 40.',
            'passing_grade' => 'Enter a passing grade greater than 0.',
            'source' => 'Choose where the area takes its grade from.',
            'must_pass',
            'sort_order',
        ]);

        $this->actingAs($this->admin)->post('/performance-areas', $this->payload(['name' => 'Other', 'weight' => '10.555', 'passing_grade' => '100.01']))
            ->assertSessionHasErrors(['weight' => 'Use at most two decimal places.', 'passing_grade' => 'A passing grade cannot be more than 100.']);

        $this->actingAs($this->admin)->post('/performance-areas', $this->payload(['name' => 'Other', 'subject_ids' => [999999]]))
            ->assertSessionHasErrors('subject_ids.0');

        $this->assertSame(1, PerformanceArea::query()->count());
    }

    public function test_database_constraints_guard_the_values(): void
    {
        $insert = fn (array $values) => DB::table('performance_areas')->insert([
            'name' => 'Area '.uniqid(), 'source' => 'subjects', 'weight' => 10, 'passing_grade' => 75, 'must_pass' => true,
            'sort_order' => 1, 'is_active' => false, 'created_at' => now(), 'updated_at' => now(), ...$values,
        ]);

        foreach ([
            ['source' => 'grades'],
            ['weight' => 100.5],
            ['passing_grade' => 0],
            ['base_rating' => 85, 'merit_value' => 1, 'demerit_value' => 1],
            ['source' => 'conduct'],
            ['source' => 'conduct', 'base_rating' => 101, 'merit_value' => 1, 'demerit_value' => 1],
        ] as $values) {
            try {
                $insert($values);
                $this->fail('Accepted invalid values: '.json_encode($values));
            } catch (QueryException) {
                // Refused by a CHECK constraint.
            }
        }

        $insert(['source' => 'conduct', 'base_rating' => 85, 'merit_value' => 0, 'demerit_value' => 1]);
        $this->assertSame(1, PerformanceArea::query()->count());
    }

    public function test_the_list_shows_areas_weights_and_unmapped_subjects(): void
    {
        $academic = $this->create(['subject_ids' => [$this->subject1->id]]);
        $this->create(['name' => 'Physical Fitness', 'source' => 'fitness', 'weight' => '20.5', 'passing_grade' => '60', 'sort_order' => '2']);
        $this->create(['name' => 'Retired', 'weight' => '30', 'sort_order' => '3', 'is_active' => false, 'must_pass' => false]);
        Subject::factory()->inactive()->create(['code' => 'SUBJ-9', 'name' => 'Old Subject']);

        $this->actingAs($this->admin)->get('/performance-areas')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('staff/performance-areas/index')
                ->has('areas', 3)
                ->where('areas.0.name', 'Academic')
                ->where('areas.0.source', ['value' => 'subjects', 'label' => 'Subject Grades'])
                ->where('areas.0.weight', '40.00')
                ->where('areas.0.subjects.0.code', 'SUBJ-1')
                ->where('areas.1.source.value', 'fitness')
                ->where('areas.2.isActive', false)
                // Inactive areas do not count.
                ->where('activeWeightTotal', 60.5)
                ->where('hasActiveMustPass', true)
                ->where('unmappedSubjects', [
                    ['id' => $this->subject2->id, 'code' => 'SUBJ-2', 'name' => 'Subject 2'],
                    ['id' => $this->subject3->id, 'code' => 'SUBJ-3', 'name' => 'Subject 3'],
                ]));

        $this->actingAs($this->admin)->get('/performance-areas/create')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('staff/performance-areas/create')
                ->has('sources', 4)
                ->where('nextSortOrder', 4)
                ->where('activeSingleSourceAreas', ['fitness' => 'Physical Fitness'])
                ->where('subjects.0.code', 'SUBJ-1')
                ->where('subjects.0.area', ['id' => $academic->id, 'name' => 'Academic'])
                // Inactive subjects are listed last.
                ->where('subjects.3.code', 'SUBJ-9'));

        $fitness = PerformanceArea::query()->where('name', 'Physical Fitness')->sole();
        $this->actingAs($this->admin)->get("/performance-areas/{$fitness->id}/edit")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('staff/performance-areas/edit')
                ->where('area.name', 'Physical Fitness')
                ->where('area.weight', '20.50')
                ->where('area.subjectIds', [])
                // The area being edited is not a conflict with itself.
                ->where('activeSingleSourceAreas', []));
    }

    public function test_the_super_administrator_can_configure_areas(): void
    {
        $this->admin = $this->userWithRole(SystemRole::SuperAdministrator);

        $area = $this->create(['subject_ids' => [$this->subject1->id]]);

        $this->actingAs($this->admin)->get("/performance-areas/{$area->id}/edit")->assertOk();
    }

    public function test_instructors_and_candidates_cannot_configure_areas(): void
    {
        $area = $this->create();
        $instructor = $this->userWithRole(SystemRole::Instructor);
        $candidate = $this->userWithRole(SystemRole::Candidate);

        foreach ([$instructor, $candidate] as $user) {
            foreach (['/performance-areas', '/performance-areas/create', "/performance-areas/{$area->id}/edit"] as $url) {
                $this->actingAs($user)->get($url)->assertForbidden();
            }
            $this->actingAs($user)->post('/performance-areas', $this->payload(['name' => 'Other']))->assertForbidden();
            $this->actingAs($user)->put("/performance-areas/{$area->id}", $this->payload(['name' => 'Renamed']))->assertForbidden();
        }

        $this->assertSame(1, PerformanceArea::query()->count());
        $this->assertSame('Academic', $area->fresh()->name);
    }
}
