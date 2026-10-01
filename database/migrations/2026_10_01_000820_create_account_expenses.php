<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Expenses assigned to candidates (owner request, 2026-10-01): finance
     * staff define an expense once (name, category, amount, optional due
     * date) and assign it to a whole class or to chosen candidates. Each
     * assignment is an ordinary charge entry linked to the expense, so the
     * ledger, voiding and balances stay as they are.
     *
     * An expense is charged to a candidate at most once among the entries
     * that count: `standing_expense_id` holds the expense only while the
     * entry is not voided, and is unique per candidate (MySQL and MariaDB
     * have no partial indexes). Voiding the charge allows assigning again.
     */
    public function up(): void
    {
        Schema::create('account_expenses', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150)->unique();
            $table->foreignId('account_category_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->date('due_on')->nullable();
            $table->string('description', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::table('account_entries', function (Blueprint $table) {
            $table->foreignId('account_expense_id')->nullable()->after('account_category_id')->constrained()->restrictOnDelete();
            // Due date of a charge recorded on its own; assigned expenses use the expense's due date.
            $table->date('due_on')->nullable()->after('posted_on');
            $table->unsignedBigInteger('standing_expense_id')->nullable()->storedAs('case when voided_at is null then account_expense_id end');
            $table->unique(['candidate_id', 'standing_expense_id'], 'account_entries_standing_expense_unique');
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('alter table `account_expenses` add constraint `account_expenses_amount_check` check (`amount` > 0)');
            // Expenses and due dates belong to charges; a due date is kept only on charges not linked to an expense.
            DB::statement("alter table `account_entries` add constraint `account_entries_expense_check` check (`account_expense_id` is null or `entry_type` = 'charge')");
            DB::statement("alter table `account_entries` add constraint `account_entries_due_check` check (`due_on` is null or (`entry_type` = 'charge' and `account_expense_id` is null))");
        }
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $drop = DB::getDriverName() === 'mariadb' ? 'drop constraint' : 'drop check';
            DB::statement("alter table `account_entries` {$drop} `account_entries_expense_check`");
            DB::statement("alter table `account_entries` {$drop} `account_entries_due_check`");
        }

        Schema::table('account_entries', function (Blueprint $table) {
            $table->dropUnique('account_entries_standing_expense_unique');
            $table->dropColumn('standing_expense_id');
            $table->dropConstrainedForeignId('account_expense_id');
            $table->dropColumn('due_on');
        });

        Schema::dropIfExists('account_expenses');
    }
};
