<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Owner decision (2026-10-02): instructors see no medical information
     * unless the medical staff approve their request for a candidate's full
     * record (medical_access_requests). Fields are therefore no longer shared
     * with instructors one by one.
     */
    public function up(): void
    {
        Schema::table('medical_fields', function (Blueprint $table) {
            $table->dropColumn('visible_to_instructors');
        });
    }

    public function down(): void
    {
        Schema::table('medical_fields', function (Blueprint $table) {
            $table->boolean('visible_to_instructors')->default(false)->after('sort_order');
        });
    }
};
