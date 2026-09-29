<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Passing and warning grades used to decide academic standing in the
     * classes of a period. Both are null until an administrator sets them;
     * there is no built-in default.
     */
    public function up(): void
    {
        Schema::table('academic_periods', function (Blueprint $table) {
            $table->decimal('passing_grade', 5, 2)->nullable()->after('ends_on');
            $table->decimal('warning_grade', 5, 2)->nullable()->after('passing_grade');
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            // Both set or both null. The explicit "is not null" tests matter:
            // a CHECK whose result is unknown (NULL) passes, so without them a
            // passing grade with no warning grade would be accepted.
            DB::statement('alter table `academic_periods` add constraint `academic_periods_grading_thresholds_check` check ('
                .'(`passing_grade` is null and `warning_grade` is null) or '
                .'(`passing_grade` is not null and `warning_grade` is not null '
                .'and `passing_grade` > 0 and `passing_grade` <= `warning_grade` and `warning_grade` <= 100))');
        }
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            // A column used by a CHECK constraint cannot be dropped first.
            DB::statement('alter table `academic_periods` drop constraint `academic_periods_grading_thresholds_check`');
        }

        Schema::table('academic_periods', function (Blueprint $table) {
            $table->dropColumn(['passing_grade', 'warning_grade']);
        });
    }
};
