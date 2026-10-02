<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A fuller medical record (owner request, 2026-10-02): fields are grouped
     * into sections chosen by the administrators (e.g. "Medical History"),
     * and a "number" type with an optional unit holds measurements such as
     * height (cm) or pulse rate (bpm).
     */
    public function up(): void
    {
        Schema::table('medical_fields', function (Blueprint $table) {
            $table->string('section', 60)->nullable()->after('name');
            $table->string('unit', 20)->nullable()->after('options');
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $drop = DB::getDriverName() === 'mariadb' ? 'drop constraint' : 'drop check';
            DB::statement("alter table `medical_fields` {$drop} `medical_fields_type_check`");
            DB::statement("alter table `medical_fields` add constraint `medical_fields_type_check` check (`field_type` in ('text', 'long_text', 'number', 'choice', 'date', 'yes_no'))");
            DB::statement("alter table `medical_fields` add constraint `medical_fields_unit_check` check (`unit` is null or `field_type` = 'number')");
        }
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $drop = DB::getDriverName() === 'mariadb' ? 'drop constraint' : 'drop check';
            DB::statement("alter table `medical_fields` {$drop} `medical_fields_unit_check`");
            DB::statement("alter table `medical_fields` {$drop} `medical_fields_type_check`");
            DB::statement("alter table `medical_fields` add constraint `medical_fields_type_check` check (`field_type` in ('text', 'long_text', 'choice', 'date', 'yes_no'))");
        }

        Schema::table('medical_fields', function (Blueprint $table) {
            $table->dropColumn(['section', 'unit']);
        });
    }
};
