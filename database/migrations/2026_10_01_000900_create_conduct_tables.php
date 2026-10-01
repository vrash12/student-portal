<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Placeholder merit and demerit types (name, kind, default points),
     * editable in the application until the institution provides its
     * official conduct rules.
     */
    private const DEFAULT_TYPES = [
        ['Outstanding performance', 'merit', 5],
        ['Leadership commendation', 'merit', 3],
        ['Exemplary conduct', 'merit', 2],
        ['Late for formation', 'demerit', 2],
        ['Improper uniform', 'demerit', 1],
        ['Violation of regulations', 'demerit', 5],
    ];

    /**
     * Merits and demerits (owner request, 2026-10-01): a ledger of conduct
     * entries per candidate. The permissions were added by migration
     * 2026_10_01_000750.
     *
     * conduct_entries are never updated or deleted except to void them with
     * a reason (same pattern as account_entries); totals count only entries
     * that are not voided. Each entry copies the kind of its type when it is
     * recorded, so later changes to a type never change recorded entries.
     */
    public function up(): void
    {
        Schema::create('conduct_types', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->string('kind', 10);
            $table->unsignedSmallInteger('default_points');
            $table->string('description', 255)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('conduct_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidate_id')->constrained()->restrictOnDelete();
            $table->foreignId('conduct_type_id')->constrained()->restrictOnDelete();
            $table->string('kind', 10);
            $table->unsignedSmallInteger('points');
            $table->date('occurred_on');
            $table->string('reason', 255);
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('void_reason', 255)->nullable();
            $table->timestamps();

            $table->index(['candidate_id', 'occurred_on']);
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            foreach (['conduct_types', 'conduct_entries'] as $table) {
                DB::statement("alter table `{$table}` add constraint `{$table}_kind_check` check (`kind` in ('merit', 'demerit'))");
            }
            DB::statement('alter table `conduct_types` add constraint `conduct_types_default_points_check` check (`default_points` between 1 and 100)');
            DB::statement('alter table `conduct_entries` add constraint `conduct_entries_points_check` check (`points` between 1 and 100)');
            // A void always records when, by whom, and why.
            DB::statement('alter table `conduct_entries` add constraint `conduct_entries_void_check` check ((`voided_at` is null and `voided_by` is null and `void_reason` is null) or (`voided_at` is not null and `voided_by` is not null and `void_reason` is not null))');
        }

        $now = now();
        DB::table('conduct_types')->insert(array_map(fn (array $type, int $index): array => [
            'name' => $type[0],
            'kind' => $type[1],
            'default_points' => $type[2],
            'description' => null,
            'sort_order' => $index + 1,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ], self::DEFAULT_TYPES, array_keys(self::DEFAULT_TYPES)));
    }

    public function down(): void
    {
        Schema::dropIfExists('conduct_entries');
        Schema::dropIfExists('conduct_types');
    }
};
