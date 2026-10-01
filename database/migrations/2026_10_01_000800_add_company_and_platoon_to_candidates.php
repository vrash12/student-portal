<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Company and platoon (owner request, 2026-10-01): free-text unit
     * assignments, so each institution uses its own names. Both are optional;
     * the index serves the per-class company/platoon filters.
     */
    public function up(): void
    {
        Schema::table('candidates', function (Blueprint $table): void {
            $table->string('company', 50)->nullable()->after('training_group');
            $table->string('platoon', 50)->nullable()->after('company');

            $table->index(['class_batch_id', 'company', 'platoon']);
        });
    }

    public function down(): void
    {
        Schema::table('candidates', function (Blueprint $table): void {
            $table->dropIndex(['class_batch_id', 'company', 'platoon']);
            $table->dropColumn(['company', 'platoon']);
        });
    }
};
