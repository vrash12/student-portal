<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Class subject -> grading categories (weights) -> assessments -> scores.
     * Every change to a score is kept in assessment_score_revisions.
     */
    public function up(): void
    {
        // Grading categories and their weights for one subject of one class.
        // The weights of an offering add up to 100; that cross-row rule is
        // enforced by GradingSchemeService under a row lock.
        Schema::create('assessment_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_subject_id')->constrained()->restrictOnDelete();
            $table->string('name', 100);
            $table->decimal('weight', 5, 2);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['class_subject_id', 'name']);
            // Target of the composite foreign key on assessments.
            $table->unique(['id', 'class_subject_id']);
        });

        Schema::create('assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_subject_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('assessment_category_id');
            $table->string('title', 150);
            $table->decimal('max_score', 6, 2);
            $table->date('assessed_on')->nullable();
            $table->string('status', 20)->default('draft');
            $table->timestamp('finalized_at')->nullable();
            $table->foreignId('finalized_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['class_subject_id', 'title']);
            $table->index(['class_subject_id', 'status']);
            $table->index('assessed_on');

            // The category must belong to the same class subject.
            $table->foreign(['assessment_category_id', 'class_subject_id'])
                ->references(['id', 'class_subject_id'])
                ->on('assessment_categories')
                ->restrictOnDelete();
        });

        // One row per candidate and assessment. A null score with a comment
        // records, for example, an absence without a score.
        Schema::create('assessment_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_id')->constrained()->restrictOnDelete();
            $table->foreignId('candidate_id')->constrained()->restrictOnDelete();
            $table->decimal('score', 6, 2)->nullable();
            $table->string('comment', 500)->nullable();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['assessment_id', 'candidate_id']);
            $table->index('candidate_id');
        });

        // Append-only history of every score change (AGENTS.md §36).
        Schema::create('assessment_score_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_score_id')->constrained()->restrictOnDelete();
            $table->string('kind', 20);
            $table->decimal('previous_score', 6, 2)->nullable();
            $table->decimal('new_score', 6, 2)->nullable();
            $table->string('comment', 500)->nullable();
            $table->string('reason', 500)->nullable();
            $table->foreignId('changed_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['assessment_score_id', 'id']);
            $table->index(['kind', 'created_at']);
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('alter table `assessment_categories` add constraint `assessment_categories_weight_check` check (`weight` > 0 and `weight` <= 100)');

            DB::statement('alter table `assessments` add constraint `assessments_max_score_check` check (`max_score` > 0)');
            DB::statement("alter table `assessments` add constraint `assessments_status_check` check (`status` in ('draft', 'finalized'))");
            DB::statement("alter table `assessments` add constraint `assessments_finalization_check` check ((`status` = 'draft' and `finalized_at` is null and `finalized_by` is null) or (`status` = 'finalized' and `finalized_at` is not null and `finalized_by` is not null))");

            DB::statement('alter table `assessment_scores` add constraint `assessment_scores_score_check` check (`score` is null or `score` >= 0)');

            DB::statement("alter table `assessment_score_revisions` add constraint `assessment_score_revisions_kind_check` check (`kind` in ('recorded', 'updated', 'corrected'))");
            DB::statement("alter table `assessment_score_revisions` add constraint `assessment_score_revisions_reason_check` check (`kind` <> 'corrected' or `reason` is not null)");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('assessment_score_revisions');
        Schema::dropIfExists('assessment_scores');
        Schema::dropIfExists('assessments');
        Schema::dropIfExists('assessment_categories');
    }
};
