<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Owner request (2026-10-03): account categories are always for charges
     * (no "usual type") and are listed by name (no display order), and
     * expenses have no due date. Removes those columns and their checks.
     * Recorded entries keep their own type and amount.
     */
    public function up(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $drop = DB::getDriverName() === 'mariadb' ? 'drop constraint' : 'drop check';
            DB::statement("alter table `account_categories` {$drop} `account_categories_entry_type_check`");
            DB::statement("alter table `account_entries` {$drop} `account_entries_due_check`");
        }

        Schema::table('account_categories', function (Blueprint $table) {
            $table->dropColumn(['entry_type', 'sort_order']);
        });
        Schema::table('account_expenses', function (Blueprint $table) {
            $table->dropColumn('due_on');
        });
        Schema::table('account_entries', function (Blueprint $table) {
            $table->dropColumn('due_on');
        });
    }

    /** The columns come back empty: categories as charges, in name order; no due dates. */
    public function down(): void
    {
        Schema::table('account_categories', function (Blueprint $table) {
            $table->string('entry_type', 10)->default('charge')->after('name');
            $table->unsignedSmallInteger('sort_order')->default(0)->after('description');
        });
        Schema::table('account_expenses', function (Blueprint $table) {
            $table->date('due_on')->nullable()->after('amount');
        });
        Schema::table('account_entries', function (Blueprint $table) {
            $table->date('due_on')->nullable()->after('posted_on');
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("alter table `account_categories` add constraint `account_categories_entry_type_check` check (`entry_type` in ('charge', 'credit'))");
            DB::statement("alter table `account_entries` add constraint `account_entries_due_check` check (`due_on` is null or (`entry_type` = 'charge' and `account_expense_id` is null))");
        }
    }
};
