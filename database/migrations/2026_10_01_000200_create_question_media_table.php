<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Images, audio, and video attached to a question, shown with its prompt.
     *
     * Files live on the private local disk (storage/app/private/question-media)
     * and are served only to authorized staff and to candidates during their
     * own attempt. Media is question content: once the question is locked by
     * a published examination it cannot be added, changed, or removed, so
     * attempts keep referring to the same files.
     */
    public function up(): void
    {
        Schema::create('question_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('position');
            $table->string('kind', 10);
            $table->string('path', 255)->unique();
            $table->string('original_name', 255);
            $table->string('mime_type', 100);
            $table->unsignedInteger('size_bytes');
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            // Alternative text for images, caption/description for audio and video.
            $table->string('description', 500);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['question_id', 'position']);
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("alter table `question_media` add constraint `question_media_kind_check` check (`kind` in ('image', 'audio', 'video'))");
            DB::statement('alter table `question_media` add constraint `question_media_position_check` check (`position` between 1 and 4)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('question_media');
    }
};
