<?php

namespace Tests\Feature\Accounts;

use App\Enums\AuditAction;
use App\Enums\Permission;
use App\Enums\SystemRole;
use App\Models\AcademicPeriod;
use App\Models\AccountCategory;
use App\Models\AccountEntry;
use App\Models\AccountExpense;
use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\ClassBatch;
use App\Models\User;
use App\Services\Accounts\AccountService;
use Database\Seeders\DemoAccountStatementsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

/**
 * Charge entries behind Expenses (owner request, 2026-10-01): voiding,
 * database rules, categories, roles and demo data. The Statements of
 * Account pages were removed at the owner's request on 2026-10-01; only
 * the Expenses feature remains (AccountExpenseTest).
 */
class AccountEntriesTest extends TestCase
{
    private User $admin;

    private Candidate $first;

    private AccountCategory $uniforms;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->userWithRole(SystemRole::SuperAdministrator);
        $class = ClassBatch::factory()->for(AcademicPeriod::factory()->active())->create(['name' => 'Class A']);
        $this->first = Candidate::factory()->create(['class_batch_id' => $class->id, 'candidate_number' => 'C-001', 'last_name' => 'Alpha']);
        Candidate::factory()->create(['class_batch_id' => $class->id, 'candidate_number' => 'C-002', 'last_name' => 'Bravo']);

