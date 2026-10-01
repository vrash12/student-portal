<?php

use App\Enums\Permission as PermissionCode;
use App\Enums\SystemRole;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Default categories from the client's request; editable in the application. */
    private const DEFAULT_CATEGORIES = [
        ['Billing', 'charge'],
        ['Chargeable Items', 'charge'],
        ['Uniforms', 'charge'],
        ['Meals', 'charge'],
        ['Military Fitness', 'charge'],
        ['Meal Allowance', 'credit'],
        ['Allowance', 'credit'],
        ['Bank Deposit', 'credit'],
        ['Payment Received', 'credit'],
    ];

    /**
     * Statement of Account (owner request, 2026-10-01): an internal ledger
     * per candidate. No online payments and no bank connections.
     *
     * account_entries are never updated or deleted except to void them with
     * a reason; balances count only entries that are not voided.
     */
    public function up(): void
    {
        Schema::create('account_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->string('entry_type', 10);
            $table->string('description', 255)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('account_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidate_id')->constrained()->restrictOnDelete();
            $table->foreignId('account_category_id')->constrained()->restrictOnDelete();
            $table->string('entry_type', 10);
            $table->decimal('amount', 12, 2);
            $table->date('posted_on');
            $table->string('description', 255);
            $table->string('reference', 100)->nullable();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('void_reason', 255)->nullable();
            $table->timestamps();

            $table->index(['candidate_id', 'posted_on']);
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("alter table `account_categories` add constraint `account_categories_entry_type_check` check (`entry_type` in ('charge', 'credit'))");
            DB::statement("alter table `account_entries` add constraint `account_entries_entry_type_check` check (`entry_type` in ('charge', 'credit'))");
            DB::statement('alter table `account_entries` add constraint `account_entries_amount_check` check (`amount` > 0)');
            // A void always records when, by whom, and why.
            DB::statement('alter table `account_entries` add constraint `account_entries_void_check` check ((`voided_at` is null and `voided_by` is null and `void_reason` is null) or (`voided_at` is not null and `voided_by` is not null and `void_reason` is not null))');
        }

        $now = now();
        DB::table('account_categories')->insert(array_map(fn (array $category, int $index): array => [
            'name' => $category[0],
            'entry_type' => $category[1],
            'description' => null,
            'sort_order' => $index + 1,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ], self::DEFAULT_CATEGORIES, array_keys(self::DEFAULT_CATEGORIES)));

        $this->grantPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('account_entries');
        Schema::dropIfExists('account_categories');
        Permission::query()->whereIn('code', [PermissionCode::ViewAccounts->value, PermissionCode::ManageAccounts->value])->delete();
        Role::query()->where('code', SystemRole::FinanceOfficer->value)->whereDoesntHave('users')->delete();
    }

    /**
     * Adds the new permissions and the Finance Officer role to existing
     * databases (AccessControlSeeder does the same for new ones).
     */
    private function grantPermissions(): void
    {
        DB::transaction(function (): void {
            $ids = [];
            foreach ([PermissionCode::ViewAccounts, PermissionCode::ManageAccounts] as $code) {
                $ids[$code->value] = Permission::query()->updateOrCreate(
                    ['code' => $code->value],
                    ['name' => $code->label(), 'description' => $code->description(), 'group' => $code->group()],
                )->id;
            }

            $finance = Role::query()->firstOrNew(['code' => SystemRole::FinanceOfficer->value]);
            $finance->fill(['name' => SystemRole::FinanceOfficer->label(), 'description' => SystemRole::FinanceOfficer->description()]);
            $finance->is_system = true;
            $finance->rank = SystemRole::FinanceOfficer->rank();
            $finance->save();

            $allIds = Permission::query()->pluck('id', 'code');
            foreach (SystemRole::cases() as $systemRole) {
                $role = Role::query()->where('code', $systemRole->value)->first();
                if ($role === null) {
                    continue;
                }

                $defaults = array_map(fn (PermissionCode $permission): string => $permission->value, $systemRole->defaultPermissions());
                $role->permissions()->syncWithoutDetaching($systemRole === SystemRole::FinanceOfficer
                    ? array_values(array_filter(array_map(fn (string $code): ?int => $allIds[$code] ?? null, $defaults)))
                    : array_values(array_intersect_key($ids, array_flip($defaults))));
            }
        });
    }
};
