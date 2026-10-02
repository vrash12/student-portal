<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Instructors' requests to download an uploaded medical document (owner
     * request, 2026-10-02). With approved access an instructor views documents
     * in the protected viewer only; a copy of a file needs its own request,
     * with a reason, approved by the medical staff. An approval lets that
     * instructor download that one document until `expires_at`; every download
     * is counted and audited. Withdrawing the approval (or the instructor's
     * full-record access) ends it at once. When email is set up, an approval
     * may also send the file to the instructor's email.
     *
     * One pending request per instructor and document: `pending_document_id`
     * holds the document only while the request is pending, unique per
     * requester (MySQL and MariaDB have no partial indexes).
     */
    public function up(): void
    {
        Schema::create('medical_download_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidate_medical_document_id')->constrained()->restrictOnDelete();
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
            $table->unsignedSmallInteger('download_count')->default(0);
            $table->timestamp('last_downloaded_at')->nullable();
            $table->timestamps();
            $table->unsignedBigInteger('pending_document_id')->nullable()->storedAs("case when status = 'pending' then candidate_medical_document_id end");

            $table->unique(['requested_by', 'pending_document_id'], 'medical_download_requests_one_pending_unique');
            $table->index(['candidate_medical_document_id', 'requested_by', 'status'], 'medical_download_requests_document_requester_index');
            $table->index(['candidate_id', 'requested_by', 'status']);
            $table->index(['status', 'created_at']);
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("alter table `medical_download_requests` add constraint `medical_download_requests_status_check` check (`status` in ('pending', 'approved', 'rejected', 'cancelled', 'revoked'))");
            DB::statement("alter table `medical_download_requests` add constraint `medical_download_requests_decision_check` check ((`status` = 'pending' and `decided_by` is null and `decided_at` is null) or (`status` <> 'pending' and `decided_by` is not null and `decided_at` is not null))");
            DB::statement("alter table `medical_download_requests` add constraint `medical_download_requests_rejection_check` check (`status` <> 'rejected' or `decision_note` is not null)");
            DB::statement("alter table `medical_download_requests` add constraint `medical_download_requests_expiry_check` check ((`status` in ('approved', 'revoked')) = (`expires_at` is not null))");
            DB::statement("alter table `medical_download_requests` add constraint `medical_download_requests_revoke_check` check ((`status` = 'revoked') = (`revoked_at` is not null and `revoked_by` is not null))");
            // Only an approval can be used to download.
            DB::statement("alter table `medical_download_requests` add constraint `medical_download_requests_downloads_check` check (`download_count` = 0 or `status` in ('approved', 'revoked'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('medical_download_requests');
    }
};
