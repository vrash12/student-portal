<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Subject -> topics -> questions -> answer choices (Milestone 7).
     *
     * Questions are never deleted; they are deactivated. Once a question is
     * part of a published examination its content is locked (locked_at), so
     * every published examination keeps the question exactly as published.
     * See docs/question-bank-examination-contract.md.
     */
    public function up(): void
    {
        // Topics group the questions of one subject.
        Schema::create('question_topics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subject_id')->constrained()->restrictOnDelete();
            $table->string('name', 100);
            $table->timestamps();

            $table->unique(['subject_id', 'name']);
            // Target of the composite foreign key on questions.
            $table->unique(['id', 'subject_id']);
        });

        Schema::create('questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subject_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('question_topic_id')->nullable();
            $table->string('type', 30);
            $table->text('prompt');
            $table->decimal('points', 5, 2);
            // Staff-only notes: why an answer is correct, or essay grading guidance.
            $table->text('explanation')->nullable();
            $table->boolean('is_active')->default(true);
            // Set when the question is first included in a published examination.
            $table->timestamp('locked_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['subject_id', 'is_active', 'type']);
            $table->index(['subject_id', 'created_at']);

            // The topic must belong to the question's subject.
            $table->foreign(['question_topic_id', 'subject_id'])
                ->references(['id', 'subject_id'])
                ->on('question_topics')
                ->restrictOnDelete();
        });

        // Answer options of objective questions, in display order (A, B, C, ...).
        Schema::create('question_choices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('position');
            $table->string('text', 1000);
            $table->boolean('is_correct')->default(false);
            // The question id on the correct choice, NULL on the others: the
            // unique index allows at most one correct choice per question.
            $table->unsignedBigInteger('correct_marker')
                ->nullable()
                ->storedAs('if(`is_correct`, `question_id`, null)');
            $table->timestamps();

            $table->unique(['question_id', 'position']);
            $table->unique('correct_marker');
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("alter table `questions` add constraint `questions_type_check` check (`type` in ('multiple_choice', 'true_false', 'essay'))");
            DB::statement('alter table `questions` add constraint `questions_points_check` check (`points` > 0 and `points` <= 100)');

            DB::statement('alter table `question_choices` add constraint `question_choices_position_check` check (`position` between 1 and 6)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('question_choices');
        Schema::dropIfExists('questions');
        Schema::dropIfExists('question_topics');
    }
};
