<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('examination_attempts', function (Blueprint $t) {
            $t->dateTime('expires_at')->nullable();
            $t->json('delivery')->nullable();
            $t->json('answers')->nullable();
            $t->unsignedInteger('current_position')->default(0);
            $t->unsignedInteger('revision')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('examination_attempts', fn (Blueprint $t) => $t->dropColumn(['expires_at', 'delivery', 'answers', 'current_position', 'revision']));
    }
};
