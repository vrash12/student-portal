<?php

use App\Enums\Permission as PermissionCode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Medical documents uploaded by candidates (owner request, 2026-10-02):
     * medical certificates, check-up findings, laboratory results and similar
     * files. Each upload waits for review ("submitted") until medical staff
     * accept it or return it with a reason; the candidate may withdraw an
     * upload only while it waits. Files live on the private local disk
     * (`path`); the browser never sees the path.
     *
     * Instructors see a candidate's documents only through an approved
     * full-record request (medical_access_requests), view-only.
     */
    public function up(): void
    {
        Schema::create('candidate_medical_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidate_id')->constrained()->restrictOnDelete();
            $table->string('category', 30);
            $table->string('title', 150);
            $table->date('document_date')->nullable();
            $table->string('notes', 500)->nullable();
            $table->string('path', 255);
            $table->string('original_name', 255);
            $table->string('mime_type', 100);
            $table->unsignedInteger('size_bytes');
            $table->string('status', 20)->default('submitted');
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note', 500)->nullable();
            $table->timestamps();

            $table->index(['candidate_id', 'status']);
            $table->index(['status', 'created_at']);
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("alter table `candidate_medical_documents` add constraint `candidate_medical_documents_category_check` check (`category` in ('medical_certificate', 'checkup_findings', 'laboratory_result', 'imaging', 'vaccination', 'prescription', 'other'))");
            DB::statement("alter table `candidate_medical_documents` add constraint `candidate_medical_documents_status_check` check (`status` in ('submitted', 'accepted', 'returned'))");
            DB::statement("alter table `candidate_medical_documents` add constraint `candidate_medical_documents_mime_check` check (`mime_type` in ('application/pdf', 'image/jpeg', 'image/png', 'image/webp'))");
            DB::statement('alter table `candidate_medical_documents` add constraint `candidate_medical_documents_size_check` check (`size_bytes` > 0)');
            // A reviewed document always records who reviewed it and when; a returned one also why.
            DB::statement("alter table `candidate_medical_documents` add constraint `candidate_medical_documents_review_check` check ((`status` = 'submitted' and `reviewed_by` is null and `reviewed_at` is null) or (`status` <> 'submitted' and `reviewed_by` is not null and `reviewed_at` is not null))");
            DB::statement("alter table `candidate_medical_documents` add constraint `candidate_medical_documents_return_check` check (`status` <> 'returned' or `review_note` is not null)");
        }

        // The medical permissions now also cover the uploaded documents.
        foreach ([PermissionCode::ViewMedical, PermissionCode::ManageMedical] as $code) {
            DB::table('permissions')->where('code', $code->value)->update(['description' => $code->description(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('candidate_medical_documents');
    }
};
