<?php

namespace Tests\Feature\Accounts;

use App\Enums\AccountEntryType;
use App\Enums\SystemRole;
use App\Models\AccountCategory;
use App\Models\Candidate;
use App\Services\Accounts\AccountService;
use Illuminate\Database\Migrations\Migration;
use Tests\TestCase;

/**
 * Statement of Account categories for a service school (owner request,
 * 2026-10-01): pay and allowances, deductions and issued-item
 * accountability, added after the first defaults. No amounts are seeded.
 */
class ServiceAccountCategoriesTest extends TestCase
{
    private const NAMES = ['Pay & Allowances', 'Deductions', 'Issued Items / Accountability'];

    private function migration(): Migration
    {
        return require database_path('migrations/2026_10_01_000810_add_service_account_categories.php');
    }

    public function test_the_service_categories_exist_after_the_first_defaults(): void
    {
        $categories = AccountCategory::query()->whereIn('name', self::NAMES)->ordered()->get();

        $this->assertSame(self::NAMES, $categories->pluck('name')->all());
        $this->assertSame([AccountEntryType::Credit, AccountEntryType::Charge, AccountEntryType::Charge], $categories->pluck('entry_type')->all());
        $this->assertTrue($categories->every(fn (AccountCategory $category): bool => $category->is_active && $category->description !== null));

        $firstDefaults = AccountCategory::query()->whereNotIn('name', self::NAMES)->max('sort_order');
        $this->assertSame([$firstDefaults + 1, $firstDefaults + 2, $firstDefaults + 3], $categories->pluck('sort_order')->all());
    }

    public function test_running_again_adds_only_the_missing_categories(): void
    {
        AccountCategory::query()->where('name', 'Pay & Allowances')->delete();
        // An administrator's own changes to an existing category are kept.
        AccountCategory::query()->where('name', 'Deductions')->update(['description' => 'Mess and quarters', 'is_active' => false]);
        $before = (int) AccountCategory::query()->max('sort_order');

        $this->migration()->up();
        $this->migration()->up();

        foreach (self::NAMES as $name) {
            $this->assertSame(1, AccountCategory::query()->where('name', $name)->count(), $name);
        }
        $deductions = AccountCategory::query()->where('name', 'Deductions')->sole();
        $this->assertSame('Mess and quarters', $deductions->description);
        $this->assertFalse($deductions->is_active);
        $this->assertSame($before + 1, AccountCategory::query()->where('name', 'Pay & Allowances')->value('sort_order'));
    }

    public function test_rolling_back_keeps_categories_that_entries_use(): void
    {
        $finance = $this->userWithRole(SystemRole::SuperAdministrator);
        app(AccountService::class)->record(Candidate::factory()->create(), [
            'account_category_id' => AccountCategory::query()->where('name', 'Deductions')->value('id'),
            'entry_type' => 'charge',
            'amount' => '250.00',
            'posted_on' => '2026-09-15',
            'description' => 'Synthetic deduction',
            'reference' => null,
        ], $finance);

        $this->migration()->down();

        $this->assertSame(['Deductions'], AccountCategory::query()->whereIn('name', self::NAMES)->pluck('name')->all());
    }
}
