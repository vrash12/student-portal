<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('examinations', function (Blueprint $table) {
            $table->boolean('release_results')->default(false);
        });
        Schema::table('examination_attempts', function (Blueprint $table) {
            // Private key is kept separately from the candidate delivery snapshot.
            $table->json('scoring_key')->nullable();
            $table->json('item_scores')->nullable();
            $table->string('result_status', 20)->nullable();
            $table->decimal('objective_points', 12, 2)->nullable();
            $table->decimal('objective_max_points', 12, 2)->nullable();
            $table->decimal('total_points', 12, 2)->nullable();
            $table->decimal('earned_points', 12, 2)->nullable();
            $table->decimal('percentage', 5, 2)->nullable();
            $table->decimal('passing_score', 5, 2)->nullable();
            $table->boolean('passed')->nullable();
            $table->string('submission_kind', 20)->nullable();
            $table->dateTime('scored_at')->nullable();
            $table->index(['status', 'expires_at'], 'attempts_due_index');
            $table->index(['examination_id', 'result_status'], 'attempt_results_index');
        });
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("alter table examination_attempts add constraint attempt_result_status_check check (result_status is null or result_status in ('pending_review', 'graded'))");
            DB::statement('alter table examination_attempts add constraint attempt_scores_check check ((percentage is null or percentage between 0 and 100) and (earned_points is null or earned_points between 0 and total_points) and (objective_points is null or objective_points between 0 and objective_max_points))');
            DB::statement("alter table examination_attempts add constraint attempt_submission_kind_check check (submission_kind is null or submission_kind in ('manual', 'automatic'))");
        }
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            foreach (['attempt_result_status_check', 'attempt_scores_check', 'attempt_submission_kind_check'] as $constraint) {
                DB::statement('alter table examination_attempts drop constraint '.$constraint);
            }
        }
        Schema::table('examination_attempts', function (Blueprint $table) {
            $table->dropIndex('attempts_due_index');
            $table->dropIndex('attempt_results_index');
            $table->dropColumn(['scoring_key', 'item_scores', 'result_status', 'objective_points', 'objective_max_points', 'total_points', 'earned_points', 'percentage', 'passing_score', 'passed', 'submission_kind', 'scored_at']);
        });
        Schema::table('examinations', fn (Blueprint $table) => $table->dropColumn('release_results'));
    }
};
