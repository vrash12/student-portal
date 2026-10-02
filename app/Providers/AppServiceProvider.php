<?php

namespace App\Providers;

use App\Enums\Permission;
use App\Models\AcademicPeriod;
use App\Models\AccountCategory;
use App\Models\AccountEntry;
use App\Models\AccountExpense;
use App\Models\Assessment;
use App\Models\AssessmentCategory;
use App\Models\AssessmentScore;
use App\Models\AttendanceSession;
use App\Models\Candidate;
use App\Models\ClassBatch;
use App\Models\ClassSubject;
use App\Models\ConductEntry;
use App\Models\ConductType;
use App\Models\Examination;
use App\Models\ExaminationAttempt;
use App\Models\FitnessEvent;
use App\Models\FitnessTest;
use App\Models\GradeCorrectionRequest;
use App\Models\InstructorAssignment;
use App\Models\MedicalAccessRequest;
use App\Models\MedicalField;
use App\Models\PerformanceArea;
use App\Models\Question;
use App\Models\Role;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
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
        $this->configureRateLimiting();

        DB::prohibitDestructiveCommands($this->app->isProduction());
    }

    /**
     * Named limiters, each with its own counter. (Plain throttle:N,M
     * middleware keys only on the user, so every such route would share one
     * counter.) Limits are far above normal use on a tablet.
     */
    private function configureRateLimiting(): void
    {
        $perUser = fn (string $name, int $perMinute) => RateLimiter::for($name, fn (Request $request): Limit => Limit::perMinute($perMinute)->by($name.'|'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        // Autosave (debounced), heartbeat every 45 seconds, and submission.
        $perUser('exam-writes', 240);
        // Leaving and returning to the examination screen.
        $perUser('exam-focus', 120);
        // Candidate and staff record PDFs.
        $perUser('record-downloads', 10);
        // Question media uploads and question imports.
        $perUser('staff-uploads', 30);
        // Changing one's own password (the current password is checked).
        $perUser('password-change', 6);

        // Starting an examination, per candidate and examination, so access
        // codes cannot be guessed by trying many.
        RateLimiter::for('exam-start', fn (Request $request): Limit => Limit::perMinute(10)->by('exam-start|'.($request->user()?->getAuthIdentifier() ?? $request->ip()).'|'.($request->route('examination') instanceof Model ? $request->route('examination')->getKey() : (string) $request->route('examination'))));
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
            'assessment_category' => AssessmentCategory::class,
            'assessment' => Assessment::class,
            'assessment_score' => AssessmentScore::class,
            'grade_correction_request' => GradeCorrectionRequest::class,
            'medical_field' => MedicalField::class,
            'medical_access_request' => MedicalAccessRequest::class,
            'question' => Question::class,
            // Created by the examination builder (Milestone 8).
            'examination' => Examination::class,
            'examination_attempt' => ExaminationAttempt::class,
            'fitness_event' => FitnessEvent::class,
            'fitness_test' => FitnessTest::class,
            'account_entry' => AccountEntry::class,
            'account_category' => AccountCategory::class,
            'account_expense' => AccountExpense::class,
            'conduct_entry' => ConductEntry::class,
            'conduct_type' => ConductType::class,
            'attendance_session' => AttendanceSession::class,
            'performance_area' => PerformanceArea::class,
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
