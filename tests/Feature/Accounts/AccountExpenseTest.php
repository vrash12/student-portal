<?php

namespace Tests\Feature\Accounts;

use App\Enums\AuditAction;
use App\Enums\CandidateStatus;
use App\Enums\SystemRole;
use App\Models\AcademicPeriod;
use App\Models\AccountCategory;
use App\Models\AccountEntry;
use App\Models\AccountExpense;
use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\ClassBatch;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Expenses assigned to candidates (owner request, 2026-10-01): the administrator
 * defines an expense once and charges it to a whole class or to chosen
 * candidates. Each charge is an entry on the candidate's statement, at most
 * once per candidate among entries that count.
 */
class AccountExpenseTest extends TestCase
{
    private User $admin;

    private ClassBatch $classA;

    private ClassBatch $classB;

    private Candidate $first;

    private Candidate $second;

    private Candidate $withdrawn;

    private Candidate $inB;

    private AccountCategory $uniforms;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->userWithRole(SystemRole::SuperAdministrator);
        $period = AcademicPeriod::factory()->active()->create();
        $this->classA = ClassBatch::factory()->for($period)->create(['name' => 'Class A']);
        $this->classB = ClassBatch::factory()->for($period)->create(['name' => 'Class B']);
        $this->first = Candidate::factory()->create(['class_batch_id' => $this->classA->id, 'candidate_number' => 'C-001', 'last_name' => 'Alpha']);
        $this->second = Candidate::factory()->create(['class_batch_id' => $this->classA->id, 'candidate_number' => 'C-002', 'last_name' => 'Bravo']);
        $this->withdrawn = Candidate::factory()->create(['class_batch_id' => $this->classA->id, 'candidate_number' => 'C-003', 'status' => CandidateStatus::Withdrawn]);
        $this->inB = Candidate::factory()->create(['class_batch_id' => $this->classB->id, 'candidate_number' => 'C-101', 'last_name' => 'Charlie']);

