<?php

use App\Enums\Permission as PermissionCode;
use App\Enums\SystemRole;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Authorized grade corrections (owner request, 2026-10-02): an instructor
     * can no longer overwrite a finalized score directly. They file a
     * correction request with an incident report, and an administrator
     * approves (the score is then corrected and the revision points back to
     * the request) or rejects it.
     *
     * The current score is copied when the request is filed, so approval can
     * refuse a request whose score changed meanwhile. One candidate has at
     * most one pending request per assessment: `pending_candidate_id` holds
     * the candidate only while the request is pending, and is unique per
     * assessment (MySQL and MariaDB have no partial indexes).
     */
    public function up(): void
    {
        Schema::create('grade_correction_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_id')->constrained()->restrictOnDelete();
            $table->foreignId('candidate_id')->constrained()->restrictOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->decimal('current_score', 6, 2)->nullable();
            $table->string('current_comment', 500)->nullable();
            $table->decimal('proposed_score', 6, 2)->nullable();
            $table->string('proposed_comment', 500)->nullable();
            $table->string('incident_type', 30);
            $table->text('incident_details');
            $table->string('status', 20)->default('pending');
            $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 1000)->nullable();
            $table->timestamps();
            $table->unsignedBigInteger('pending_candidate_id')->nullable()->storedAs("case when status = 'pending' then candidate_id end");

            $table->unique(['assessment_id', 'pending_candidate_id'], 'grade_correction_requests_one_pending_unique');
            $table->index(['status', 'created_at']);
        });

        Schema::table('assessment_score_revisions', function (Blueprint $table) {
            // The approved request behind a correction; null for other revisions and for corrections made before 2026-10-02.
            $table->foreignId('grade_correction_request_id')->nullable()->after('reason')->constrained()->restrictOnDelete();
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("alter table `grade_correction_requests` add constraint `grade_correction_requests_status_check` check (`status` in ('pending', 'approved', 'rejected', 'cancelled'))");
            DB::statement("alter table `grade_correction_requests` add constraint `grade_correction_requests_incident_type_check` check (`incident_type` in ('encoding_error', 'wrong_candidate', 'computation_error', 'rechecked', 'late_requirement', 'other'))");
            DB::statement('alter table `grade_correction_requests` add constraint `grade_correction_requests_scores_check` check ((`current_score` is null or `current_score` >= 0) and (`proposed_score` is null or `proposed_score` >= 0))');
            // A decision has a decider and a time; a pending request has neither. A rejection always gives a reason.
            DB::statement("alter table `grade_correction_requests` add constraint `grade_correction_requests_decision_check` check ((`status` = 'pending' and `decided_by` is null and `decided_at` is null) or (`status` <> 'pending' and `decided_by` is not null and `decided_at` is not null))");
            DB::statement("alter table `grade_correction_requests` add constraint `grade_correction_requests_rejection_check` check (`status` <> 'rejected' or `decision_note` is not null)");
        }

        // The approval permission, granted to the system roles that hold it by default (AccessControlSeeder does the same for new databases).
        DB::transaction(function (): void {
            $code = PermissionCode::ApproveGradeCorrections;
            $permission = Permission::query()->updateOrCreate(
                ['code' => $code->value],
                ['name' => $code->label(), 'description' => $code->description(), 'group' => $code->group()],
            );
            foreach (SystemRole::cases() as $systemRole) {
                if (in_array($code, $systemRole->defaultPermissions(), true)) {
                    Role::query()->where('code', $systemRole->value)->first()?->permissions()->syncWithoutDetaching([$permission->id]);
                }
            }
            // The description of recording grades now says corrections are requested.
            $record = PermissionCode::RecordGrades;
            Permission::query()->where('code', $record->value)->update(['description' => $record->description()]);
        });
    }

    public function down(): void
    {
        Permission::query()->where('code', PermissionCode::ApproveGradeCorrections->value)->delete();

        Schema::table('assessment_score_revisions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('grade_correction_request_id');
        });

        Schema::dropIfExists('grade_correction_requests');
    }
};
