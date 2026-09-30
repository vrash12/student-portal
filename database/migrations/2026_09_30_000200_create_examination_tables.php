<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('examinations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('class_subject_id')->constrained()->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->string('title', 200);
            $t->text('description')->nullable();
            $t->string('status', 20)->default('draft');
            $t->dateTime('opens_at')->nullable();
            $t->dateTime('closes_at')->nullable();
            $t->unsignedInteger('duration_minutes')->nullable();
            $t->unsignedInteger('attempt_limit')->default(1);
            $t->decimal('passing_score', 5, 2)->nullable();
            $t->boolean('randomize_questions')->default(false);
            $t->boolean('randomize_choices')->default(false);
            $t->boolean('one_question_at_a_time')->default(false);
            $t->boolean('allow_back_navigation')->default(true);
            $t->boolean('auto_submit')->default(true);
            $t->string('access_code', 100)->nullable();
            $t->timestamps();
            $t->index(['class_subject_id', 'status']);
        });
        Schema::create('examination_questions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('examination_id')->constrained()->cascadeOnDelete();
            $t->foreignId('question_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('position');
            $t->decimal('points', 5, 2);
            $t->timestamps();
            $t->unique(['examination_id', 'question_id']);
            $t->unique(['examination_id', 'position']);
        });
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("alter table examinations add constraint examinations_status_check check (status in ('draft','published','archived'))");
            DB::statement('alter table examinations add constraint examinations_duration_check check (duration_minutes is null or duration_minutes > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('examination_questions');
        Schema::dropIfExists('examinations');
    }
};
