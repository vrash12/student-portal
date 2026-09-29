<?php

namespace App\Providers;

use App\Enums\Permission;
use App\Models\AcademicPeriod;
use App\Models\Candidate;
use App\Models\ClassBatch;
use App\Models\ClassSubject;
use App\Models\InstructorAssignment;
use App\Models\Role;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureModels();
        $this->configureAuthorization();
        $this->configurePasswords();

        DB::prohibitDestructiveCommands($this->app->isProduction());
    }

    private function configureModels(): void
    {
        // Surface lazy loading, silently discarded attributes, and missing
        // attributes during development instead of failing quietly.
        Model::shouldBeStrict(! $this->app->isProduction());

        // Store short aliases instead of class names in polymorphic columns
        // (for example audit_logs.auditable_type).
        Relation::enforceMorphMap([
            'user' => User::class,
            'role' => Role::class,
            'academic_period' => AcademicPeriod::class,
            'subject' => Subject::class,
            'class_batch' => ClassBatch::class,
            'class_subject' => ClassSubject::class,
            'instructor_assignment' => InstructorAssignment::class,
            'candidate' => Candidate::class,
        ]);
    }

    /**
     * Every permission becomes a Gate ability, usable in `can:` middleware,
     * `$user->can()`, and policies.
     */
    private function configureAuthorization(): void
    {
        foreach (Permission::cases() as $permission) {
            Gate::define($permission->value, fn (User $user): bool => $user->hasPermission($permission));
        }
    }

    /**
     * Length-based password policy. Breach checks against external services
     * are intentionally not used because internet access is not assumed.
     */
    private function configurePasswords(): void
    {
        Password::defaults(fn (): Password => Password::min(10)->max(128));
    }
}
