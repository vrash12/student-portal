<?php

namespace Tests\Feature\Accounts;

use App\Enums\AuditAction;
use App\Enums\Permission;
use App\Enums\SystemRole;
use App\Models\AcademicPeriod;
use App\Models\AccountCategory;
use App\Models\AccountEntry;
use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\ClassBatch;
use App\Models\User;
use Database\Seeders\DemoAccountStatementsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

/**
 * Statement of Account (owner request, 2026-10-01): an internal ledger of
 * charges and credits per candidate with a running balance and a printable
 * statement. Amounts only; no payments. Staff only.
 */
class StatementOfAccountTest extends TestCase
{
    private User $finance;

    private Candidate $first;

    private Candidate $second;

    private AccountCategory $uniforms;

    private AccountCategory $allowance;

    protected function setUp(): void
    {
        parent::setUp();

        $this->finance = $this->userWithRole(SystemRole::FinanceOfficer);
        $class = ClassBatch::factory()->for(AcademicPeriod::factory()->active())->create(['name' => 'Class A']);
        $this->first = Candidate::factory()->create(['class_batch_id' => $class->id, 'candidate_number' => 'C-001', 'last_name' => 'Alpha']);
        $this->second = Candidate::factory()->create(['class_batch_id' => $class->id, 'candidate_number' => 'C-002', 'last_name' => 'Bravo']);

        // Seeded by the migration.
        $this->uniforms = AccountCategory::query()->where('name', 'Uniforms')->sole();
        $this->allowance = AccountCategory::query()->where('name', 'Meal Allowance')->sole();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function entry(array $overrides = []): array
    {
        return [
            'account_category_id' => (string) $this->uniforms->id,
            'entry_type' => 'charge',
            'amount' => '1,250.50',
            'posted_on' => '2026-09-01',
            'description' => 'Uniform set',
            'reference' => '',
            ...$overrides,
        ];
    }

    private function record(Candidate $candidate, array $overrides = [], ?User $actor = null): AccountEntry
    {
        $this->actingAs($actor ?? $this->finance)
            ->from("/accounts/{$candidate->id}")
            ->post("/accounts/{$candidate->id}/entries", $this->entry($overrides))
            ->assertRedirect("/accounts/{$candidate->id}")
            ->assertSessionHasNoErrors();

        return AccountEntry::query()->latest('id')->firstOrFail();
    }

    public function test_the_migration_seeds_the_default_categories(): void
    {
        $this->assertSame(
            ['Billing', 'Chargeable Items', 'Uniforms', 'Meals', 'Military Fitness', 'Meal Allowance', 'Allowance', 'Bank Deposit', 'Payment Received'],
            AccountCategory::query()->ordered()->pluck('name')->all(),
        );
        $this->assertSame('credit', AccountCategory::query()->where('name', 'Payment Received')->value('entry_type')->value);
    }

    public function test_charges_and_credits_produce_the_balance_and_running_balance(): void
    {
        $charge = $this->record($this->first);
        $this->record($this->first, ['amount' => '300', 'posted_on' => '2026-09-03', 'description' => 'Name tags', 'reference' => 'INV-7']);
        $this->record($this->first, ['account_category_id' => (string) $this->allowance->id, 'entry_type' => 'credit', 'amount' => '2000.00', 'posted_on' => '2026-09-02', 'description' => 'September meal allowance']);

        $this->assertSame('1250.50', $charge->amount);
        $this->assertSame($this->finance->id, $charge->recorded_by);
        $this->assertNull($charge->reference);
        $this->assertSame(3, AuditLog::query()->where('action', AuditAction::AccountEntryRecorded->value)->count());

        $this->actingAs($this->finance)->get("/accounts/{$this->first->id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('staff/accounts/show')
                ->where('statement.opening', '0.00')
                ->where('statement.charges', '1550.50')
                ->where('statement.credits', '2000.00')
                ->where('statement.closing', '-449.50')
                ->where('statement.status.label', 'Credit balance')
                // Ordered by date: charge, credit (Sep 2), charge (Sep 3).
                ->where('statement.entries.0.balance', '1250.50')
                ->where('statement.entries.1.description', 'September meal allowance')
                ->where('statement.entries.1.balance', '-749.50')
                ->where('statement.entries.2.balance', '-449.50')
                ->where('statement.entries.2.reference', 'INV-7')
                ->where('can.manage', true)
                ->where('can.viewCandidate', false));
    }

    public function test_a_period_brings_the_earlier_balance_forward(): void
    {
        $this->record($this->first, ['posted_on' => '2026-08-15', 'amount' => '1000']);
        $this->record($this->first, ['posted_on' => '2026-09-10', 'amount' => '200']);
        $this->record($this->first, ['posted_on' => '2026-10-20', 'amount' => '50']);

        $this->actingAs($this->finance)->get("/accounts/{$this->first->id}?from=2026-09-01&to=2026-09-30")->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('statement.from', '2026-09-01')
                ->where('statement.opening', '1000.00')
                ->where('statement.charges', '200.00')
                ->where('statement.closing', '1200.00')
                ->has('statement.entries', 1)
                ->where('statement.entries.0.balance', '1200.00'));

        $this->actingAs($this->finance)->get("/accounts/{$this->first->id}?to=2026-09-30")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('statement.closing', '1200.00')->has('statement.entries', 2));

