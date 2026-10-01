<?php

namespace Tests\Feature\Conduct;

use App\Enums\AuditAction;
use App\Enums\CandidateStatus;
use App\Enums\SystemRole;
use App\Models\AcademicPeriod;
use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\ClassBatch;
use App\Models\ConductEntry;
use App\Models\ConductType;
use App\Models\Subject;
use App\Models\User;
use App\Services\ClassBatchService;
use App\Services\Conduct\ConductLedger;
use App\Services\InstructorAssignmentService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Merits and demerits (owner request, 2026-10-01): a ledger of conduct
 * entries per candidate, never edited or deleted (mistakes are voided with a
 * reason). Administrators act on every candidate, instructors only on the
 * classes they teach. Types are configured by administrators.
 */
class ConductTest extends TestCase
{
    private User $admin;

    private User $alpha;

    private ClassBatch $classA;

    private ClassBatch $classB;

    private Candidate $first;

    private Candidate $second;

    private Candidate $other;

    private ConductType $leadership;

    private ConductType $late;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-01 09:00:00');

        $this->admin = $this->userWithRole(SystemRole::AcademicAdministrator);
        $period = AcademicPeriod::factory()->active()->create(['name' => 'Period Current']);
        $this->classA = ClassBatch::factory()->for($period)->create(['name' => 'Class A']);
        $this->classB = ClassBatch::factory()->for($period)->create(['name' => 'Class B']);
        $subject = Subject::factory()->create(['code' => 'SUBJ-1', 'name' => 'Subject 1']);

        // Instructor Alpha teaches Class A only.
        $this->alpha = $this->userWithRole(SystemRole::Instructor, ['name' => 'Instructor Alpha']);
        $offering = $this->app->make(ClassBatchService::class)->addSubject($this->classA, $subject);
        $this->app->make(InstructorAssignmentService::class)->assign($offering, $this->alpha);

        $this->first = Candidate::factory()->create(['class_batch_id' => $this->classA->id, 'candidate_number' => 'C-001', 'last_name' => 'Alpha']);
        $this->second = Candidate::factory()->create(['class_batch_id' => $this->classA->id, 'candidate_number' => 'C-002', 'last_name' => 'Bravo']);
        $this->other = Candidate::factory()->create(['class_batch_id' => $this->classB->id, 'candidate_number' => 'C-003', 'last_name' => 'Charlie']);

