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
     * Standard military fitness testing (owner request, 2026-10-01).
     *
     * fitness_events: the configurable events and their standards. Each event
     * scores 60 points at its passing value and 100 at its maximum value.
     * fitness_tests: one test of a class on a date.
     * fitness_test_events: the standards of each event as they were when the
     * test was created, so later changes never alter recorded results.
     * fitness_results: one raw value per candidate and test event (seconds
     * for timed events).
     */
    public function up(): void
    {
        Schema::create('fitness_events', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->string('description', 500)->nullable();
            $table->string('unit', 20);
            $table->boolean('higher_is_better');
            $table->decimal('passing_value', 8, 2);
            $table->decimal('maximum_value', 8, 2);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('fitness_tests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_batch_id')->constrained()->restrictOnDelete();
            $table->string('title', 150);
            $table->date('tested_on');
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['class_batch_id', 'tested_on']);
        });

        Schema::create('fitness_test_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fitness_test_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fitness_event_id')->constrained()->restrictOnDelete();
            $table->string('name', 100);
            $table->string('unit', 20);
            $table->boolean('higher_is_better');
            $table->decimal('passing_value', 8, 2);
            $table->decimal('maximum_value', 8, 2);
            $table->unsignedSmallInteger('position');

            $table->unique(['fitness_test_id', 'fitness_event_id']);
        });

        Schema::create('fitness_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fitness_test_event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('candidate_id')->constrained()->restrictOnDelete();
            $table->decimal('value', 8, 2);
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['fitness_test_event_id', 'candidate_id']);
            $table->index('candidate_id');
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            foreach (['fitness_events', 'fitness_test_events'] as $table) {
                DB::statement("alter table `{$table}` add constraint `{$table}_unit_check` check (`unit` in ('repetitions', 'time'))");
                DB::statement("alter table `{$table}` add constraint `{$table}_values_check` check (`passing_value` > 0 and `maximum_value` > 0)");
                // The maximum standard is better than the passing standard.
                DB::statement("alter table `{$table}` add constraint `{$table}_direction_check` check ((`higher_is_better` = 1 and `maximum_value` > `passing_value`) or (`higher_is_better` = 0 and `maximum_value` < `passing_value`))");
            }
            DB::statement('alter table `fitness_results` add constraint `fitness_results_value_check` check (`value` >= 0)');
        }

        $this->grantPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('fitness_results');
        Schema::dropIfExists('fitness_test_events');
        Schema::dropIfExists('fitness_tests');
        Schema::dropIfExists('fitness_events');
        Permission::query()->whereIn('code', [PermissionCode::ViewFitness->value, PermissionCode::ManageFitness->value])->delete();
    }

    /** Adds the new permissions to existing databases and to the system roles that hold them by default. */
    private function grantPermissions(): void
    {
        DB::transaction(function (): void {
            foreach ([PermissionCode::ViewFitness, PermissionCode::ManageFitness] as $code) {
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
};
