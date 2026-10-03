<?php

namespace Tests\Feature\Accounts;

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
 * Since 2026-10-03 categories have no usual type or order (always charges,
 * listed by name), so the migration is no longer re-run here.
 */
class ServiceAccountCategoriesTest extends TestCase
{
    private const NAMES = ['Pay & Allowances', 'Deductions', 'Issued Items / Accountability'];

    private function migration(): Migration
    {
        return require database_path('migrations/2026_10_01_000810_add_service_account_categories.php');
    }

    public function test_the_service_categories_exist_and_are_listed_by_name(): void
    {
        $categories = AccountCategory::query()->whereIn('name', self::NAMES)->ordered()->get();

        $this->assertSame(['Deductions', 'Issued Items / Accountability', 'Pay & Allowances'], $categories->pluck('name')->all());
        $this->assertTrue($categories->every(fn (AccountCategory $category): bool => $category->is_active && $category->description !== null));
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
