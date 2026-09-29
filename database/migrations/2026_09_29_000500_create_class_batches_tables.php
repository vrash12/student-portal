<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Academic Period -> Class / Batch -> Subject offering -> Instructor.
     */
    public function up(): void
    {
        Schema::create('class_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_period_id')->constrained()->restrictOnDelete();
            $table->string('name', 100);
            $table->timestamps();

            $table->unique(['academic_period_id', 'name']);
        });

        // A subject taken by a class during its academic period.
        Schema::create('class_subjects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_batch_id')->constrained()->restrictOnDelete();
            $table->foreignId('subject_id')->constrained()->restrictOnDelete();
            $table->timestamps();

            $table->unique(['class_batch_id', 'subject_id']);
        });

        Schema::create('instructor_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_subject_id')->constrained()->cascadeOnDelete();
            $table->foreignId('instructor_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['class_subject_id', 'instructor_id']);
            $table->index('instructor_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instructor_assignments');
        Schema::dropIfExists('class_subjects');
        Schema::dropIfExists('class_batches');
    }
};
