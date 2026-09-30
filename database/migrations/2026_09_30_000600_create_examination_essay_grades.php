<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('examination_essay_grades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('examination_attempt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('examination_question_id')->constrained()->restrictOnDelete();
            $table->decimal('score', 8, 2);
            $table->text('comment')->nullable();
            $table->foreignId('graded_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('graded_at');
            $table->timestamps();
            $table->unique(['examination_attempt_id', 'examination_question_id'], 'essay_grade_unique');
            $table->index(['graded_by', 'graded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('examination_essay_grades');
    }
};
