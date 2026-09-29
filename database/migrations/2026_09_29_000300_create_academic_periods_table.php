<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_periods', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->boolean('is_active')->default(false);
            // 1 for the active period and NULL otherwise. The unique index
            // allows many NULLs, so at most one period can be active (MySQL
            // and MariaDB have no partial indexes).
            $table->unsignedTinyInteger('active_marker')
                ->nullable()
                ->storedAs('if(`is_active`, 1, null)')
                ->unique();
            $table->timestamps();
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('alter table `academic_periods` add constraint `academic_periods_dates_check` check (`ends_on` >= `starts_on`)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_periods');
    }
};
