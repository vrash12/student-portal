<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The candidate's background record (owner request, 2026-10-03: the
     * profile was too basic): personal details, contact and emergency
     * contact, eligibility, prior service and occupation (one row per
     * candidate), and the education attained (bachelor's degree and others,
     * one row each). Every value is optional; medical details stay in the
     * medical record.
     */
    public function up(): void
    {
        Schema::create('candidate_backgrounds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidate_id')->unique()->constrained()->cascadeOnDelete();
            $table->date('date_of_birth')->nullable();
            $table->string('place_of_birth', 150)->nullable();
            $table->string('sex', 10)->nullable();
            $table->string('civil_status', 20)->nullable();
            $table->string('home_address', 255)->nullable();
            $table->string('mobile_number', 30)->nullable();
            $table->string('personal_email', 150)->nullable();
            $table->string('emergency_contact_name', 150)->nullable();
            $table->string('emergency_contact_relationship', 50)->nullable();
            $table->string('emergency_contact_phone', 30)->nullable();
            $table->string('eligibility', 255)->nullable();
            $table->string('prior_service', 255)->nullable();
            $table->string('previous_occupation', 150)->nullable();
            $table->timestamps();
        });

        Schema::create('candidate_education', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidate_id')->constrained()->cascadeOnDelete();
            $table->string('level', 20);
            $table->string('degree', 150);
            $table->string('school', 150);
            $table->unsignedSmallInteger('year_graduated')->nullable();
            $table->string('honors', 100)->nullable();
            $table->unsignedTinyInteger('position');
            $table->timestamps();
            $table->unique(['candidate_id', 'position']);
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("alter table `candidate_backgrounds` add constraint `candidate_backgrounds_sex_check` check (`sex` in ('male', 'female'))");
            DB::statement("alter table `candidate_backgrounds` add constraint `candidate_backgrounds_civil_status_check` check (`civil_status` in ('single', 'married', 'widowed', 'separated'))");
            DB::statement("alter table `candidate_education` add constraint `candidate_education_level_check` check (`level` in ('bachelor', 'master', 'doctorate', 'vocational', 'other'))");
            DB::statement('alter table `candidate_education` add constraint `candidate_education_year_check` check (`year_graduated` between 1950 and 2100)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('candidate_education');
        Schema::dropIfExists('candidate_backgrounds');
    }
};
