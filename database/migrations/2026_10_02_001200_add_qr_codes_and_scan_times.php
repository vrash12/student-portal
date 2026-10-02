<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Attendance by QR code (owner request, 2026-10-02).
     *
     * candidates.qr_token: the random token inside the candidate's QR code
     * (32 letters and digits, unique; nothing about the candidate). Reissuing
     * a code replaces it. Every existing candidate gets one here.
     * attendance_records.scanned_at: when the candidate's code was scanned
     * for the session (null for the roll call).
     */
    public function up(): void
    {
        Schema::table('candidates', function (Blueprint $table) {
            $table->string('qr_token', 32)->nullable()->after('candidate_number');
            $table->timestamp('qr_token_issued_at')->nullable()->after('qr_token');
        });

        $issued = now();
        DB::table('candidates')->whereNull('qr_token')->orderBy('id')->select('id')->chunkById(500, function ($candidates) use ($issued): void {
            foreach ($candidates as $candidate) {
                DB::table('candidates')->where('id', $candidate->id)->update(['qr_token' => Str::random(32), 'qr_token_issued_at' => $issued]);
            }
        });

        Schema::table('candidates', function (Blueprint $table) {
            $table->string('qr_token', 32)->nullable(false)->change();
            $table->unique('qr_token');
        });

        Schema::table('attendance_records', function (Blueprint $table) {
            $table->timestamp('scanned_at')->nullable()->after('remarks');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->dropColumn('scanned_at');
        });
        Schema::table('candidates', function (Blueprint $table) {
            $table->dropUnique(['qr_token']);
            $table->dropColumn(['qr_token', 'qr_token_issued_at']);
        });
    }
};
