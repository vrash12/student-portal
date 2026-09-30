<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('examination_attempts', function (Blueprint $table) {
            $table->dateTime('last_activity_at')->nullable()->after('started_at');
            $table->index(['examination_id', 'status', 'last_activity_at'], 'attempt_monitor_index');
        });
    }

    public function down(): void
    {
        Schema::table('examination_attempts', function (Blueprint $table) {
            $table->dropIndex('attempt_monitor_index');
            $table->dropColumn('last_activity_at');
        });
    }
};
