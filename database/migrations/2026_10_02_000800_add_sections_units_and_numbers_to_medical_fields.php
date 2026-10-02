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
        // Skips columns already added (a deploy that stopped half-way: MariaDB cannot roll back DDL).
        Schema::table('medical_fields', function (Blueprint $table) {
            if (! Schema::hasColumn('medical_fields', 'section')) {
                $table->string('section', 60)->nullable()->after('name');
            }
            if (! Schema::hasColumn('medical_fields', 'unit')) {
                $table->string('unit', 20)->nullable()->after('options');
            }
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $drop = $this->dropCheck();
            DB::statement("alter table `medical_fields` {$drop} `medical_fields_type_check`");
            DB::statement("alter table `medical_fields` add constraint `medical_fields_type_check` check (`field_type` in ('text', 'long_text', 'number', 'choice', 'date', 'yes_no'))");
            DB::statement("alter table `medical_fields` add constraint `medical_fields_unit_check` check (`unit` is null or `field_type` = 'number')");
        }
    }

    /**
     * MariaDB drops a CHECK with "drop constraint", MySQL with "drop check".
     * Asks the server itself: a MariaDB server may be configured with the
     * `mysql` driver (as on the Hostinger trial site).
     */
    private function dropCheck(): string
    {
        $connection = DB::connection();

        return $connection->getDriverName() === 'mariadb' || (method_exists($connection, 'isMaria') && $connection->isMaria()) ? 'drop constraint' : 'drop check';
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $drop = $this->dropCheck();
            DB::statement("alter table `medical_fields` {$drop} `medical_fields_unit_check`");
            DB::statement("alter table `medical_fields` {$drop} `medical_fields_type_check`");
            DB::statement("alter table `medical_fields` add constraint `medical_fields_type_check` check (`field_type` in ('text', 'long_text', 'choice', 'date', 'yes_no'))");
        }

        Schema::table('medical_fields', function (Blueprint $table) {
            $table->dropColumn(['section', 'unit']);
        });
    }
};
