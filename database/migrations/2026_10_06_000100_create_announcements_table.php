<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Announcements (owner request, 2026-10-06): notices that candidates see
     * on the portal home page, posted to every candidate, to one campus or to
     * one class. A notice shows from publishes_at until expires_at (if set)
     * unless it is withdrawn; withdrawn notices are kept (who and when).
     *
     * - audience "everyone": no campus, no class;
     * - audience "campus": the campus, no class;
     * - audience "class": the class and its campus (composite foreign key,
     *   so the campus is always the class's campus).
     *
     * Guarded so it can be re-run (MariaDB cannot roll back DDL).
     */
    public function up(): void
    {
        if (Schema::hasTable('announcements')) {
            return;
        }

        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->string('title', 150);
            $table->text('body');
            $table->string('audience', 20);
            $table->foreignId('campus_id')->nullable()->constrained('campuses')->restrictOnDelete();
            $table->unsignedBigInteger('class_batch_id')->nullable();
            $table->boolean('is_important')->default(false);
            $table->dateTime('publishes_at');
            $table->dateTime('expires_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('withdrawn_at')->nullable();
            $table->foreignId('withdrawn_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->foreign(['class_batch_id', 'campus_id'], 'announcements_class_campus_foreign')
                ->references(['id', 'campus_id'])->on('class_batches')->restrictOnDelete();
            $table->index(['audience', 'publishes_at'], 'announcements_audience_publishes_index');
            $table->index(['class_batch_id', 'publishes_at'], 'announcements_class_publishes_index');
        });

        DB::statement("ALTER TABLE announcements ADD CONSTRAINT announcements_audience_check CHECK (
            (audience = 'everyone' AND campus_id IS NULL AND class_batch_id IS NULL)
            OR (audience = 'campus' AND campus_id IS NOT NULL AND class_batch_id IS NULL)
            OR (audience = 'class' AND campus_id IS NOT NULL AND class_batch_id IS NOT NULL)
        )");
        DB::statement('ALTER TABLE announcements ADD CONSTRAINT announcements_expiry_check CHECK (expires_at IS NULL OR expires_at > publishes_at)');
        DB::statement('ALTER TABLE announcements ADD CONSTRAINT announcements_withdrawn_check CHECK ((withdrawn_at IS NULL) = (withdrawn_by IS NULL))');
    }

    public function down(): void
    {
        Schema::dropIfExists('announcements');
    }
};
