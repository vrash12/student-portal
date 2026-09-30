<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An answer choice of a multiple-choice question may show one image.
     * Choice images are rows of question_media with question_choice_id set
     * and no position (question-level media keep positions 1–4). The choice
     * must belong to the same question; deleting the choice deletes its image
     * row (the service removes the file).
     */
    public function up(): void
    {
        Schema::table('question_choices', function (Blueprint $table) {
            // Target of the composite foreign key below.
            $table->unique(['id', 'question_id']);
        });

        Schema::table('question_media', function (Blueprint $table) {
            $table->unsignedBigInteger('question_choice_id')->nullable()->after('question_id');
            $table->unsignedTinyInteger('position')->nullable()->change();

            // One image per choice (NULLs, i.e. question-level media, are not limited).
            $table->unique('question_choice_id');
            $table->foreign(['question_choice_id', 'question_id'])
                ->references(['id', 'question_id'])
                ->on('question_choices')
                ->cascadeOnDelete();
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('alter table `question_media` add constraint `question_media_target_check` check ((`question_choice_id` is null and `position` is not null) or (`question_choice_id` is not null and `position` is null))');
            DB::statement("alter table `question_media` add constraint `question_media_choice_kind_check` check (`question_choice_id` is null or `kind` = 'image')");
        }
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('alter table `question_media` drop constraint `question_media_choice_kind_check`');
            DB::statement('alter table `question_media` drop constraint `question_media_target_check`');
        }

        DB::table('question_media')->whereNotNull('question_choice_id')->delete();

        Schema::table('question_media', function (Blueprint $table) {
            $table->dropForeign(['question_choice_id', 'question_id']);
            $table->dropUnique(['question_choice_id']);
            $table->dropColumn('question_choice_id');
            $table->unsignedTinyInteger('position')->nullable(false)->change();
        });

        Schema::table('question_choices', function (Blueprint $table) {
            $table->dropUnique(['id', 'question_id']);
        });
    }
};
