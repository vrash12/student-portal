<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Performance areas and qualification (owner request, 2026-10-01): a
     * layer on top of academic standing that groups subject grades, the
     * latest fitness test, conduct and attendance into weighted areas with a
     * passing grade and a must-pass flag. The permissions were added by
     * migration 2026_10_01_000750.
     *
     * Nothing is seeded: areas, weights and passing grades are institutional
     * rules configured in the application (placeholders come from the demo
     * seeder until the institution provides its grading SOP).
     *
     * subjects.performance_area_id maps a subject to the area its grades
     * count toward. Only areas whose source is `subjects` hold subjects;
     * PerformanceAreaService enforces that, because a CHECK constraint cannot
     * look at another table.
     */
    public function up(): void
    {
        Schema::create('performance_areas', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->string('description', 255)->nullable();
            $table->string('source', 20);
            // Share of the overall score; the overall divides by the total of the weights used.
            $table->decimal('weight', 5, 2);
            $table->decimal('passing_grade', 5, 2);
            $table->boolean('must_pass')->default(true);
            // Conduct rating rule (conduct areas only): base + merit points × merit value − demerit points × demerit value.
            $table->decimal('base_rating', 5, 2)->nullable();
            $table->decimal('merit_value', 5, 2)->nullable();
            $table->decimal('demerit_value', 5, 2)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            // The source of an active fitness, conduct or attendance area, and
            // NULL otherwise. The unique index allows many NULLs, so at most
            // one such area per source can be active (MySQL and MariaDB have
            // no partial indexes). PerformanceAreaService also checks this
            // under a lock, with a readable message.
            $table->string('active_source_marker', 20)
                ->nullable()
                ->storedAs("if(`is_active` = 1 and `source` <> 'subjects', `source`, null)")
                ->unique();
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });

        Schema::table('subjects', function (Blueprint $table) {
            $table->foreignId('performance_area_id')->nullable()->after('description')->constrained()->restrictOnDelete();
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("alter table `performance_areas` add constraint `performance_areas_source_check` check (`source` in ('subjects', 'fitness', 'conduct', 'attendance'))");
            DB::statement('alter table `performance_areas` add constraint `performance_areas_weight_check` check (`weight` between 0 and 100)');
            DB::statement('alter table `performance_areas` add constraint `performance_areas_passing_grade_check` check (`passing_grade` > 0 and `passing_grade` <= 100)');
            DB::statement('alter table `performance_areas` add constraint `performance_areas_conduct_values_range_check` check ((`base_rating` is null or `base_rating` between 0 and 100) and (`merit_value` is null or `merit_value` between 0 and 100) and (`demerit_value` is null or `demerit_value` between 0 and 100))');
            // The conduct rating rule is set for conduct areas and only for them.
            DB::statement("alter table `performance_areas` add constraint `performance_areas_conduct_values_check` check ((`source` = 'conduct' and `base_rating` is not null and `merit_value` is not null and `demerit_value` is not null) or (`source` <> 'conduct' and `base_rating` is null and `merit_value` is null and `demerit_value` is null))");
        }
    }

    public function down(): void
    {
        Schema::table('subjects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('performance_area_id');
        });

        Schema::dropIfExists('performance_areas');
    }
};
