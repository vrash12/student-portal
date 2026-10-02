<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Instructors' requests to see a candidate's full medical record (owner
     * request, 2026-10-02). Instructors normally see only the fields shared
     * with instructors; with a reason, they may ask the administrators for
     * the full record of one candidate. An approval gives read-only access
     * until `expires_at` (1, 7 or 30 days, chosen by the administrator) and
     * can be revoked earlier. Expiry needs no scheduler: access is checked
     * against `expires_at` when the record is shown.
     *
     * One pending request per instructor and candidate: `pending_candidate_id`
     * holds the candidate only while the request is pending, unique per
     * requester (MySQL and MariaDB have no partial indexes).
     */
    public function up(): void
    {
        Schema::create('medical_access_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidate_id')->constrained()->restrictOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->string('reason', 1000);
            $table->string('status', 20)->default('pending');
            $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 1000)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->unsignedBigInteger('pending_candidate_id')->nullable()->storedAs("case when status = 'pending' then candidate_id end");

            $table->unique(['requested_by', 'pending_candidate_id'], 'medical_access_requests_one_pending_unique');
            $table->index(['candidate_id', 'requested_by', 'status']);
            $table->index(['status', 'created_at']);
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("alter table `medical_access_requests` add constraint `medical_access_requests_status_check` check (`status` in ('pending', 'approved', 'rejected', 'cancelled', 'revoked'))");
            DB::statement("alter table `medical_access_requests` add constraint `medical_access_requests_decision_check` check ((`status` = 'pending' and `decided_by` is null and `decided_at` is null) or (`status` <> 'pending' and `decided_by` is not null and `decided_at` is not null))");
            DB::statement("alter table `medical_access_requests` add constraint `medical_access_requests_rejection_check` check (`status` <> 'rejected' or `decision_note` is not null)");
            // Approved (and later revoked) access always has an end; only revoked access has a revoker.
            DB::statement("alter table `medical_access_requests` add constraint `medical_access_requests_expiry_check` check ((`status` in ('approved', 'revoked')) = (`expires_at` is not null))");
            DB::statement("alter table `medical_access_requests` add constraint `medical_access_requests_revoke_check` check ((`status` = 'revoked') = (`revoked_at` is not null and `revoked_by` is not null))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('medical_access_requests');
    }
};
