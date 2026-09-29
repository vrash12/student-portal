<?php

namespace Tests\Feature\Academic;

use App\Enums\AuditAction;
use App\Enums\SystemRole;
use App\Models\AuditLog;
use App\Models\Subject;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SubjectTest extends TestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->userWithRole(SystemRole::AcademicAdministrator);
    }

    public function test_administrator_creates_a_subject(): void
    {
        $this->actingAs($this->admin)
            ->post('/subjects', ['code' => ' SUBJ-9 ', 'name' => ' Subject 9 ', 'description' => '  '])
            ->assertRedirect(route('subjects.index'));

        $subject = Subject::query()->where('code', 'SUBJ-9')->sole();
        $this->assertSame('Subject 9', $subject->name);
        $this->assertNull($subject->description);
        $this->assertTrue($subject->is_active);
        $this->assertDatabaseHas('audit_logs', ['action' => AuditAction::SubjectCreated->value, 'auditable_id' => $subject->id]);
    }

    public function test_subject_codes_must_be_unique_and_well_formed(): void
    {
        Subject::factory()->create(['code' => 'SUBJ-1']);

        $this->actingAs($this->admin)
            ->post('/subjects', ['code' => 'subj-1', 'name' => 'Duplicate'])
            ->assertSessionHasErrors(['code' => 'Another subject already uses this code.']);

        $this->actingAs($this->admin)
            ->post('/subjects', ['code' => 'SUBJ 2!', 'name' => 'Bad Code'])
            ->assertSessionHasErrors(['code' => 'Use only letters, numbers, periods, hyphens, and underscores.']);
    }

    public function test_deactivating_a_subject_is_audited(): void
    {
        $subject = Subject::factory()->create(['code' => 'SUBJ-3', 'name' => 'Subject 3']);

        $this->actingAs($this->admin)
            ->put("/subjects/{$subject->id}", ['code' => 'SUBJ-3', 'name' => 'Subject 3', 'description' => '', 'is_active' => false])
            ->assertRedirect(route('subjects.index'));

        $this->assertFalse($subject->fresh()->is_active);
        $entry = AuditLog::query()->where('action', AuditAction::SubjectUpdated->value)->sole();
        $this->assertSame(['is_active' => true], $entry->old_values);
        $this->assertSame(['is_active' => false], $entry->new_values);
    }

    public function test_index_searches_and_filters_by_status(): void
    {
        Subject::factory()->create(['code' => 'SUBJ-1', 'name' => 'Subject 1']);
        Subject::factory()->inactive()->create(['code' => 'SUBJ-2', 'name' => 'Subject 2']);

        $this->actingAs($this->admin)
            ->get('/subjects?search=subj-2')
            ->assertInertia(fn (Assert $page) => $page->component('staff/subjects/index')->has('subjects.data', 1)->where('subjects.data.0.code', 'SUBJ-2'));

        $this->actingAs($this->admin)
            ->get('/subjects?status=active')
            ->assertInertia(fn (Assert $page) => $page->has('subjects.data', 1)->where('subjects.data.0.code', 'SUBJ-1'));
    }

    public function test_instructors_cannot_manage_subjects(): void
    {
        $instructor = $this->userWithRole(SystemRole::Instructor);

        $this->actingAs($instructor)->get('/subjects')->assertForbidden();
        $this->actingAs($instructor)->post('/subjects', ['code' => 'SUBJ-5', 'name' => 'Subject 5'])->assertForbidden();

        $this->assertDatabaseMissing('subjects', ['code' => 'SUBJ-5']);
    }
}