        $this->actingAs($this->finance)->from("/accounts/{$this->first->id}")
            ->get("/accounts/{$this->first->id}?from=2026-09-30&to=2026-09-01")
            ->assertSessionHasErrors(['to' => 'The end date must be on or after the start date.']);
    }

    public function test_a_voided_entry_no_longer_counts_is_audited_and_cannot_be_voided_twice(): void
    {
        $this->record($this->first, ['amount' => '500']);
        $mistake = $this->record($this->first, ['amount' => '500', 'description' => 'Uniform set (duplicate)']);

        $this->actingAs($this->finance)->from("/accounts/{$this->first->id}")
            ->post("/account-entries/{$mistake->id}/void", ['reason' => 'no'])
            ->assertSessionHasErrors(['reason' => 'Give the reason for voiding this entry (at least 5 characters).']);
        $this->assertNull($mistake->fresh()->voided_at);

        $this->actingAs($this->finance)->from("/accounts/{$this->first->id}")
            ->post("/account-entries/{$mistake->id}/void", ['reason' => '  Recorded twice  '])
            ->assertRedirect("/accounts/{$this->first->id}")
            ->assertSessionHasNoErrors();

        $voided = $mistake->fresh();
        $this->assertNotNull($voided->voided_at);
        $this->assertSame($this->finance->id, $voided->voided_by);
        $this->assertSame('Recorded twice', $voided->void_reason);
        // Never deleted: the entry and its amount remain.
        $this->assertSame('500.00', $voided->amount);

        $log = AuditLog::query()->where('action', AuditAction::AccountEntryVoided->value)->sole();
        $this->assertSame('account_entry', $log->auditable_type);
        $this->assertSame($mistake->id, $log->auditable_id);
        $this->assertSame('Recorded twice', $log->reason);

        $this->actingAs($this->finance)->get("/accounts/{$this->first->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('statement.closing', '500.00')
                ->where('statement.charges', '500.00')
                ->where('statement.entries.1.balance', null)
                ->where('statement.entries.1.voided.reason', 'Recorded twice')
                ->where('statement.entries.1.voided.by', $this->finance->name));

        $this->actingAs($this->finance)->from("/accounts/{$this->first->id}")
            ->post("/account-entries/{$mistake->id}/void", ['reason' => 'Voiding again'])
            ->assertSessionHasErrors(['reason' => 'This entry has already been voided.']);
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::AccountEntryVoided->value)->count());
    }

    public function test_amounts_are_validated(): void
    {
        $cases = [
            '' => 'Enter the amount.',
            '0' => 'The amount must be more than zero.',
            '0.00' => 'The amount must be more than zero.',
            '-50' => 'Enter an amount such as 1250 or 1250.50 (up to two decimal places).',
            '12.345' => 'Enter an amount such as 1250 or 1250.50 (up to two decimal places).',
            'ten' => 'Enter an amount such as 1250 or 1250.50 (up to two decimal places).',
            '12345678901' => 'Enter an amount such as 1250 or 1250.50 (up to two decimal places).',
        ];

        foreach ($cases as $amount => $message) {
            $this->actingAs($this->finance)->from("/accounts/{$this->first->id}")
                ->post("/accounts/{$this->first->id}/entries", $this->entry(['amount' => (string) $amount]))
                ->assertSessionHasErrors(['amount' => $message]);
        }

        $this->actingAs($this->finance)->from("/accounts/{$this->first->id}")
            ->post("/accounts/{$this->first->id}/entries", $this->entry(['description' => '', 'posted_on' => 'yesterday', 'entry_type' => 'refund']))
            ->assertSessionHasErrors(['description', 'posted_on', 'entry_type']);

        $this->assertSame(0, AccountEntry::query()->count());

        // The largest amount the column holds, entered with separators.
        $this->assertSame('9999999999.99', $this->record($this->first, ['amount' => '9,999,999,999.99'])->amount);
    }

    public function test_an_inactive_category_cannot_be_used(): void
    {
        $this->uniforms->forceFill(['is_active' => false])->save();

        $this->actingAs($this->finance)->from("/accounts/{$this->first->id}")
            ->post("/accounts/{$this->first->id}/entries", $this->entry())
            ->assertSessionHasErrors(['account_category_id' => 'Choose an active category.']);

        $this->actingAs($this->finance)->get("/accounts/{$this->first->id}")
            ->assertInertia(fn (Assert $page) => $page->where('categories', fn ($categories): bool => ! collect($categories)->contains('name', 'Uniforms')));
        $this->assertSame(0, AccountEntry::query()->count());
    }

    public function test_the_database_rejects_invalid_entries(): void
    {
        $insert = fn (array $values) => DB::table('account_entries')->insert([
            'candidate_id' => $this->first->id, 'account_category_id' => $this->uniforms->id, 'entry_type' => 'charge',
            'amount' => '10.00', 'posted_on' => '2026-09-01', 'description' => 'Check', 'recorded_by' => $this->finance->id,
            ...$values,
        ]);

        foreach ([['amount' => '0.00'], ['entry_type' => 'refund'], ['voided_at' => now()]] as $values) {
            try {
                $insert($values);
                $this->fail('The database accepted '.json_encode($values));
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_the_finance_officer_sees_only_statements_of_account(): void
    {
        $this->actingAs($this->finance)->get('/dashboard')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('statementsOverview')->where('administratorOverview', null)->where('teaching', null));
        $this->actingAs($this->finance)->get('/accounts')->assertOk();
        $this->actingAs($this->finance)->get('/account-categories')->assertOk();

        foreach (["/candidates/{$this->first->id}", '/candidates', '/monitoring', '/reports', '/fitness', '/users', '/audit-history'] as $url) {
            $this->actingAs($this->finance)->get($url)->assertForbidden();
        }
    }

    public function test_the_academic_administrator_may_only_view(): void
    {
        $entry = $this->record($this->first);
        $admin = $this->userWithRole(SystemRole::AcademicAdministrator);

        $this->actingAs($admin)->get('/accounts')->assertOk()->assertInertia(fn (Assert $page) => $page->where('can.manage', false));
        $this->actingAs($admin)->get("/accounts/{$this->first->id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('can.manage', false)->where('can.viewCandidate', true));
        $this->actingAs($admin)->get("/accounts/{$this->first->id}/statement")->assertOk();

        $this->actingAs($admin)->post("/accounts/{$this->first->id}/entries", $this->entry())->assertForbidden();
        $this->actingAs($admin)->post("/account-entries/{$entry->id}/void", ['reason' => 'Not allowed'])->assertForbidden();
        $this->actingAs($admin)->get('/account-categories')->assertForbidden();
        $this->actingAs($admin)->post('/account-categories', ['name' => 'Laundry', 'entry_type' => 'charge', 'sort_order' => '10'])->assertForbidden();

        $this->assertSame(1, AccountEntry::query()->count());
        $this->assertNull($entry->fresh()->voided_at);
    }

    public function test_the_super_administrator_may_record_entries(): void
    {
        $this->record($this->first, actor: $this->userWithRole(SystemRole::SuperAdministrator));

        $this->assertSame(1, AccountEntry::query()->count());
    }

    public function test_instructors_and_candidates_cannot_reach_statements_of_account(): void
    {
        $entry = $this->record($this->first);
        $instructor = $this->userWithRole(SystemRole::Instructor);

        foreach ([$instructor, $this->first->user] as $user) {
            foreach (['/accounts', "/accounts/{$this->first->id}", "/accounts/{$this->first->id}/statement", '/account-categories'] as $url) {
                $this->actingAs($user)->get($url)->assertForbidden();
            }
            $this->actingAs($user)->post("/accounts/{$this->first->id}/entries", $this->entry())->assertForbidden();
            $this->actingAs($user)->post("/account-entries/{$entry->id}/void", ['reason' => 'Not allowed'])->assertForbidden();
        }

        $this->actingAs($instructor)->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('statementsOverview', null));
        $this->assertSame(1, AccountEntry::query()->count());
    }

    public function test_the_list_shows_balances_and_filters_by_balance(): void
    {
        $third = Candidate::factory()->create(['class_batch_id' => $this->first->class_batch_id, 'candidate_number' => 'C-003', 'last_name' => 'Charlie']);
        $this->record($this->first, ['amount' => '800']);
        $this->record($this->second, ['account_category_id' => (string) $this->allowance->id, 'entry_type' => 'credit', 'amount' => '150.25']);
        $voided = $this->record($third, ['amount' => '99']);
        $this->actingAs($this->finance)->post("/account-entries/{$voided->id}/void", ['reason' => 'Wrong candidate']);

        $this->actingAs($this->finance)->get('/accounts')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('staff/accounts/index')
                ->has('candidates.data', 3)
                ->where('candidates.data.0.candidateNumber', 'C-001')
                ->where('candidates.data.0.charges', '800.00')
                ->where('candidates.data.0.balance', '800.00')
                ->where('candidates.data.0.balanceStatus.label', 'Balance due')
                ->where('candidates.data.1.credits', '150.25')
                ->where('candidates.data.1.balance', '-150.25')
                ->where('candidates.data.1.balanceStatus.label', 'Credit balance')
                ->where('candidates.data.2.balance', '0.00')
                ->where('candidates.data.2.balanceStatus.label', 'Settled')
                ->where('overview.totalDue', '800.00')
                ->where('overview.candidatesDue', 1)
                ->where('overview.totalCredit', '150.25')
                ->where('overview.candidatesInCredit', 1)
                ->where('can.manage', true));

        foreach (['due' => 'C-001', 'credit' => 'C-002', 'settled' => 'C-003'] as $filter => $number) {
            $this->actingAs($this->finance)->get("/accounts?balance={$filter}")
                ->assertInertia(fn (Assert $page) => $page->has('candidates.data', 1)->where('candidates.data.0.candidateNumber', $number)->where('filters.balance', $filter));
        }

        $this->actingAs($this->finance)->get('/accounts?search=bravo')
            ->assertInertia(fn (Assert $page) => $page->has('candidates.data', 1)->where('candidates.data.0.candidateNumber', 'C-002'));
        $this->actingAs($this->finance)->get('/accounts?balance=everything')
            ->assertInertia(fn (Assert $page) => $page->has('candidates.data', 3)->where('filters.balance', ''));
    }

    public function test_the_dashboard_summarizes_balances(): void
    {
        $this->record($this->first, ['amount' => '800']);
        $this->record($this->second, ['amount' => '200.50']);

        $this->actingAs($this->finance)->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page
                ->where('statementsOverview.totalDue', '1000.50')
                ->where('statementsOverview.candidatesDue', 2)
                ->has('statementsOverview.recentEntries', 2)
                ->where('statementsOverview.recentEntries.0.candidate.candidateNumber', 'C-002'));
    }

    public function test_the_statement_pdf_downloads_and_is_audited(): void
    {
        $this->record($this->first, ['amount' => '800']);
        $voided = $this->record($this->first, ['amount' => '75', 'description' => 'Duplicate']);
        $this->actingAs($this->finance)->post("/account-entries/{$voided->id}/void", ['reason' => 'Recorded twice']);

        $response = $this->actingAs($this->finance)->get("/accounts/{$this->first->id}/statement?from=2026-08-01&to=2026-09-30")->assertOk();

        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment; filename="statement-of-account-c-001.pdf"', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF', (string) $response->getContent());

        $log = AuditLog::query()->where('action', AuditAction::AccountStatementDownloaded->value)->sole();
        $this->assertSame('candidate', $log->auditable_type);
        $this->assertSame($this->first->id, $log->auditable_id);
        $this->assertSame(['from' => '2026-08-01', 'to' => '2026-09-30'], $log->new_values);
    }

    public function test_the_printed_statement_shows_the_balance_and_leaves_voided_entries_out(): void
    {
        config(['institution.currency' => 'PHP']);
        $this->record($this->first, ['amount' => '1,800', 'description' => 'Uniform set', 'posted_on' => '2026-08-20']);
        $this->record($this->first, ['account_category_id' => (string) $this->allowance->id, 'entry_type' => 'credit', 'amount' => '300', 'description' => 'Meal allowance', 'posted_on' => '2026-09-05']);
        $voided = $this->record($this->first, ['amount' => '75', 'description' => 'Duplicate line', 'posted_on' => '2026-09-06']);
        $this->actingAs($this->finance)->post("/account-entries/{$voided->id}/void", ['reason' => 'Recorded twice']);

        // The PDF text is compressed, so check the HTML the service renders it from.
        $data = null;
        View::creator('pdf.statement-of-account', function ($view) use (&$data): void {
            $data = $view->getData();
        });
        $this->actingAs($this->finance)->get("/accounts/{$this->first->id}/statement?from=2026-09-01")->assertOk();
        $html = view('pdf.statement-of-account', $data)->render();

        $this->assertStringContainsString('Balance brought forward', $html);
        $this->assertStringContainsString('PHP 1,800.00', $html);
        $this->assertStringContainsString('Meal allowance', $html);
        $this->assertStringNotContainsString('Uniform set', $html);
        $this->assertStringNotContainsString('Duplicate line', $html);
        $this->assertStringContainsString('1 voided entry is not included.', $html);
        $this->assertStringContainsString('Balance due', $html);
        $this->assertStringContainsString('PHP 1,500.00', $html);
    }

    public function test_the_finance_officer_role_holds_only_account_permissions(): void
    {
        $this->assertSame(
            [Permission::AccessStaffArea, Permission::ViewAccounts, Permission::ManageAccounts],
            SystemRole::FinanceOfficer->defaultPermissions(),
        );
        $this->assertGreaterThan(SystemRole::Instructor->rank(), SystemRole::FinanceOfficer->rank());
        $this->assertLessThan(SystemRole::AcademicAdministrator->rank(), SystemRole::FinanceOfficer->rank());
        $this->assertContains(Permission::ViewAccounts, SystemRole::AcademicAdministrator->defaultPermissions());
        $this->assertNotContains(Permission::ManageAccounts, SystemRole::AcademicAdministrator->defaultPermissions());
        $this->assertNotContains(Permission::ViewAccounts, SystemRole::Instructor->defaultPermissions());
        $this->assertSame([Permission::AccessExamPortal], SystemRole::Candidate->defaultPermissions());
    }

    public function test_the_demo_seeder_adds_synthetic_statements_once(): void
    {
        $this->seed(DemoAccountStatementsSeeder::class);

        $finance = User::query()->where('username', 'finance1')->sole();
        $this->assertSame(SystemRole::FinanceOfficer->value, $finance->role->code);
        $this->assertSame(13, AccountEntry::query()->count());
        $this->assertSame(1, AccountEntry::query()->whereNotNull('voided_at')->count());
        $this->assertSame(13, AuditLog::query()->where('action', AuditAction::AccountEntryRecorded->value)->count());

        $this->actingAs($finance)->get('/accounts')
            ->assertInertia(fn (Assert $page) => $page
                ->where('candidates.data.0.balanceStatus.label', 'Settled')
                ->where('candidates.data.1.balance', '13000.00'));

        $this->seed(DemoAccountStatementsSeeder::class);
        $this->assertSame(13, AccountEntry::query()->count());
        $this->assertSame(1, User::query()->where('username', 'finance1')->count());

        $this->app->detectEnvironment(fn (): string => 'production');
        $this->expectException(RuntimeException::class);
        $this->app->call([$this->app->make(DemoAccountStatementsSeeder::class), 'run']);
    }

    public function test_categories_can_be_created_updated_and_deactivated(): void
    {
        $this->actingAs($this->finance)->get('/account-categories')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('staff/accounts/categories/index')->has('categories', 9));
        $this->actingAs($this->finance)->get('/account-categories/create')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('staff/accounts/categories/create')->where('nextSortOrder', 10));

        $this->actingAs($this->finance)->post('/account-categories', ['name' => '  Laundry  ', 'entry_type' => 'charge', 'description' => '', 'sort_order' => '10'])
            ->assertRedirect('/account-categories')->assertSessionHasNoErrors();
        $laundry = AccountCategory::query()->where('name', 'Laundry')->sole();
        $this->assertTrue($laundry->is_active);
        $this->assertNull($laundry->description);
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::AccountCategoryCreated->value)->count());

        $this->actingAs($this->finance)->post('/account-categories', ['name' => 'uniforms', 'entry_type' => 'charge', 'sort_order' => '11'])
            ->assertSessionHasErrors(['name' => 'Another category already uses this name.']);
        $this->actingAs($this->finance)->post('/account-categories', ['name' => 'Fees', 'entry_type' => 'charge', 'sort_order' => '11', 'is_active' => '0'])
            ->assertSessionHasErrors(['is_active']);

        $this->actingAs($this->finance)->get("/account-categories/{$laundry->id}/edit")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('staff/accounts/categories/edit')->where('category.name', 'Laundry')->where('category.entryCount', 0));
        $this->actingAs($this->finance)->put("/account-categories/{$laundry->id}", [
            'name' => 'Laundry Service', 'entry_type' => 'charge', 'description' => 'Weekly laundry', 'sort_order' => '12', 'is_active' => false,
        ])->assertRedirect('/account-categories')->assertSessionHasNoErrors();

        $laundry->refresh();
        $this->assertSame('Laundry Service', $laundry->name);
        $this->assertFalse($laundry->is_active);
        $log = AuditLog::query()->where('action', AuditAction::AccountCategoryUpdated->value)->sole();
        $this->assertSame('account_category', $log->auditable_type);
        $this->assertSame(['name' => 'Laundry', 'description' => null, 'sort_order' => 10, 'is_active' => true], $log->old_values);
    }
}
