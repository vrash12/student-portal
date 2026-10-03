<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Owner request (2026-10-03): quizzes and examinations are taken once,
     * at the same time, under the instructor's eye, so there are no retakes.
     * Every examination allows one attempt (Examination::ATTEMPTS_ALLOWED);
     * the per-examination attempt limit is removed. Attempts already
     * recorded are kept.
     */
    public function up(): void
    {
        Schema::table('examinations', function (Blueprint $table) {
            $table->dropColumn('attempt_limit');
        });
    }

    /** The setting comes back as one attempt for every examination. */
    public function down(): void
    {
        Schema::table('examinations', function (Blueprint $table) {
            $table->unsignedInteger('attempt_limit')->default(1)->after('duration_minutes');
        });
    }
};
