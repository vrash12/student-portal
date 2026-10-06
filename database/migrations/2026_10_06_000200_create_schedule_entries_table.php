<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Training schedule (owner request, 2026-10-06): the weekly timetable of
     * each class. An entry happens once (on starts_on) or every week on the
     * weekday of starts_on until ends_on, between start_time and end_time
     * (the institution's local time). It may name one of the class's
     * subjects, an instructor of the class's campus and a room or field.
     *
     * campus_id is a copy of the class's campus so composite foreign keys
     * keep the class, the subject and the instructor on one campus (as for
     * instructor assignments). That the subject belongs to the same class is
     * checked by ScheduleService.
     *
     * Examinations, fitness tests and attendance sessions are not copied
     * here: the calendar reads them from their own tables.
     *
     * Guarded so it can be re-run (MariaDB cannot roll back DDL).
     */
    public function up(): void
    {
        if (Schema::hasTable('schedule_entries')) {
            return;
        }

        Schema::create('schedule_entries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('class_batch_id');
            $table->unsignedBigInteger('campus_id');
            $table->unsignedBigInteger('class_subject_id')->nullable();
            $table->unsignedBigInteger('instructor_id')->nullable();
            $table->string('title', 150);
            $table->string('location', 120)->nullable();
            $table->date('starts_on');
            $table->boolean('repeats_weekly')->default(false);
            $table->date('ends_on')->nullable();
            $table->time('start_time');
            $table->time('end_time');
            $table->string('notes', 300)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->foreign('campus_id', 'schedule_entries_campus_id_foreign')->references('id')->on('campuses')->restrictOnDelete();
            $table->foreign(['class_batch_id', 'campus_id'], 'schedule_entries_class_campus_foreign')
                ->references(['id', 'campus_id'])->on('class_batches')->restrictOnDelete();
            $table->foreign(['class_subject_id', 'campus_id'], 'schedule_entries_offering_campus_foreign')
                ->references(['id', 'campus_id'])->on('class_subjects')->restrictOnDelete();
            $table->foreign(['instructor_id', 'campus_id'], 'schedule_entries_instructor_campus_foreign')
                ->references(['id', 'campus_id'])->on('users')->restrictOnDelete();

            $table->index(['class_batch_id', 'starts_on'], 'schedule_entries_class_starts_index');
            $table->index(['instructor_id', 'starts_on'], 'schedule_entries_instructor_starts_index');
        });

        DB::statement('ALTER TABLE schedule_entries ADD CONSTRAINT schedule_entries_times_check CHECK (end_time > start_time)');
        DB::statement('ALTER TABLE schedule_entries ADD CONSTRAINT schedule_entries_repeat_check CHECK (
            (repeats_weekly = 0 AND ends_on IS NULL) OR (repeats_weekly = 1 AND ends_on IS NOT NULL AND ends_on >= starts_on)
        )');
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_entries');
    }
};
