<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('examination_attempts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('examination_id')->constrained()->restrictOnDelete();
            $t->foreignId('candidate_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('attempt_number');
            $t->string('status', 20)->default('in_progress');
            $t->dateTime('started_at');
            $t->dateTime('submitted_at')->nullable();
            $t->timestamps();
            $t->unique(['examination_id', 'candidate_id', 'attempt_number'], 'attempt_number_unique');
            $t->index(['candidate_id', 'status']);
        });
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("alter table examination_attempts add constraint examination_attempts_status_check check (status in ('in_progress','submitted','expired'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('examination_attempts');
    }
};