        // Seeded by the migration.
        $this->leadership = ConductType::query()->where('name', 'Leadership commendation')->sole();
        $this->late = ConductType::query()->where('name', 'Late for formation')->sole();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function entry(array $overrides = []): array
    {
        return [
            'conduct_type_id' => (string) $this->leadership->id,
            'points' => '3',
            'occurred_on' => '2026-09-15',
            'reason' => 'Led the platoon during the field exercise',
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function record(Candidate $candidate, array $overrides = [], ?User $actor = null): ConductEntry
    {
        $this->actingAs($actor ?? $this->admin)
            ->from("/conduct/candidates/{$candidate->id}")
            ->post("/conduct/candidates/{$candidate->id}/entries", $this->entry($overrides))
            ->assertRedirect("/conduct/candidates/{$candidate->id}")
            ->assertSessionHasNoErrors();

        return ConductEntry::query()->latest('id')->firstOrFail();
    }

    private function void(ConductEntry $entry, string $reason = 'Recorded for the wrong candidate', ?User $actor = null): void
    {
        $this->actingAs($actor ?? $this->admin)
            ->from("/conduct/candidates/{$entry->candidate_id}")
            ->post("/conduct-entries/{$entry->id}/void", ['reason' => $reason])
            ->assertSessionHasNoErrors();
    }

    public function test_the_migration_seeds_placeholder_types(): void
    {
        $this->assertSame(
            [
                ['Outstanding performance', 'merit', 5],
                ['Leadership commendation', 'merit', 3],
                ['Exemplary conduct', 'merit', 2],
                ['Late for formation', 'demerit', 2],
                ['Improper uniform', 'demerit', 1],
                ['Violation of regulations', 'demerit', 5],
            ],
            ConductType::query()->ordered()->get()
                ->map(fn (ConductType $type): array => [$type->name, $type->kind->value, $type->default_points])
                ->all(),
        );
        $this->assertTrue(ConductType::query()->where('is_active', false)->doesntExist());
    }

    public function test_merits_and_demerits_are_recorded_audited_and_totalled(): void
    {
        $merit = $this->record($this->first);
        // The kind always comes from the type; the points may be adjusted.
        $demerit = $this->record($this->first, ['conduct_type_id' => (string) $this->late->id, 'points' => '4', 'kind' => 'merit', 'occurred_on' => '2026-09-20', 'reason' => '  Late for morning formation  ']);
        $this->record($this->first, ['points' => '1', 'occurred_on' => '2026-09-10']);

        $this->assertSame('merit', $merit->kind->value);
        $this->assertSame(3, $merit->points);
        $this->assertSame($this->admin->id, $merit->recorded_by);
        $this->assertSame('demerit', $demerit->kind->value);
        $this->assertSame(4, $demerit->points);
        $this->assertSame('Late for morning formation', $demerit->reason);

        $log = AuditLog::query()->where('action', AuditAction::ConductEntryRecorded->value)->where('auditable_id', $demerit->id)->sole();
        $this->assertSame('conduct_entry', $log->auditable_type);
        // Compared without key order: MySQL's JSON type reorders keys.
        $this->assertEquals([
            'candidate' => 'C-001',
            'type' => 'Late for formation',
            'kind' => 'demerit',
            'points' => 4,
            'occurred_on' => '2026-09-20',
            'reason' => 'Late for morning formation',
        ], $log->new_values);
        $this->assertSame(3, AuditLog::query()->where('action', AuditAction::ConductEntryRecorded->value)->count());

        $this->actingAs($this->admin)->get("/conduct/candidates/{$this->first->id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('staff/conduct/show')
                ->where('candidate.candidateNumber', 'C-001')
                ->where('totals', ['merits' => 4, 'demerits' => 4, 'net' => 0])
                // Newest first by date.
                ->has('entries', 3)
                ->where('entries.0.id', $demerit->id)
                ->where('entries.0.kind.label', 'Demerit')
                ->where('entries.0.type', 'Late for formation')
                ->where('entries.0.recordedBy', $this->admin->name)
                ->where('entries.0.voided', null)
                ->where('entries.1.id', $merit->id)
                ->where('entries.2.occurredOn', '2026-09-10')
                ->where('types.0.name', 'Outstanding performance')
                ->where('types.0.kind', 'merit')
                ->where('types.3.kind', 'demerit')
                ->where('today', '2026-10-01')
                ->where('can.record', true)
                ->where('can.viewCandidate', true)
                ->where('can.configureTypes', true));

        $this->actingAs($this->admin)->get('/conduct')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('staff/conduct/index')
                ->has('candidates.data', 3)
                ->where('candidates.data.0.candidateNumber', 'C-001')
                ->where('candidates.data.0.totals', ['merits' => 4, 'demerits' => 4, 'net' => 0])
                ->where('candidates.data.1.totals', ['merits' => 0, 'demerits' => 0, 'net' => 0])
                ->where('scope', 'all')
                ->where('can.configureTypes', true));
    }

    public function test_a_voided_entry_no_longer_counts_is_audited_and_cannot_be_voided_twice(): void
    {
        $this->record($this->first, ['points' => '5']);
        $mistake = $this->record($this->first, ['conduct_type_id' => (string) $this->late->id, 'points' => '2']);

        $this->actingAs($this->admin)->from("/conduct/candidates/{$this->first->id}")
            ->post("/conduct-entries/{$mistake->id}/void", ['reason' => 'no'])
            ->assertSessionHasErrors(['reason' => 'Give the reason for voiding this entry (at least 5 characters).']);
        $this->actingAs($this->admin)->from("/conduct/candidates/{$this->first->id}")
            ->post("/conduct-entries/{$mistake->id}/void", ['reason' => str_repeat('x', 256)])
            ->assertSessionHasErrors('reason');
        $this->assertNull($mistake->fresh()->voided_at);

        $this->actingAs($this->admin)->from("/conduct/candidates/{$this->first->id}")
            ->post("/conduct-entries/{$mistake->id}/void", ['reason' => '  Recorded twice  '])
            ->assertRedirect("/conduct/candidates/{$this->first->id}")
            ->assertSessionHasNoErrors();

        $voided = $mistake->fresh();
        $this->assertNotNull($voided->voided_at);
        $this->assertSame($this->admin->id, $voided->voided_by);
        $this->assertSame('Recorded twice', $voided->void_reason);
        // Never deleted: the entry and its points remain.
        $this->assertSame(2, $voided->points);

        $log = AuditLog::query()->where('action', AuditAction::ConductEntryVoided->value)->sole();
        $this->assertSame('conduct_entry', $log->auditable_type);
        $this->assertSame($mistake->id, $log->auditable_id);
        $this->assertSame('Recorded twice', $log->reason);
        $this->assertSame('demerit', $log->old_values['kind']);

        $this->actingAs($this->admin)->get("/conduct/candidates/{$this->first->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('totals', ['merits' => 5, 'demerits' => 0, 'net' => 5])
                ->has('entries', 2)
                ->where('entries.0.id', $mistake->id)
                ->where('entries.0.voided.reason', 'Recorded twice')
                ->where('entries.0.voided.by', $this->admin->name));

        $this->actingAs($this->admin)->from("/conduct/candidates/{$this->first->id}")
            ->post("/conduct-entries/{$mistake->id}/void", ['reason' => 'Voiding again'])
            ->assertSessionHasErrors(['reason' => 'This entry has already been voided.']);
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::ConductEntryVoided->value)->count());
    }

    public function test_entries_are_validated(): void
    {
        $url = "/conduct/candidates/{$this->first->id}/entries";
        $cases = [
            ['conduct_type_id', '', 'Choose a merit or demerit type.'],
            ['conduct_type_id', '999999', 'Choose an active merit or demerit type.'],
            ['points', '', 'Enter the points.'],
            ['points', '0', 'Enter the points as a whole number from 1 to 100.'],
            ['points', '101', 'Enter the points as a whole number from 1 to 100.'],
            ['points', '2.5', 'Enter the points as a whole number from 1 to 100.'],
            ['points', 'ten', 'Enter the points as a whole number from 1 to 100.'],
            ['occurred_on', '', 'Enter the date it happened.'],
            ['occurred_on', 'yesterday', 'Enter the date it happened.'],
            ['occurred_on', '2026-10-02', 'The date cannot be in the future.'],
            ['occurred_on', '1999-12-31', 'Enter a date from the year 2000 onward.'],
            ['reason', '   ', 'Describe what happened, for example "Led the platoon during the field exercise".'],
        ];

        foreach ($cases as [$field, $value, $message]) {
            $this->actingAs($this->admin)->from("/conduct/candidates/{$this->first->id}")
                ->post($url, $this->entry([$field => $value]))
                ->assertSessionHasErrors([$field => $message]);
        }

        $this->actingAs($this->admin)->from("/conduct/candidates/{$this->first->id}")
            ->post($url, $this->entry(['reason' => str_repeat('x', 256)]))
            ->assertSessionHasErrors('reason');

        $this->assertSame(0, ConductEntry::query()->count());

        // The bounds themselves are accepted, as is today.
        $this->assertSame(100, $this->record($this->first, ['points' => '100', 'occurred_on' => '2026-10-01'])->points);
        $this->assertSame(1, $this->record($this->first, ['points' => ' 1 '])->points);
    }

    public function test_an_inactive_type_cannot_be_used_or_offered(): void
    {
        $this->leadership->forceFill(['is_active' => false])->save();

        $this->actingAs($this->admin)->from("/conduct/candidates/{$this->first->id}")
            ->post("/conduct/candidates/{$this->first->id}/entries", $this->entry())
            ->assertSessionHasErrors(['conduct_type_id' => 'Choose an active merit or demerit type.']);

        $this->actingAs($this->admin)->get("/conduct/candidates/{$this->first->id}")
            ->assertInertia(fn (Assert $page) => $page->has('types', 5)
                ->where('types', fn ($types): bool => ! collect($types)->contains('name', 'Leadership commendation')));
        $this->assertSame(0, ConductEntry::query()->count());
    }

    public function test_withdrawn_candidates_receive_no_new_entries_but_mistakes_can_be_voided(): void
    {
        $entry = $this->record($this->first);
        $this->first->forceFill(['status' => CandidateStatus::Withdrawn->value])->save();

        $this->actingAs($this->admin)->get("/conduct/candidates/{$this->first->id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('can.record', false));

        $this->actingAs($this->admin)->from("/conduct/candidates/{$this->first->id}")
            ->post("/conduct/candidates/{$this->first->id}/entries", $this->entry())
            ->assertSessionHasErrors(['candidate' => 'Merits and demerits cannot be recorded for a withdrawn candidate.']);
        $this->assertSame(1, ConductEntry::query()->count());

        $this->void($entry);
        $this->assertNotNull($entry->fresh()->voided_at);
    }

    public function test_instructors_act_only_on_candidates_of_the_classes_they_teach(): void
    {
        $own = $this->record($this->first, actor: $this->alpha);
        $this->assertSame($this->alpha->id, $own->recorded_by);

        $this->actingAs($this->alpha)->get("/conduct/candidates/{$this->first->id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('can.record', true)
                ->where('can.viewCandidate', true)
                ->where('can.configureTypes', false));

        $this->actingAs($this->alpha)->get('/conduct')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('scope', 'taught')
                ->has('candidates.data', 2)
                ->where('candidates.data.0.candidateNumber', 'C-001')
                ->where('candidates.data.0.totals.merits', 3)
                ->where('candidates.data.1.candidateNumber', 'C-002')
                ->has('classOptions', 1)
                ->where('classOptions.0.classes', [['id' => $this->classA->id, 'name' => 'Class A']])
                ->where('can.configureTypes', false));

        // A class outside the scope lists nobody; searching never widens it.
        $this->actingAs($this->alpha)->get("/conduct?class={$this->classB->id}")
            ->assertInertia(fn (Assert $page) => $page->has('candidates.data', 0));
        $this->actingAs($this->alpha)->get('/conduct?search=Charlie')
            ->assertInertia(fn (Assert $page) => $page->has('candidates.data', 0));

        // Another class: forbidden even with the URL.
        $foreign = $this->record($this->other);
        $this->actingAs($this->alpha)->get("/conduct/candidates/{$this->other->id}")->assertForbidden();
        $this->actingAs($this->alpha)->post("/conduct/candidates/{$this->other->id}/entries", $this->entry())->assertForbidden();
        $this->actingAs($this->alpha)->post("/conduct-entries/{$foreign->id}/void", ['reason' => 'Not my class'])->assertForbidden();
        $this->assertNull($foreign->fresh()->voided_at);
        $this->assertSame(1, ConductEntry::query()->where('candidate_id', $this->other->id)->count());

        // Own class: voiding is allowed.
        $this->void($own, actor: $this->alpha);
        $this->assertSame($this->alpha->id, $own->fresh()->voided_by);
    }

    public function test_the_scope_follows_the_current_class_and_assignment(): void
    {
        $entry = $this->record($this->first, actor: $this->alpha);

        // Moved to a class Alpha does not teach: out of scope.
        $this->first->forceFill(['class_batch_id' => $this->classB->id])->save();
        $this->actingAs($this->alpha)->get("/conduct/candidates/{$this->first->id}")->assertForbidden();
        $this->actingAs($this->alpha)->post("/conduct-entries/{$entry->id}/void", ['reason' => 'Out of scope'])->assertForbidden();

        // Without a class, only users who can view all candidates act.
        $this->first->forceFill(['class_batch_id' => null])->save();
        $this->actingAs($this->alpha)->get("/conduct/candidates/{$this->first->id}")->assertForbidden();
        $this->actingAs($this->admin)->get("/conduct/candidates/{$this->first->id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('candidate.classBatch', null));
        $this->record($this->first);
    }

    public function test_types_are_managed_by_administrators_and_audited(): void
    {
        $this->actingAs($this->admin)->get('/conduct/types')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('staff/conduct/types/index')
                ->has('types', 6)
                ->where('types.0.name', 'Outstanding performance')
                ->where('types.0.kind.label', 'Merit')
                ->where('types.0.entryCount', 0));
        $this->actingAs($this->admin)->get('/conduct/types/create')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('staff/conduct/types/create')->has('kinds', 2)->where('nextSortOrder', 7));

        $this->actingAs($this->admin)->post('/conduct/types', [
            'name' => '  Unauthorized absence  ',
            'kind' => 'demerit',
            'default_points' => '10',
            'description' => '',
            'sort_order' => '7',
        ])->assertRedirect('/conduct/types')->assertSessionHasNoErrors();

        $type = ConductType::query()->where('name', 'Unauthorized absence')->sole();
        $this->assertSame('demerit', $type->kind->value);
        $this->assertSame(10, $type->default_points);
        $this->assertNull($type->description);
        $this->assertTrue($type->is_active);
        $this->assertEquals(
            ['name' => 'Unauthorized absence', 'kind' => 'demerit', 'default_points' => 10, 'description' => null, 'sort_order' => 7, 'is_active' => true],
            AuditLog::query()->where('action', AuditAction::ConductTypeCreated->value)->sole()->new_values,
        );

        // Recorded entries keep their kind and points when the type changes.
        $entry = $this->record($this->first, ['conduct_type_id' => (string) $type->id, 'points' => '10']);

        $this->actingAs($this->admin)->get("/conduct/types/{$type->id}/edit")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('staff/conduct/types/edit')->where('type.entryCount', 1));

        $this->actingAs($this->admin)->put("/conduct/types/{$type->id}", [
            'name' => 'Absence without leave',
            'kind' => 'demerit',
            'default_points' => '8',
            'description' => 'Away from the unit without permission',
            'sort_order' => '7',
            'is_active' => false,
        ])->assertRedirect('/conduct/types')->assertSessionHasNoErrors();

        $type->refresh();
        $this->assertSame('Absence without leave', $type->name);
        $this->assertFalse($type->is_active);
        $this->assertSame(10, $entry->fresh()->points);

        $log = AuditLog::query()->where('action', AuditAction::ConductTypeUpdated->value)->sole();
        $this->assertEquals(['name' => 'Unauthorized absence', 'default_points' => 10, 'description' => null, 'is_active' => true], $log->old_values);
        $this->assertEquals(['name' => 'Absence without leave', 'default_points' => 8, 'description' => 'Away from the unit without permission', 'is_active' => false], $log->new_values);
    }

    public function test_types_are_validated(): void
    {
        $valid = ['name' => 'Valor', 'kind' => 'merit', 'default_points' => '5', 'description' => '', 'sort_order' => '1'];

        $this->actingAs($this->admin)->from('/conduct/types/create')
            ->post('/conduct/types', [...$valid, 'name' => 'Late for formation'])
            ->assertSessionHasErrors(['name' => 'Another merit or demerit type already uses this name.']);
        $this->actingAs($this->admin)->from('/conduct/types/create')
            ->post('/conduct/types', [...$valid, 'kind' => 'bonus', 'default_points' => '0', 'sort_order' => '1000', 'name' => ''])
            ->assertSessionHasErrors([
                'name' => 'Enter the name of the type.',
                'kind' => 'Choose whether this is a merit or a demerit.',
                'default_points' => 'Enter the usual points as a whole number from 1 to 100.',
                'sort_order' => 'Enter the position as a whole number from 0 to 999.',
            ]);
        $this->actingAs($this->admin)->from('/conduct/types/create')
            ->post('/conduct/types', [...$valid, 'default_points' => '101', 'is_active' => false])
            ->assertSessionHasErrors(['default_points', 'is_active']);
        $this->assertSame(6, ConductType::query()->count());

        // Keeping its own name is not a duplicate.
        $this->actingAs($this->admin)->put("/conduct/types/{$this->late->id}", [
            'name' => 'Late for formation', 'kind' => 'demerit', 'default_points' => '3', 'description' => '', 'sort_order' => '4', 'is_active' => true,
        ])->assertSessionHasNoErrors();
        $this->assertSame(3, $this->late->fresh()->default_points);

        $this->actingAs($this->admin)->from("/conduct/types/{$this->late->id}/edit")
            ->put("/conduct/types/{$this->late->id}", ['name' => 'Late for formation', 'kind' => 'demerit', 'default_points' => '3', 'sort_order' => '4'])
            ->assertSessionHasErrors('is_active');
    }

    public function test_instructors_cannot_configure_types(): void
    {
        foreach (['/conduct/types', '/conduct/types/create', "/conduct/types/{$this->late->id}/edit"] as $url) {
            $this->actingAs($this->alpha)->get($url)->assertForbidden();
        }
        $this->actingAs($this->alpha)->post('/conduct/types', ['name' => 'Valor', 'kind' => 'merit', 'default_points' => '5', 'sort_order' => '1'])->assertForbidden();
        $this->actingAs($this->alpha)->put("/conduct/types/{$this->late->id}", ['name' => 'Renamed', 'kind' => 'demerit', 'default_points' => '5', 'sort_order' => '1', 'is_active' => true])->assertForbidden();

        $this->assertSame('Late for formation', $this->late->fresh()->name);
        $this->assertSame(6, ConductType::query()->count());
    }

    public function test_the_super_administrator_may_record_and_configure(): void
    {
        $super = $this->userWithRole(SystemRole::SuperAdministrator);

        $this->record($this->other, actor: $super);
        $this->actingAs($super)->get('/conduct/types')->assertOk();
        $this->assertSame(1, ConductEntry::query()->count());
    }

    public function test_candidates_cannot_reach_merits_and_demerits(): void
    {
        $entry = $this->record($this->first);

        foreach ([$this->first->user] as $user) {
            foreach (['/conduct', "/conduct/candidates/{$this->first->id}", '/conduct/types', '/conduct/types/create', "/conduct/types/{$this->late->id}/edit"] as $url) {
                $this->actingAs($user)->get($url)->assertForbidden();
            }
            $this->actingAs($user)->post("/conduct/candidates/{$this->first->id}/entries", $this->entry())->assertForbidden();
            $this->actingAs($user)->post("/conduct-entries/{$entry->id}/void", ['reason' => 'Not allowed'])->assertForbidden();
            $this->actingAs($user)->post('/conduct/types', ['name' => 'Valor', 'kind' => 'merit', 'default_points' => '5', 'sort_order' => '1'])->assertForbidden();
        }

        $this->assertSame(1, ConductEntry::query()->count());
        $this->assertNull($entry->fresh()->voided_at);
    }

    public function test_the_list_filters_by_search_and_class(): void
    {
        $this->record($this->other, ['conduct_type_id' => (string) $this->late->id, 'points' => '2']);

        $this->actingAs($this->admin)->get('/conduct?search=charlie')
            ->assertInertia(fn (Assert $page) => $page->has('candidates.data', 1)
                ->where('candidates.data.0.candidateNumber', 'C-003')
                ->where('candidates.data.0.className', 'Class B')
                ->where('candidates.data.0.totals', ['merits' => 0, 'demerits' => 2, 'net' => -2]));
        $this->actingAs($this->admin)->get("/conduct?class={$this->classA->id}")
            ->assertInertia(fn (Assert $page) => $page->has('candidates.data', 2)->where('filters.class', (string) $this->classA->id));
        $this->actingAs($this->admin)->get('/conduct?class=abc&search[]=x')
            ->assertInertia(fn (Assert $page) => $page->has('candidates.data', 3)->where('filters.class', '')->where('filters.search', ''));
    }

    public function test_the_ledger_totals_every_requested_candidate_and_lists_history(): void
    {
        $this->record($this->first, ['points' => '5', 'occurred_on' => '2026-09-01']);
        $voided = $this->record($this->first, ['conduct_type_id' => (string) $this->late->id, 'points' => '7', 'occurred_on' => '2026-09-05']);
        $this->record($this->first, ['conduct_type_id' => (string) $this->late->id, 'points' => '2', 'occurred_on' => '2026-09-03']);
        $this->record($this->other, ['points' => '1']);
        $this->void($voided);

        $ledger = $this->app->make(ConductLedger::class);

        $this->assertSame([
            $this->first->id => ['merits' => 5, 'demerits' => 2, 'net' => 3],
            $this->second->id => ['merits' => 0, 'demerits' => 0, 'net' => 0],
            $this->other->id => ['merits' => 1, 'demerits' => 0, 'net' => 1],
        ], $ledger->totalsFor([$this->first->id, $this->second->id, (string) $this->other->id]));
        $this->assertSame([], $ledger->totalsFor([]));

        $history = $ledger->history($this->first);
        $this->assertSame(['2026-09-05', '2026-09-03', '2026-09-01'], array_column($history, 'occurredOn'));
        $this->assertNotNull($history[0]['voided']);

        $this->assertCount(1, $ledger->history($this->first, 1));
        $standing = $ledger->history($this->first, null, includeVoided: false);
        $this->assertSame(['2026-09-03', '2026-09-01'], array_column($standing, 'occurredOn'));
        $this->assertSame([], $ledger->history($this->second));
    }

    public function test_the_database_rejects_invalid_entries_and_types(): void
    {
        $insertEntry = fn (array $values) => DB::table('conduct_entries')->insert([
            'candidate_id' => $this->first->id, 'conduct_type_id' => $this->late->id, 'kind' => 'demerit', 'points' => 2,
            'occurred_on' => '2026-09-01', 'reason' => 'Check', 'recorded_by' => $this->admin->id,
            ...$values,
        ]);
        $insertType = fn (array $values) => DB::table('conduct_types')->insert(['name' => 'Check '.uniqid(), 'kind' => 'merit', 'default_points' => 1, ...$values]);

        $cases = [
            fn () => $insertEntry(['points' => 0]),
            fn () => $insertEntry(['points' => 101]),
            fn () => $insertEntry(['kind' => 'bonus']),
            fn () => $insertEntry(['voided_at' => now()]),
            fn () => $insertEntry(['voided_at' => now(), 'voided_by' => $this->admin->id]),
            fn () => $insertType(['default_points' => 0]),
            fn () => $insertType(['default_points' => 101]),
            fn () => $insertType(['kind' => 'bonus']),
        ];

        foreach ($cases as $index => $insert) {
            try {
                $insert();
                $this->fail("The database accepted invalid row #{$index}.");
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(0, ConductEntry::query()->count());
    }
}
