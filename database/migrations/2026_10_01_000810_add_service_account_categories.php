<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Statement of Account categories for a service school (owner request,
     * 2026-10-01): candidates receive pay and allowances, have deductions,
     * and are accountable for issued items. There is no tuition. Only the
     * categories are added; amounts are entered per entry, so no pay rate
     * is ever fixed here. Editable in the application like the others.
     *
     * @var list<array{0: string, 1: string, 2: string}>
     */
    private const CATEGORIES = [
        ['Pay & Allowances', 'credit', 'Pay and allowances credited to the candidate.'],
        ['Deductions', 'charge', 'Authorized deductions charged to the candidate.'],
        ['Issued Items / Accountability', 'charge', 'Value of issued items the candidate is accountable for.'],
    ];

    /**
     * Adds each category that does not exist yet (by name; an administrator
     * may already have created or renamed one), after the existing ones.
     */
    public function up(): void
    {
        DB::transaction(function (): void {
            $sortOrder = (int) DB::table('account_categories')->max('sort_order');
            $now = now();

            foreach (self::CATEGORIES as [$name, $entryType, $description]) {
                if (DB::table('account_categories')->where('name', $name)->exists()) {
                    continue;
                }

                DB::table('account_categories')->insert([
                    'name' => $name,
                    'entry_type' => $entryType,
                    'description' => $description,
                    'sort_order' => ++$sortOrder,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        });
    }

    /**
     * Removes the categories again unless entries already use them (those
     * are kept, as in the application).
     */
    public function down(): void
    {
        DB::table('account_categories')
            ->whereIn('name', array_column(self::CATEGORIES, 0))
            ->whereNotExists(fn ($entries) => $entries->select(DB::raw(1))
                ->from('account_entries')
                ->whereColumn('account_entries.account_category_id', 'account_categories.id'))
            ->delete();
    }
};
