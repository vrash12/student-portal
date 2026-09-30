<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('examination_essay_grades', function (Blueprint $table) {
            $table->unsignedInteger('version')->default(1);
            $table->decimal('max_points', 8, 2)->nullable();
        });
        DB::table('examination_essay_grades')->orderBy('id')->each(function (object $grade) {
            $attempt = DB::table('examination_attempts')->find($grade->examination_attempt_id);
            $key = json_decode($attempt->scoring_key ?? '{}', true);
            $maximum = $key[$grade->examination_question_id]['points'] ?? DB::table('examination_questions')->where('id', $grade->examination_question_id)->value('points');
            DB::table('examination_essay_grades')->where('id', $grade->id)->update(['max_points' => $maximum]);
        });
        Schema::create('examination_essay_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('examination_essay_grade_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->decimal('previous_score', 8, 2)->nullable();
            $table->decimal('new_score', 8, 2);
            $table->text('previous_comment')->nullable();
            $table->text('new_comment')->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['examination_essay_grade_id', 'version'], 'essay_revision_unique');
        });
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('alter table examination_essay_grades add constraint essay_score_range check (max_points is not null and max_points > 0 and score between 0 and max_points)');
            DB::statement('alter table examination_essay_revisions add constraint essay_revision_score check (new_score >= 0 and (previous_score is null or previous_score >= 0))');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('examination_essay_revisions');
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('alter table examination_essay_grades drop constraint essay_score_range');
        }
        Schema::table('examination_essay_grades', fn (Blueprint $table) => $table->dropColumn(['version', 'max_points']));
    }
};
