<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Attendance (owner request, 2026-10-01).
     *
     * attendance_sessions: one training session of a class on a date, with
     * its length in hours (configurable per session, never hardcoded).
     * attendance_records: one status per candidate and session. A session
     * with records is history and cannot be deleted; every change of a
     * record is in the audit log with the previous and new values.
     */
    public function up(): void
    {
        Schema::create('attendance_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_batch_id')->constrained()->restrictOnDelete();
            $table->date('held_on');
            $table->string('title', 150);
            $table->decimal('hours', 4, 2);
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['class_batch_id', 'held_on']);
        });

        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('candidate_id')->constrained()->restrictOnDelete();
            $table->string('status', 10);
            $table->string('remarks', 255)->nullable();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            // One status per candidate and session; also serves candidate lookups by session.
            $table->unique(['attendance_session_id', 'candidate_id']);
            $table->index('candidate_id');
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('alter table `attendance_sessions` add constraint `attendance_sessions_hours_check` check (`hours` > 0 and `hours` <= 24)');
            DB::statement("alter table `attendance_records` add constraint `attendance_records_status_check` check (`status` in ('present', 'late', 'excused', 'absent'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_records');
        Schema::dropIfExists('attendance_sessions');
    }
};