        $this->uniforms = AccountCategory::query()->where('name', 'Uniforms')->sole();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createExpense(array $overrides = []): AccountExpense
    {
        $this->actingAs($this->admin)
            ->post('/account-expenses', [
                'name' => 'Uniform set',
                'account_category_id' => (string) $this->uniforms->id,
                'amount' => '3,500.00',
                'description' => '',
                ...$overrides,
            ])
            ->assertSessionHasNoErrors();

        return AccountExpense::query()->latest('id')->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assign(AccountExpense $expense, array $data): TestResponse
    {
        return $this->actingAs($this->admin)
            ->from("/account-expenses/{$expense->id}")
            ->post("/account-expenses/{$expense->id}/assignments", ['posted_on' => '2026-09-01', ...$data]);
    }

    public function test_an_expense_is_created_validated_and_audited(): void
    {
        $this->actingAs($this->admin)->get('/account-expenses/create')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('staff/accounts/expenses/create')
                // Every active category: categories are always for charges.
                ->where('categories', fn ($categories) => collect($categories)->pluck('name')->contains('Meal Allowance')));

        $this->actingAs($this->admin)->post('/account-expenses', ['name' => '', 'account_category_id' => '999999', 'amount' => '0'])
            ->assertSessionHasErrors(['name', 'account_category_id', 'amount']);

        $expense = $this->createExpense(['due_on' => '2026-09-15']);
        $this->assertSame('3500.00', $expense->amount);
        // There is no due date any more; a sent one is ignored.
        $this->assertArrayNotHasKey('due_on', $expense->getAttributes());
        $this->assertNull($expense->description);
        $this->assertSame($this->admin->id, $expense->created_by);
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::AccountExpenseCreated->value)->count());

        $this->actingAs($this->admin)->post('/account-expenses', ['name' => 'Uniform set', 'account_category_id' => (string) $this->uniforms->id, 'amount' => '10'])
            ->assertSessionHasErrors(['name' => 'Another expense already uses this name.']);
    }

    public function test_assigning_to_a_class_charges_its_candidates_once_and_leaves_out_withdrawn_ones(): void
    {
        $expense = $this->createExpense();

        $this->assign($expense, ['mode' => 'class', 'class_batch_id' => (string) $this->classA->id])
            ->assertRedirect("/account-expenses/{$expense->id}")->assertSessionHasNoErrors();

        $charges = AccountEntry::query()->where('account_expense_id', $expense->id)->orderBy('candidate_id')->get();
        $this->assertSame([$this->first->id, $this->second->id], $charges->pluck('candidate_id')->all());
        $this->assertTrue($charges->every(fn (AccountEntry $entry): bool => $entry->amount === '3500.00'
            && $entry->entry_type->value === 'charge'
            && $entry->account_category_id === $this->uniforms->id
            && $entry->posted_on->toDateString() === '2026-09-01'
            && $entry->recorded_by === $this->admin->id));

        $log = AuditLog::query()->where('action', AuditAction::AccountExpenseAssigned->value)->sole();
        $this->assertSame('account_expense', $log->auditable_type);
        $this->assertSame(['C-001', 'C-002'], $log->new_values['candidates']);
        $this->assertSame(2, $log->new_values['candidate_count']);

        // Again: nobody is charged twice, and nothing new is audited.
        $this->assign($expense, ['mode' => 'class', 'class_batch_id' => (string) $this->classA->id])->assertSessionHasNoErrors();
        $this->assertSame(2, AccountEntry::query()->where('account_expense_id', $expense->id)->count());
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::AccountExpenseAssigned->value)->count());

        $this->actingAs($this->admin)->get("/account-expenses/{$expense->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('expense.assignedCount', 2)
                ->where('expense.assignedTotal', '7000.00')
                ->where('charges.data.0.candidate.candidateNumber', 'C-001')
                ->where('charges.data.0.amount', '3500.00'));
    }

    public function test_assigning_to_chosen_candidates_skips_those_already_charged(): void
    {
        $expense = $this->createExpense();
        $this->assign($expense, ['mode' => 'candidates', 'candidate_ids' => [$this->first->id]])->assertSessionHasNoErrors();

        // Withdrawn candidates may be chosen one by one; the one already charged is skipped.
        $this->assign($expense, ['mode' => 'candidates', 'candidate_ids' => [$this->first->id, $this->withdrawn->id, $this->inB->id]])
            ->assertSessionHasNoErrors();

        $this->assertEqualsCanonicalizing(
            [$this->first->id, $this->withdrawn->id, $this->inB->id],
            AccountEntry::query()->where('account_expense_id', $expense->id)->pluck('candidate_id')->all(),
        );
        $this->assertSame(['C-003', 'C-101'], AuditLog::query()->where('action', AuditAction::AccountExpenseAssigned->value)->latest('id')->first()->new_values['candidates']);
    }

    public function test_assignments_are_validated(): void
    {
        $expense = $this->createExpense();

        $this->assign($expense, ['mode' => 'everyone'])->assertSessionHasErrors('mode');
        $this->assign($expense, ['mode' => 'class'])->assertSessionHasErrors(['class_batch_id' => 'Choose the class.']);
        $this->assign($expense, ['mode' => 'class', 'class_batch_id' => '999999'])->assertSessionHasErrors('class_batch_id');
        $this->assign($expense, ['mode' => 'candidates', 'candidate_ids' => []])->assertSessionHasErrors('candidate_ids');
        $this->assign($expense, ['mode' => 'candidates', 'candidate_ids' => [999999]])->assertSessionHasErrors('candidate_ids.0');
        $this->assign($expense, ['mode' => 'candidates', 'candidate_ids' => [$this->first->id], 'posted_on' => '01/09/2026'])->assertSessionHasErrors('posted_on');

        $empty = ClassBatch::factory()->create(['academic_period_id' => $this->classA->academic_period_id, 'name' => 'Class C']);
        $this->assign($expense, ['mode' => 'class', 'class_batch_id' => (string) $empty->id])->assertSessionHasErrors('class_batch_id');

        $this->assertSame(0, AccountEntry::query()->count());
    }

    public function test_a_voided_charge_can_be_assigned_again_and_the_database_refuses_a_second_standing_charge(): void
    {
        $expense = $this->createExpense();
        $this->assign($expense, ['mode' => 'candidates', 'candidate_ids' => [$this->first->id]]);
        $charge = AccountEntry::query()->where('account_expense_id', $expense->id)->sole();

        try {
            DB::table('account_entries')->insert([
                'candidate_id' => $this->first->id, 'account_category_id' => $this->uniforms->id, 'account_expense_id' => $expense->id,
                'entry_type' => 'charge', 'amount' => '3500.00', 'posted_on' => '2026-09-02', 'description' => 'Uniform set', 'recorded_by' => $this->admin->id,
            ]);
            $this->fail('A second standing charge of the same expense was accepted.');
        } catch (QueryException) {
            // Expected: unique (candidate, standing expense).
        }

        $this->actingAs($this->admin)->post("/account-entries/{$charge->id}/void", ['reason' => 'Charged by mistake'])->assertSessionHasNoErrors();
        $this->assign($expense, ['mode' => 'candidates', 'candidate_ids' => [$this->first->id]])->assertSessionHasNoErrors();

        $this->assertSame(2, AccountEntry::query()->where('account_expense_id', $expense->id)->count());
        $this->assertSame(1, AccountEntry::query()->where('account_expense_id', $expense->id)->standing()->count());
    }

    public function test_once_assigned_the_amount_and_category_are_fixed_but_the_name_follows(): void
    {
        $expense = $this->createExpense();
        $update = fn (array $overrides) => $this->actingAs($this->admin)->put("/account-expenses/{$expense->id}", [
            'name' => 'Uniform set', 'account_category_id' => (string) $this->uniforms->id, 'amount' => '3500', 'description' => '', 'is_active' => true,
            ...$overrides,
        ]);

        // Not assigned yet: the amount can still change.
        $update(['amount' => '3600'])->assertSessionHasNoErrors();
        $this->assertSame('3600.00', $expense->refresh()->amount);

        $this->assign($expense, ['mode' => 'candidates', 'candidate_ids' => [$this->first->id]]);
        $update(['amount' => '4000'])->assertSessionHasErrors('amount');
        $billing = AccountCategory::query()->where('name', 'Billing')->sole();
        $update(['amount' => '3600', 'account_category_id' => (string) $billing->id])->assertSessionHasErrors('account_category_id');
        $this->assertSame('3600.00', $expense->refresh()->amount);

        $update(['amount' => '3,600.00', 'name' => 'Uniform set (two pieces)'])->assertSessionHasNoErrors();
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::AccountExpenseUpdated->value)->where('new_values->name', 'Uniform set (two pieces)')->count());

        $charge = AccountEntry::query()->where('account_expense_id', $expense->id)->with('expense')->sole();
        // The charge shows the expense's current name; its amount is unchanged.
        $this->assertSame('Uniform set (two pieces)', $charge->label());
        $this->assertSame('3600.00', $charge->amount);
    }

    public function test_an_inactive_expense_cannot_be_assigned(): void
    {
        $expense = $this->createExpense();
        $expense->forceFill(['is_active' => false])->save();

        $this->assign($expense, ['mode' => 'class', 'class_batch_id' => (string) $this->classA->id])->assertSessionHasErrors('expense');
        $this->assertSame(0, AccountEntry::query()->count());
    }

    public function test_the_expense_page_lists_charges_and_candidates_to_choose(): void
    {
        $expense = $this->createExpense();
        $this->assign($expense, ['mode' => 'candidates', 'candidate_ids' => [$this->first->id]]);

        $this->actingAs($this->admin)->get("/account-expenses/{$expense->id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('staff/accounts/expenses/show')
                ->where('expense.assignedCount', 1)
                ->where('expense.assignedTotal', '3500.00')
                ->has('charges.data', 1)
                ->where('charges.data.0.candidate.candidateNumber', 'C-001')
                // Nothing is listed until a class or search narrows the picker.
                ->where('picker.candidates', []));

        $this->actingAs($this->admin)->get("/account-expenses/{$expense->id}?class={$this->classA->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->has('picker.candidates', 3)
                ->where('picker.candidates.0.candidateNumber', 'C-001')
                ->where('picker.candidates.0.charged', true)
                ->where('picker.candidates.1.charged', false));

        $this->actingAs($this->admin)->get("/account-expenses/{$expense->id}?search=charlie")
            ->assertInertia(fn (Assert $page) => $page->has('picker.candidates', 1)->where('picker.candidates.0.candidateNumber', 'C-101'));

        $this->actingAs($this->admin)->get('/account-expenses')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('staff/accounts/expenses/index')
                ->where('expenses.0.assignedCount', 1)
                ->where('expenses.0.assignedTotal', '3500.00'));
    }

    public function test_only_staff_who_manage_accounts_can_use_expenses(): void
    {
        $expense = $this->createExpense();
        $viewOnly = $this->userWithRole(SystemRole::AcademicAdministrator);

        foreach ([$viewOnly, $this->userWithRole(SystemRole::Instructor), $this->first->user] as $user) {
            foreach (['/account-expenses', '/account-expenses/create', "/account-expenses/{$expense->id}", "/account-expenses/{$expense->id}/edit"] as $url) {
                $this->actingAs($user)->get($url)->assertForbidden();
            }
            $this->actingAs($user)->post("/account-expenses/{$expense->id}/assignments", ['mode' => 'class', 'class_batch_id' => $this->classA->id, 'posted_on' => '2026-09-01'])->assertForbidden();
        }

        $this->assertSame(0, AccountEntry::query()->count());
        $this->actingAs($this->userWithRole(SystemRole::SuperAdministrator))->get("/account-expenses/{$expense->id}")->assertOk();
    }
}
