<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Training phases and subject units (owner request, 2026-10-03): a class
     * keeps the same candidates for the whole course, and each of its
     * subjects belongs to a phase (Phase 1, 2, 3, or the phases OCS uses)
     * and carries units. Phase averages and the CGPA are the unit-weighted
     * averages of the subject grades (GradeCalculationService).
     *
     * Three placeholder phases are created; administrators rename them, add
     * more or delete unused ones (Academics → Training Phases). Existing class
     * subjects get no phase and 1 unit, so every average stays as it was.
     */
    public function up(): void
    {
        Schema::create('training_phases', function (Blueprint $table) {
            $table->id();
            // Position in the course: Phase 1 comes before Phase 2.
            $table->unsignedTinyInteger('number')->unique();
            $table->string('name', 100)->unique();
            $table->timestamps();
        });

        Schema::table('class_subjects', function (Blueprint $table) {
            $table->foreignId('training_phase_id')->nullable()->after('subject_id')->constrained()->restrictOnDelete();
            $table->decimal('units', 4, 2)->default(1)->after('training_phase_id');
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('alter table `training_phases` add constraint `training_phases_number_check` check (`number` between 1 and 20)');
            DB::statement('alter table `class_subjects` add constraint `class_subjects_units_check` check (`units` > 0)');
        }

        $now = now();
        DB::table('training_phases')->insert(array_map(fn (int $number): array => [
            'number' => $number, 'name' => "Phase {$number}", 'created_at' => $now, 'updated_at' => $now,
        ], [1, 2, 3]));
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $drop = DB::getDriverName() === 'mariadb' ? 'drop constraint' : 'drop check';
            DB::statement("alter table `class_subjects` {$drop} `class_subjects_units_check`");
        }

        Schema::table('class_subjects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('training_phase_id');
            $table->dropColumn('units');
        });

        Schema::dropIfExists('training_phases');
    }
};
