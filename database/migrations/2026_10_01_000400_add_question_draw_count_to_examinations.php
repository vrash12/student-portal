<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Random question subsets: when set, each attempt receives this many
 * questions drawn at random from the examination's questions (for example
 * 20 of 50). Null delivers every question.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('examinations', function (Blueprint $table): void {
            $table->unsignedSmallInteger('question_draw_count')->nullable()->after('randomize_choices');
        });

        DB::statement('ALTER TABLE examinations ADD CONSTRAINT examinations_question_draw_count_check CHECK (question_draw_count IS NULL OR (question_draw_count >= 1 AND question_draw_count <= 500))');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE examinations DROP CONSTRAINT examinations_question_draw_count_check');

        Schema::table('examinations', function (Blueprint $table): void {
            $table->dropColumn('question_draw_count');
        });
    }
};
