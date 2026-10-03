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
     * Campuses (owner request, 2026-10-03): one organization with several
     * campuses, not multi-tenancy. Classes, candidates and instructors belong
     * to a campus; staff accounts without a campus see every campus.
     *
     * - class_batches.campus_id: required, fixed when the class is created.
     * - candidates.campus_id: required; equal to the class's campus whenever
     *   the candidate has a class (composite foreign key).
     * - users.campus_id: nullable; null = every campus (administrators only).
     * - class_subjects.campus_id and instructor_assignments.campus_id: copies
     *   of the class's campus kept so composite foreign keys make "an
     *   instructor only teaches classes of their own campus" a database rule.
     * - audit_logs.campus_id: which campus an entry is about (null = an
     *   institution-wide entry), for campus-limited audit history.
     *
     * Existing data is attached to a "Main Campus" (created only when there
     * is data to attach), so nothing disappears after the deploy. Teaching
     * staff join it; administrators stay institution-wide. Backup restores
     * run only `migrate`, so this migration also grants campuses.manage.
     *
     * Every step is guarded: MariaDB cannot roll back DDL, so a deploy that
     * stopped half-way can run this migration again.
     */
    public function up(): void
    {
        if (! Schema::hasTable('campuses')) {
            Schema::create('campuses', function (Blueprint $table) {
                $table->id();
                $table->string('name', 100)->unique();
                $table->string('code', 20)->unique();
                $table->string('address', 255)->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        $this->addColumn('class_batches', 'academic_period_id');
        $this->addColumn('candidates', 'class_batch_id');
        $this->addColumn('users', 'role_id');
        $this->addColumn('class_subjects', 'class_batch_id');
        $this->addColumn('instructor_assignments', 'class_subject_id');
        $this->addColumn('audit_logs', 'actor_id');

        $this->backfill();

        foreach (['class_batches', 'candidates', 'class_subjects', 'instructor_assignments'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->unsignedBigInteger('campus_id')->nullable(false)->change();
            });
        }

        $this->addKeys();
        $this->grantPermission();
    }

    public function down(): void
    {
        $duplicates = DB::table('class_batches')
            ->select('academic_period_id', 'name')
            ->groupBy('academic_period_id', 'name')
            ->havingRaw('count(*) > 1')
            ->exists();
        if ($duplicates) {
            throw new RuntimeException('Campuses cannot be removed: two campuses use the same class name in one academic year. Rename one of the classes first.');
        }

        $this->dropForeign('instructor_assignments', 'instructor_assignments_instructor_campus_foreign');
        $this->dropForeign('instructor_assignments', 'instructor_assignments_offering_campus_foreign');
        $this->dropForeign('class_subjects', 'class_subjects_class_campus_foreign');
        $this->dropForeign('candidates', 'candidates_class_campus_foreign');
        $this->dropForeign('candidates', 'candidates_campus_id_foreign');
        $this->dropForeign('users', 'users_campus_id_foreign');
        $this->dropForeign('class_batches', 'class_batches_campus_id_foreign');
        $this->dropForeign('audit_logs', 'audit_logs_campus_id_foreign');

        // The old name rule comes back before the campus-aware one is dropped:
        // the period's foreign key needs one of the two.
        $this->addIndex('class_batches', 'class_batches_academic_period_id_name_unique', fn (Blueprint $table) => $table->unique(['academic_period_id', 'name'], 'class_batches_academic_period_id_name_unique'));

        $this->dropIndex('class_subjects', 'class_subjects_id_campus_unique');
        $this->dropIndex('class_subjects', 'class_subjects_campus_id_index');
        $this->dropIndex('class_batches', 'class_batches_id_campus_unique');
        $this->dropIndex('class_batches', 'class_batches_period_campus_name_unique');
        $this->dropIndex('users', 'users_id_campus_unique');
        $this->dropIndex('candidates', 'candidates_campus_status_index');
        $this->dropIndex('audit_logs', 'audit_logs_campus_created_index');

        foreach (['instructor_assignments', 'class_subjects', 'candidates', 'users', 'class_batches', 'audit_logs'] as $table) {
            if (Schema::hasColumn($table, 'campus_id')) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropColumn('campus_id'));
            }
        }

        Permission::query()->where('code', 'campuses.manage')->delete();
        Schema::dropIfExists('campuses');
    }

    private function addColumn(string $table, string $after): void
    {
        if (! Schema::hasColumn($table, 'campus_id')) {
            Schema::table($table, function (Blueprint $blueprint) use ($after) {
                $blueprint->unsignedBigInteger('campus_id')->nullable()->after($after);
            });
        }
    }

    /**
     * Attaches existing records to a default campus. A fresh installation
     * has nothing to attach and gets no campus: administrators create the
     * first one on the Campuses page.
     */
    private function backfill(): void
    {
        $hasData = DB::table('class_batches')->exists()
            || DB::table('candidates')->exists()
            || DB::table('users')->exists();

        if (! $hasData) {
            return;
        }

        $mainId = DB::table('campuses')->where('code', 'MAIN')->value('id');
        if ($mainId === null) {
            $mainId = DB::table('campuses')->insertGetId([
                'name' => 'Main Campus',
                'code' => 'MAIN',
                'address' => null,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('class_batches')->whereNull('campus_id')->update(['campus_id' => $mainId]);

        // Copies follow the class; candidates without a class join the default campus.
        DB::statement('update `class_subjects` inner join `class_batches` on `class_batches`.`id` = `class_subjects`.`class_batch_id` set `class_subjects`.`campus_id` = `class_batches`.`campus_id` where `class_subjects`.`campus_id` is null');
        DB::statement('update `instructor_assignments` inner join `class_subjects` on `class_subjects`.`id` = `instructor_assignments`.`class_subject_id` set `instructor_assignments`.`campus_id` = `class_subjects`.`campus_id` where `instructor_assignments`.`campus_id` is null');
        DB::statement('update `candidates` inner join `class_batches` on `class_batches`.`id` = `candidates`.`class_batch_id` set `candidates`.`campus_id` = `class_batches`.`campus_id` where `candidates`.`campus_id` is null');
        DB::table('candidates')->whereNull('campus_id')->update(['campus_id' => $mainId]);

        // Teaching staff (and anyone holding an assignment) join the default
        // campus; administrators and candidate accounts stay without one.
        $teachingRoles = DB::table('permission_role')
            ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
            ->where('permissions.code', PermissionCode::TeachClasses->value)
            ->pluck('permission_role.role_id')
            ->all();
        DB::table('users')
            ->whereNull('campus_id')
            ->where(fn ($users) => $users
                ->whereIn('role_id', $teachingRoles)
                ->orWhereIn('id', DB::table('instructor_assignments')->select('instructor_id')))
            ->update(['campus_id' => $mainId]);

        // Audit entries about campus records belong to the default campus
        // (the only one so far). Entries about shared settings stay
        // institution-wide (null). Only this new column is filled in.
        DB::table('audit_logs')
            ->whereNull('campus_id')
            ->where(fn ($entries) => $entries
                ->whereIn('auditable_type', [
                    'class_batch', 'class_subject', 'instructor_assignment', 'candidate',
                    'assessment_category', 'assessment', 'assessment_score', 'grade_correction_request',
                    'medical_access_request', 'candidate_medical_document', 'medical_download_request',
                    'examination', 'examination_attempt', 'fitness_test', 'account_entry',
                    'conduct_entry', 'attendance_session',
                ])
                ->orWhere('action', 'account_expense.assigned'))
            ->update(['campus_id' => $mainId]);
        DB::statement("update `audit_logs` inner join `users` on `users`.`id` = `audit_logs`.`auditable_id` left join `candidates` on `candidates`.`user_id` = `users`.`id` set `audit_logs`.`campus_id` = coalesce(`users`.`campus_id`, `candidates`.`campus_id`) where `audit_logs`.`auditable_type` = 'user' and `audit_logs`.`campus_id` is null");
    }

    private function addKeys(): void
    {
        // Targets of the composite foreign keys.
        $this->addIndex('class_batches', 'class_batches_id_campus_unique', fn (Blueprint $table) => $table->unique(['id', 'campus_id'], 'class_batches_id_campus_unique'));
        $this->addIndex('class_subjects', 'class_subjects_id_campus_unique', fn (Blueprint $table) => $table->unique(['id', 'campus_id'], 'class_subjects_id_campus_unique'));
        $this->addIndex('users', 'users_id_campus_unique', fn (Blueprint $table) => $table->unique(['id', 'campus_id'], 'users_id_campus_unique'));

        // Two campuses may each have a "Class A" in the same academic year.
        // The new index is added first: the period's foreign key relies on the old one.
        $this->addIndex('class_batches', 'class_batches_period_campus_name_unique', fn (Blueprint $table) => $table->unique(['academic_period_id', 'campus_id', 'name'], 'class_batches_period_campus_name_unique'));
        $this->dropIndex('class_batches', 'class_batches_academic_period_id_name_unique');

        $this->addIndex('class_subjects', 'class_subjects_campus_id_index', fn (Blueprint $table) => $table->index('campus_id', 'class_subjects_campus_id_index'));
        $this->addIndex('candidates', 'candidates_campus_status_index', fn (Blueprint $table) => $table->index(['campus_id', 'status'], 'candidates_campus_status_index'));
        $this->addIndex('audit_logs', 'audit_logs_campus_created_index', fn (Blueprint $table) => $table->index(['campus_id', 'created_at'], 'audit_logs_campus_created_index'));

        $this->addForeign('class_batches', 'class_batches_campus_id_foreign', fn (Blueprint $table) => $table->foreign('campus_id', 'class_batches_campus_id_foreign')->references('id')->on('campuses')->restrictOnDelete());
        $this->addForeign('users', 'users_campus_id_foreign', fn (Blueprint $table) => $table->foreign('campus_id', 'users_campus_id_foreign')->references('id')->on('campuses')->restrictOnDelete());
        $this->addForeign('audit_logs', 'audit_logs_campus_id_foreign', fn (Blueprint $table) => $table->foreign('campus_id', 'audit_logs_campus_id_foreign')->references('id')->on('campuses')->restrictOnDelete());
        $this->addForeign('candidates', 'candidates_campus_id_foreign', fn (Blueprint $table) => $table->foreign('campus_id', 'candidates_campus_id_foreign')->references('id')->on('campuses')->restrictOnDelete());

        // A candidate in a class is on the class's campus (not checked by
        // the database while the candidate has no class).
        $this->addForeign('candidates', 'candidates_class_campus_foreign', fn (Blueprint $table) => $table->foreign(['class_batch_id', 'campus_id'], 'candidates_class_campus_foreign')->references(['id', 'campus_id'])->on('class_batches')->restrictOnDelete());
        $this->addForeign('class_subjects', 'class_subjects_class_campus_foreign', fn (Blueprint $table) => $table->foreign(['class_batch_id', 'campus_id'], 'class_subjects_class_campus_foreign')->references(['id', 'campus_id'])->on('class_batches')->restrictOnDelete());

        // An instructor teaches only subjects of classes on their own campus.
        $this->addForeign('instructor_assignments', 'instructor_assignments_offering_campus_foreign', fn (Blueprint $table) => $table->foreign(['class_subject_id', 'campus_id'], 'instructor_assignments_offering_campus_foreign')->references(['id', 'campus_id'])->on('class_subjects')->cascadeOnDelete());
        $this->addForeign('instructor_assignments', 'instructor_assignments_instructor_campus_foreign', fn (Blueprint $table) => $table->foreign(['instructor_id', 'campus_id'], 'instructor_assignments_instructor_campus_foreign')->references(['id', 'campus_id'])->on('users')->restrictOnDelete());
    }

    /**
     * Adds campuses.manage and grants it to the roles that hold it by default
     * (the Admin), as AccessControlSeeder does for new databases.
     */
    private function grantPermission(): void
    {
        DB::transaction(function (): void {
            $code = PermissionCode::ManageCampuses;
            $permission = Permission::query()->updateOrCreate(
                ['code' => $code->value],
                ['name' => $code->label(), 'description' => $code->description(), 'group' => $code->group()],
            );
            foreach (SystemRole::cases() as $systemRole) {
                if (in_array($code, $systemRole->defaultPermissions(), true)) {
                    Role::query()->where('code', $systemRole->value)->first()?->permissions()->syncWithoutDetaching([$permission->id]);
                }
            }

            // "View all candidates" now means every candidate of the user's campus.
            $viewAll = PermissionCode::ViewAllCandidates;
            Permission::query()->where('code', $viewAll->value)->update(['description' => $viewAll->description()]);
        });
    }

    private function indexExists(string $table, string $name): bool
    {
        return collect(Schema::getIndexes($table))->contains(fn (array $index): bool => $index['name'] === $name);
    }

    private function foreignExists(string $table, string $name): bool
    {
        return collect(Schema::getForeignKeys($table))->contains(fn (array $key): bool => $key['name'] === $name);
    }

    /**
     * @param  Closure(Blueprint): mixed  $definition
     */
    private function addIndex(string $table, string $name, Closure $definition): void
    {
        if (! $this->indexExists($table, $name)) {
            Schema::table($table, $definition);
        }
    }

    /**
     * @param  Closure(Blueprint): mixed  $definition
     */
    private function addForeign(string $table, string $name, Closure $definition): void
    {
        if (! $this->foreignExists($table, $name)) {
            Schema::table($table, $definition);
        }
    }

    private function dropIndex(string $table, string $name): void
    {
        if ($this->indexExists($table, $name)) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropIndex($name));
        }
    }

    private function dropForeign(string $table, string $name): void
    {
        if (Schema::hasTable($table) && $this->foreignExists($table, $name)) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropForeign($name));
        }
    }
};
