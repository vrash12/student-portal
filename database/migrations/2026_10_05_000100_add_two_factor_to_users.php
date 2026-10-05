<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Two-step sign-in with authenticator codes (TOTP, RFC 6238; owner
     * request 2026-10-05). Works without internet: the code comes from the
     * shared secret and the clock.
     *
     * - two_factor_secret: the shared secret, encrypted with the app key.
     *   Stored while the user sets it up; on only once confirmed_at is set.
     * - two_factor_recovery_codes: SHA-256 hashes of the unused one-time
     *   recovery codes (encrypted JSON); the codes are shown once.
     * - two_factor_last_step: the 30-second step of the last accepted code,
     *   so a code cannot be used twice.
     *
     * Guarded so it can be re-run (MariaDB cannot roll back DDL).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'two_factor_secret')) {
                $table->text('two_factor_secret')->nullable()->after('password');
            }
            if (! Schema::hasColumn('users', 'two_factor_recovery_codes')) {
                $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_secret');
            }
            if (! Schema::hasColumn('users', 'two_factor_confirmed_at')) {
                $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');
            }
            if (! Schema::hasColumn('users', 'two_factor_last_step')) {
                $table->unsignedBigInteger('two_factor_last_step')->nullable()->after('two_factor_confirmed_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            foreach (['two_factor_last_step', 'two_factor_confirmed_at', 'two_factor_recovery_codes', 'two_factor_secret'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
