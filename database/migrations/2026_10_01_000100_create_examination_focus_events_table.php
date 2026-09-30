<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Times a candidate left the examination screen during an attempt
     * (switched tab or app, minimized the browser, or moved focus to another
     * window). One row per departure; returned_at stays null while the
     * candidate is still away. This is an indicator for instructors, not
     * proof of misconduct.
     */
    public function up(): void
    {
        Schema::create('examination_focus_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('examination_attempt_id')->constrained()->cascadeOnDelete();
            // hidden: the page was hidden (tab/app switch, minimized); blur: another window took focus.
            $table->string('reason', 10);
            // An explicit default stops MariaDB/MySQL from making the first TIMESTAMP
            // column ON UPDATE CURRENT_TIMESTAMP, which would overwrite left_at when
            // returned_at is saved.
            $table->timestamp('left_at')->useCurrent();
            $table->timestamp('returned_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['examination_attempt_id', 'left_at']);
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("alter table `examination_focus_events` add constraint `examination_focus_events_reason_check` check (`reason` in ('hidden', 'blur'))");
            DB::statement('alter table `examination_focus_events` add constraint `examination_focus_events_order_check` check (`returned_at` is null or `returned_at` >= `left_at`)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('examination_focus_events');
    }
};
