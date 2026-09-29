<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Candidate records. Each candidate signs in with their own user account
     * (Candidate role), created together with the record.
     */
    public function up(): void
    {
        Schema::create('candidates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $table->string('candidate_number', 30)->unique();
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->foreignId('class_batch_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('enrolled');
            $table->timestamps();

            $table->index(['class_batch_id', 'status']);
            $table->index(['last_name', 'first_name']);
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("alter table `candidates` add constraint `candidates_status_check` check (`status` in ('enrolled', 'on_leave', 'withdrawn', 'completed'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('candidates');
    }
};