        // Seeded by the migration.
        $this->uniforms = AccountCategory::query()->where('name', 'Uniforms')->sole();
    }

    private function record(Candidate $candidate, string $amount = '500.00', string $description = 'Uniform set'): AccountEntry
    {
        return app(AccountService::class)->record($candidate, [
            'account_category_id' => $this->uniforms->id,
            'entry_type' => 'charge',
            'amount' => $amount,
            'posted_on' => '2026-09-01',
            'description' => $description,
            'reference' => null,
        ], $this->admin);
    }

    public function test_the_migration_seeds_the_default_categories(): void
    {
        // The service-school categories (migration 2026_10_01_000810) follow the first defaults.
        $this->assertSame(
            ['Billing', 'Chargeable Items', 'Uniforms', 'Meals', 'Military Fitness', 'Meal Allowance', 'Allowance', 'Bank Deposit', 'Payment Received',
                'Pay & Allowances', 'Deductions', 'Issued Items / Accountability'],
            AccountCategory::query()->ordered()->pluck('name')->all(),
        );
    }

    public function test_a_voided_charge_is_kept_audited_and_cannot_be_voided_twice(): void
    {
        $mistake = $this->record($this->first, '500', 'Uniform set (duplicate)');

        $this->actingAs($this->admin)->from('/account-expenses')
            ->post("/account-entries/{$mistake->id}/void", ['reason' => 'no'])
            ->assertSessionHasErrors(['reason' => 'Give the reason for voiding this entry (at least 5 characters).']);
        $this->assertNull($mistake->fresh()->voided_at);

        $this->actingAs($this->admin)->from('/account-expenses')
            ->post("/account-entries/{$mistake->id}/void", ['reason' => '  Recorded twice  '])
            ->assertRedirect('/account-expenses')
            ->assertSessionHasNoErrors();

        $voided = $mistake->fresh();
        $this->assertSame($this->admin->id, $voided->voided_by);
        $this->assertSame('Recorded twice', $voided->void_reason);
        // Never deleted: the entry and its amount remain.
        $this->assertSame('500.00', $voided->amount);

        $log = AuditLog::query()->where('action', AuditAction::AccountEntryVoided->value)->sole();
        $this->assertSame('account_entry', $log->auditable_type);
        $this->assertSame('Recorded twice', $log->reason);

        $this->actingAs($this->admin)->from('/account-expenses')
            ->post("/account-entries/{$mistake->id}/void", ['reason' => 'Voiding again'])
            ->assertSessionHasErrors(['reason' => 'This entry has already been voided.']);
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::AccountEntryVoided->value)->count());
    }

    public function test_the_database_rejects_invalid_entries(): void
    {
        $insert = fn (array $values) => DB::table('account_entries')->insert([
            'candidate_id' => $this->first->id, 'account_category_id' => $this->uniforms->id, 'entry_type' => 'charge',
            'amount' => '10.00', 'posted_on' => '2026-09-01', 'description' => 'Check', 'recorded_by' => $this->admin->id,
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

    public function test_the_statement_of_account_pages_are_gone(): void
    {
        foreach (['/accounts', "/accounts/{$this->first->id}", "/accounts/{$this->first->id}/statement"] as $url) {
            $this->actingAs($this->admin)->get($url)->assertNotFound();
        }
        // Only the not-found fallback (GET) matches now.
        $this->assertContains($this->actingAs($this->admin)->post("/accounts/{$this->first->id}/entries", [])->getStatusCode(), [404, 405]);
        foreach (['/portal/account', '/portal/account/statement'] as $url) {
            $this->actingAs($this->first->user)->get($url)->assertNotFound();
        }

        $this->actingAs($this->admin)->get('/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page->missing('statementsOverview'));
    }

    public function test_other_roles_cannot_manage_expenses_categories_or_void_charges(): void
    {
        $entry = $this->record($this->first);

        foreach ([$this->userWithRole(SystemRole::AcademicAdministrator), $this->userWithRole(SystemRole::Instructor), $this->first->user] as $user) {
            foreach (['/account-categories', '/account-expenses', '/account-expenses/create'] as $url) {
                $this->actingAs($user)->get($url)->assertForbidden();
            }
            $this->actingAs($user)->post("/account-entries/{$entry->id}/void", ['reason' => 'Not allowed'])->assertForbidden();
            $this->actingAs($user)->post('/account-categories', ['name' => 'Laundry', 'entry_type' => 'charge', 'sort_order' => '10'])->assertForbidden();
        }

        $this->assertNull($entry->fresh()->voided_at);
        $this->actingAs($this->userWithRole(SystemRole::SuperAdministrator))->get('/account-expenses')->assertOk();
    }

    public function test_only_the_super_administrator_manages_expenses_and_there_is_no_finance_role(): void
    {
        $this->assertNull(SystemRole::tryFrom('finance_officer'));
        $this->assertContains(Permission::ManageAccounts, SystemRole::SuperAdministrator->defaultPermissions());
        foreach ([SystemRole::AcademicAdministrator, SystemRole::Instructor, SystemRole::Candidate] as $role) {
            $this->assertNotContains(Permission::ManageAccounts, $role->defaultPermissions(), $role->value);
        }
    }

    public function test_the_migration_retires_the_finance_officer_role(): void
    {
        $migration = require database_path('migrations/2026_10_01_000830_remove_finance_officer_role.php');
        $permission = DB::table('permissions')->where('code', Permission::ManageAccounts->value)->value('id');
        $role = fn (): int => DB::table('roles')->insertGetId(['code' => 'finance_officer', 'name' => 'Finance Officer', 'rank' => 50, 'is_system' => true, 'created_at' => now(), 'updated_at' => now()]);

        // Without accounts the role is deleted.
        $empty = $role();
        DB::table('permission_role')->insert(['role_id' => $empty, 'permission_id' => $permission]);
        $migration->up();
        $this->assertFalse(DB::table('roles')->where('id', $empty)->exists());

        // With an account, the account is deactivated and the role keeps no permissions.
        $used = $role();
        DB::table('permission_role')->insert(['role_id' => $used, 'permission_id' => $permission]);
        $former = User::factory()->create(['role_id' => $used]);
        $migration->up();
        $this->assertFalse($former->fresh()->is_active);
        $this->assertSame(0, DB::table('permission_role')->where('role_id', $used)->count());
        $this->assertSame('Finance Officer (removed)', DB::table('roles')->where('id', $used)->value('name'));
    }

    public function test_the_demo_seeder_adds_synthetic_expenses_once(): void
    {
        $this->admin->forceFill(['username' => 'admin'])->save();
        $this->seed(DemoAccountStatementsSeeder::class);

        $this->assertFalse(User::query()->where('username', 'finance1')->exists());
        $this->assertSame([$this->admin->id], AccountEntry::query()->distinct()->pluck('recorded_by')->all());
        // Per candidate: four assigned expenses; the first also a one-off charge and its voided duplicate. No payments.
        $this->assertSame(10, AccountEntry::query()->count());
        $this->assertSame(8, AccountEntry::query()->whereNotNull('account_expense_id')->count());
        $this->assertSame(0, AccountEntry::query()->where('entry_type', 'credit')->count());
        $this->assertSame(4, AccountExpense::query()->count());
        $this->assertSame(1, AccountEntry::query()->whereNotNull('voided_at')->count());
        $this->assertSame(4, AuditLog::query()->where('action', AuditAction::AccountExpenseAssigned->value)->count());

        $this->seed(DemoAccountStatementsSeeder::class);
        $this->assertSame(10, AccountEntry::query()->count());
        $this->assertSame(4, AccountExpense::query()->count());

        $this->app->detectEnvironment(fn (): string => 'production');
        $this->expectException(RuntimeException::class);
        $this->app->call([$this->app->make(DemoAccountStatementsSeeder::class), 'run']);
    }

    public function test_categories_can_be_created_updated_and_deactivated(): void
    {
        $this->actingAs($this->admin)->get('/account-categories')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('staff/accounts/categories/index')->has('categories', 12));

        $this->actingAs($this->admin)->post('/account-categories', ['name' => '  Laundry  ', 'entry_type' => 'charge', 'description' => '', 'sort_order' => '10'])
            ->assertRedirect('/account-categories')->assertSessionHasNoErrors();
        $laundry = AccountCategory::query()->where('name', 'Laundry')->sole();
        $this->assertTrue($laundry->is_active);
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::AccountCategoryCreated->value)->count());

        $this->actingAs($this->admin)->post('/account-categories', ['name' => 'uniforms', 'entry_type' => 'charge', 'sort_order' => '11'])
            ->assertSessionHasErrors(['name' => 'Another category already uses this name.']);

        $this->actingAs($this->admin)->put("/account-categories/{$laundry->id}", [
            'name' => 'Laundry Service', 'entry_type' => 'charge', 'description' => 'Weekly laundry', 'sort_order' => '12', 'is_active' => false,
        ])->assertRedirect('/account-categories')->assertSessionHasNoErrors();

        $laundry->refresh();
        $this->assertSame('Laundry Service', $laundry->name);
        $this->assertFalse($laundry->is_active);
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::AccountCategoryUpdated->value)->count());
    }
}
