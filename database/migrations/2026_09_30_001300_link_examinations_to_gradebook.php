<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assessments', function (Blueprint $table): void {
            $table->foreignId('source_examination_id')->nullable()->unique()->constrained('examinations')->restrictOnDelete();
            $table->string('exam_attempt_rule', 20)->nullable();
        });
        Schema::table('assessment_scores', function (Blueprint $table): void {
            $table->foreignId('source_examination_attempt_id')->nullable()->constrained('examination_attempts')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('assessment_scores', fn (Blueprint $table) => $table->dropConstrainedForeignId('source_examination_attempt_id'));
        Schema::table('assessments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('source_examination_id');
            $table->dropColumn('exam_attempt_rule');
        });
    }
};
