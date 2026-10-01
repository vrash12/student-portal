<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = ['fitness_events', 'fitness_test_events'];

    /**
     * Configurable points for fitness events (owner request, 2026-10-02):
     * push-ups, sit-ups, runs and other events can be scored with a points
     * table (results and the points each earns), and every event has its own
     * passing points (previously always 60).
     *
     * scoring_method: "table" or "scaled" (the original rule; existing events
     * and tests keep it, so recorded results do not change).
     * points_table: [{value, points}, ...] from the weakest to the best result;
     * passing_value and maximum_value are then null (they follow from the
     * table). Test events keep a copy, like the other standards.
     */
    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->string('scoring_method', 20)->default('scaled')->after('higher_is_better');
                $table->decimal('passing_points', 5, 2)->default(60)->after('scoring_method');
                $table->decimal('passing_value', 8, 2)->nullable()->change();
                $table->decimal('maximum_value', 8, 2)->nullable()->change();
                $table->json('points_table')->nullable()->after('maximum_value');
            });
        }

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            foreach (self::TABLES as $name) {
                DB::statement("alter table `{$name}` add constraint `{$name}_scoring_method_check` check (`scoring_method` in ('table', 'scaled'))");
                DB::statement("alter table `{$name}` add constraint `{$name}_passing_points_check` check (`passing_points` > 0 and `passing_points` <= 100)");
                // A scaled standard has both values (and a maximum above the passing points); a table standard has its table.
                DB::statement("alter table `{$name}` add constraint `{$name}_standard_check` check ((`scoring_method` = 'scaled' and `passing_value` is not null and `maximum_value` is not null and `points_table` is null and `passing_points` < 100) or (`scoring_method` = 'table' and `points_table` is not null))");
            }
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            if (DB::table($name)->where('scoring_method', 'table')->exists()) {
                throw new RuntimeException("{$name} has points-table standards, which the earlier schema cannot hold. Change them to scaled standards first.");
            }
        }

        foreach (self::TABLES as $name) {
            if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
                foreach (['scoring_method', 'passing_points', 'standard'] as $check) {
                    DB::statement("alter table `{$name}` drop constraint `{$name}_{$check}_check`");
                }
            }

            Schema::table($name, function (Blueprint $table): void {
                $table->dropColumn(['scoring_method', 'passing_points', 'points_table']);
                $table->decimal('passing_value', 8, 2)->nullable(false)->change();
                $table->decimal('maximum_value', 8, 2)->nullable(false)->change();
            });
        }
    }
};
