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
    private const PERMISSIONS = [
        PermissionCode::ViewMedical,
        PermissionCode::ManageMedical,
        PermissionCode::ConfigureMedical,
    ];

    /**
     * Candidate medical records (owner request, 2026-10-02). Administrators
     * define the fields (name, type, choices, and whether instructors of the
     * candidate's classes and the candidate may see them); each candidate has
     * at most one value per field. Every change is kept in
     * candidate_medical_revisions, readable only by medical staff; the general
     * audit log records which fields changed, never their values.
     */
    public function up(): void
    {
        Schema::create('medical_fields', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->string('field_type', 20);
            // Choice fields only: the allowed answers, in order.
            $table->json('options')->nullable();
            $table->string('help_text', 255)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('visible_to_instructors')->default(false);
            $table->boolean('visible_to_candidate')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('candidate_medical_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidate_id')->constrained()->restrictOnDelete();
            $table->foreignId('medical_field_id')->constrained()->restrictOnDelete();
            $table->text('value');
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['candidate_id', 'medical_field_id']);
        });

        Schema::create('candidate_medical_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidate_id')->constrained()->restrictOnDelete();
            $table->foreignId('medical_field_id')->constrained()->restrictOnDelete();
            $table->text('previous_value')->nullable();
            $table->text('new_value')->nullable();
            $table->foreignId('changed_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['candidate_id', 'id']);
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("alter table `medical_fields` add constraint `medical_fields_type_check` check (`field_type` in ('text', 'long_text', 'choice', 'date', 'yes_no'))");
            DB::statement("alter table `medical_fields` add constraint `medical_fields_options_check` check ((`field_type` = 'choice') = (`options` is not null))");
            DB::statement('alter table `candidate_medical_revisions` add constraint `candidate_medical_revisions_change_check` check (`previous_value` is not null or `new_value` is not null)');
        }

        DB::transaction(function (): void {
            foreach (self::PERMISSIONS as $code) {
                $permission = Permission::query()->updateOrCreate(
                    ['code' => $code->value],
                    ['name' => $code->label(), 'description' => $code->description(), 'group' => $code->group()],
                );
                foreach (SystemRole::cases() as $systemRole) {
                    if (in_array($code, $systemRole->defaultPermissions(), true)) {
                        Role::query()->where('code', $systemRole->value)->first()?->permissions()->syncWithoutDetaching([$permission->id]);
                    }
                }
            }
        });
    }

    public function down(): void
    {
        Permission::query()->whereIn('code', array_map(fn (PermissionCode $code): string => $code->value, self::PERMISSIONS))->delete();

        Schema::dropIfExists('candidate_medical_revisions');
        Schema::dropIfExists('candidate_medical_values');
        Schema::dropIfExists('medical_fields');
    }
};
