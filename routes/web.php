<?php

use App\Enums\Permission;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\CandidatePdfController;
use App\Http\Controllers\CandidatePhotoController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\Portal\CandidateProfileController;
use App\Http\Controllers\Portal\ExaminationController as PortalExaminationController;
use App\Http\Controllers\Portal\PortalHomeController;
use App\Http\Controllers\Staff\AcademicMonitoringController;
use App\Http\Controllers\Staff\AcademicPeriodController;
use App\Http\Controllers\Staff\AccountPasswordController;
use App\Http\Controllers\Staff\AssessmentController;
use App\Http\Controllers\Staff\AssessmentScoreController;
use App\Http\Controllers\Staff\AuditHistoryController;
use App\Http\Controllers\Staff\CandidateController;
use App\Http\Controllers\Staff\ClassBatchController;
use App\Http\Controllers\Staff\ClassSubjectController;
use App\Http\Controllers\Staff\DashboardController;
use App\Http\Controllers\Staff\FitnessEventController;
use App\Http\Controllers\Staff\FitnessTestController;
use App\Http\Controllers\Staff\GradebookController;
use App\Http\Controllers\Staff\GradingSchemeController;
use App\Http\Controllers\Staff\GradingThresholdController;
use App\Http\Controllers\Staff\InstructorAssignmentController;
use App\Http\Controllers\Staff\InstructorController;
use App\Http\Controllers\Staff\ReportController;
use App\Http\Controllers\Staff\RoleController;
use App\Http\Controllers\Staff\SubjectController;
use App\Http\Controllers\Staff\TeachingClassController;
use App\Http\Controllers\Staff\UserController;
use App\Models\Candidate;
use App\Models\User;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function (): void {
    Route::get('login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('login', [AuthenticatedSessionController::class, 'store'])->name('login.attempt');
});

Route::middleware(['auth', 'active'])->group(function (): void {
    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    Route::get('/', HomeController::class)->name('home');

    // Staff area: administrators and instructors.
    Route::middleware(['can:'.Permission::AccessStaffArea->value, 'password.current'])->group(function (): void {
        Route::get('dashboard', DashboardController::class)->name('dashboard');
        Route::get('reports', ReportController::class)->name('reports.index')->can(Permission::ViewReports->value);
        Route::get('audit-history', AuditHistoryController::class)->name('audit-history.index')->can(Permission::ViewAuditHistory->value);

        // Academic monitoring, scoped by MonitoringScope (all candidates, or the subjects taught).
        Route::get('monitoring', AcademicMonitoringController::class)
            ->name('academic-monitoring.index')
            ->can(Permission::ViewAcademicMonitoring->value);

        // Military fitness: viewing (fitness.view), standards, tests and results (fitness.manage).
        Route::middleware('can:'.Permission::ViewFitness->value)->group(function (): void {
            Route::get('fitness', [FitnessTestController::class, 'index'])->name('fitness.index');
            Route::get('fitness/tests/{fitnessTest}', [FitnessTestController::class, 'show'])->name('fitness.tests.show')->whereNumber('fitnessTest');

            Route::middleware('can:'.Permission::ManageFitness->value)->group(function (): void {
                Route::get('fitness/standards', [FitnessEventController::class, 'index'])->name('fitness.standards.index');
                Route::get('fitness/standards/create', [FitnessEventController::class, 'create'])->name('fitness.standards.create');
                Route::post('fitness/standards', [FitnessEventController::class, 'store'])->name('fitness.standards.store');
                Route::get('fitness/standards/{fitnessEvent}/edit', [FitnessEventController::class, 'edit'])->name('fitness.standards.edit');
                Route::put('fitness/standards/{fitnessEvent}', [FitnessEventController::class, 'update'])->name('fitness.standards.update');

                Route::get('fitness/tests/create', [FitnessTestController::class, 'create'])->name('fitness.tests.create');
                Route::post('fitness/tests', [FitnessTestController::class, 'store'])->name('fitness.tests.store');
                Route::get('fitness/tests/{fitnessTest}/edit', [FitnessTestController::class, 'edit'])->name('fitness.tests.edit');
                Route::put('fitness/tests/{fitnessTest}', [FitnessTestController::class, 'update'])->name('fitness.tests.update');
                Route::delete('fitness/tests/{fitnessTest}', [FitnessTestController::class, 'destroy'])->name('fitness.tests.destroy');
                Route::put('fitness/tests/{fitnessTest}/results', [FitnessTestController::class, 'recordResults'])->name('fitness.tests.results');
            });
        });

        Route::get('users', [UserController::class, 'index'])->name('users.index')->can('viewAny', User::class);
        Route::get('users/create', [UserController::class, 'create'])->name('users.create')->can('create', User::class);
        Route::post('users', [UserController::class, 'store'])->name('users.store')->can('create', User::class);
        Route::get('users/{user}/edit', [UserController::class, 'edit'])->name('users.edit')->can('update', 'user');
        Route::put('users/{user}', [UserController::class, 'update'])->name('users.update')->can('update', 'user');

        Route::get('roles', [RoleController::class, 'index'])->name('roles.index')->can(Permission::ViewRoles->value);

        Route::get('account/password', [AccountPasswordController::class, 'edit'])->name('account.password.edit');
        Route::put('account/password', [AccountPasswordController::class, 'update'])->name('account.password.update')->middleware('throttle:password-change');

        // Academic structure.
        Route::middleware('can:'.Permission::ManageAcademicPeriods->value)->group(function (): void {
            Route::get('academic-periods', [AcademicPeriodController::class, 'index'])->name('academic-periods.index');
            Route::get('academic-periods/create', [AcademicPeriodController::class, 'create'])->name('academic-periods.create');
            Route::post('academic-periods', [AcademicPeriodController::class, 'store'])->name('academic-periods.store');
            Route::get('academic-periods/{academicPeriod}', [AcademicPeriodController::class, 'show'])->name('academic-periods.show')->whereNumber('academicPeriod');
            Route::get('academic-periods/{academicPeriod}/edit', [AcademicPeriodController::class, 'edit'])->name('academic-periods.edit');
            Route::put('academic-periods/{academicPeriod}', [AcademicPeriodController::class, 'update'])->name('academic-periods.update');
            Route::post('academic-periods/{academicPeriod}/activate', [AcademicPeriodController::class, 'activate'])->name('academic-periods.activate');
        });

        Route::middleware('can:'.Permission::ManageSubjects->value)->group(function (): void {
            Route::get('subjects', [SubjectController::class, 'index'])->name('subjects.index');
            Route::get('subjects/create', [SubjectController::class, 'create'])->name('subjects.create');
            Route::post('subjects', [SubjectController::class, 'store'])->name('subjects.store');
            Route::get('subjects/{subject}/edit', [SubjectController::class, 'edit'])->name('subjects.edit');
            Route::put('subjects/{subject}', [SubjectController::class, 'update'])->name('subjects.update');
        });

        Route::middleware('can:'.Permission::ManageClassBatches->value)->group(function (): void {
            Route::get('classes', [ClassBatchController::class, 'index'])->name('classes.index');
            Route::get('classes/create', [ClassBatchController::class, 'create'])->name('classes.create');
            Route::post('classes', [ClassBatchController::class, 'store'])->name('classes.store');
            Route::get('classes/{classBatch}', [ClassBatchController::class, 'show'])->name('classes.show');
            Route::get('classes/{classBatch}/edit', [ClassBatchController::class, 'edit'])->name('classes.edit');
            Route::put('classes/{classBatch}', [ClassBatchController::class, 'update'])->name('classes.update');
            Route::post('classes/{classBatch}/subjects', [ClassSubjectController::class, 'store'])->name('classes.subjects.store');
            Route::delete('classes/{classBatch}/subjects/{classSubject}', [ClassSubjectController::class, 'destroy'])
                ->name('classes.subjects.destroy')
                ->scopeBindings();
        });

        Route::middleware('can:'.Permission::ManageInstructorAssignments->value)->group(function (): void {
            Route::get('instructors', [InstructorController::class, 'index'])->name('instructors.index');
            Route::get('instructors/{instructor}', [InstructorController::class, 'show'])->name('instructors.show');
            Route::post('instructor-assignments', [InstructorAssignmentController::class, 'store'])->name('instructor-assignments.store');
            Route::delete('instructor-assignments/{instructorAssignment}', [InstructorAssignmentController::class, 'destroy'])->name('instructor-assignments.destroy');
        });

        // Passing and warning grades of an academic period (administrative).
        Route::middleware('can:'.Permission::ConfigureGrading->value)->group(function (): void {
            Route::get('academic-periods/{academicPeriod}/grading-thresholds', [GradingThresholdController::class, 'edit'])
                ->name('academic-periods.thresholds.edit');
            Route::put('academic-periods/{academicPeriod}/grading-thresholds', [GradingThresholdController::class, 'update'])
                ->name('academic-periods.thresholds.update');
        });

        // Grading setup of a class subject (administrative).
        Route::get('classes/{classBatch}/subjects/{classSubject}/grading', [GradingSchemeController::class, 'edit'])
            ->name('classes.grading.edit')
            ->scopeBindings()
            ->can('configureGrading', 'classSubject');
        Route::put('classes/{classBatch}/subjects/{classSubject}/grading', [GradingSchemeController::class, 'update'])
            ->name('classes.grading.update')
            ->scopeBindings()
            ->can('configureGrading', 'classSubject');

        // Teaching staff: the classes they teach, and grading of their subjects.
        Route::middleware('can:'.Permission::TeachClasses->value)->group(function (): void {
            Route::prefix('my-classes')->name('teaching.')->group(function (): void {
                Route::get('/', [TeachingClassController::class, 'index'])->name('classes.index');
                Route::get('{classBatch}', [TeachingClassController::class, 'show'])->name('classes.show')->can('viewTeaching', 'classBatch');

                Route::get('{classBatch}/subjects/{classSubject}', [GradebookController::class, 'show'])
                    ->name('gradebooks.show')
                    ->scopeBindings()
                    ->can('viewGradebook', 'classSubject');
                Route::get('{classBatch}/subjects/{classSubject}/assessments/create', [AssessmentController::class, 'create'])
                    ->name('assessments.create')
                    ->scopeBindings()
                    ->can('recordGrades', 'classSubject');
                Route::post('{classBatch}/subjects/{classSubject}/assessments', [AssessmentController::class, 'store'])
                    ->name('assessments.store')
                    ->scopeBindings()
                    ->can('recordGrades', 'classSubject');
            });

            Route::get('assessments/{assessment}', [AssessmentController::class, 'show'])->name('assessments.show')->can('view', 'assessment');
            Route::middleware('can:manage,assessment')->group(function (): void {
                Route::get('assessments/{assessment}/edit', [AssessmentController::class, 'edit'])->name('assessments.edit');
                Route::put('assessments/{assessment}', [AssessmentController::class, 'update'])->name('assessments.update');
                Route::delete('assessments/{assessment}', [AssessmentController::class, 'destroy'])->name('assessments.destroy');
                Route::post('assessments/{assessment}/finalize', [AssessmentController::class, 'finalize'])->name('assessments.finalize');
                Route::put('assessments/{assessment}/scores', [AssessmentScoreController::class, 'update'])->name('assessments.scores.update');
                Route::post('assessments/{assessment}/corrections', [AssessmentScoreController::class, 'correct'])->name('assessments.corrections.store');
            });
        });

        // Question bank (Milestone 7), in its own route file.
        require __DIR__.'/question-bank.php';

        // Quizzes and examinations (Milestone 8), in their own route file.
        require __DIR__.'/examinations.php';

        // Candidates.
        Route::get('candidates', [CandidateController::class, 'index'])->name('candidates.index')->can('viewAny', Candidate::class);
        Route::get('candidates/create', [CandidateController::class, 'create'])->name('candidates.create')->can('create', Candidate::class);
        Route::post('candidates', [CandidateController::class, 'store'])->name('candidates.store')->can('create', Candidate::class);
        Route::get('candidates/{candidate}', [CandidateController::class, 'show'])->name('candidates.show')->can('view', 'candidate');
        Route::get('candidates/{candidate}/edit', [CandidateController::class, 'edit'])->name('candidates.edit')->can('update', 'candidate');
        Route::get('candidates/{candidate}/photo', [CandidatePhotoController::class, 'show'])->name('candidates.photo')->can('view', 'candidate');
        Route::get('candidates/{candidate}/documents/{type}', [CandidatePdfController::class, 'show'])
            ->whereIn('type', ['registration', 'academic'])->name('candidates.documents')
            ->can('downloadRecord', 'candidate')->middleware('throttle:record-downloads');
        Route::put('candidates/{candidate}', [CandidateController::class, 'update'])->name('candidates.update')->can('update', 'candidate');
    });

    // Candidate examination portal.
    Route::middleware('can:'.Permission::AccessExamPortal->value)
        ->prefix('portal')
        ->name('portal.')
        ->group(function (): void {
            Route::get('/', PortalHomeController::class)->name('home');
            Route::get('profile', CandidateProfileController::class)->name('profile');
            Route::get('profile/photo', [CandidatePhotoController::class, 'own'])->name('profile.photo');
            Route::get('profile/documents/{type}', [CandidatePdfController::class, 'own'])
                ->whereIn('type', ['registration', 'academic'])->name('profile.documents')->middleware('throttle:record-downloads');
            Route::get('examinations/{examination}', [PortalExaminationController::class, 'show'])->name('examinations.show');
            Route::post('examinations/{examination}/start', [PortalExaminationController::class, 'start'])->name('examinations.start')->middleware('throttle:exam-start');
            Route::get('attempts/{attempt}', [PortalExaminationController::class, 'attempt'])->name('attempts.show')->can('view', 'attempt');
            Route::get('attempts/{attempt}/media/{medium}', [PortalExaminationController::class, 'media'])->name('attempts.media')->can('view', 'attempt')->whereNumber('medium');
            Route::put('attempts/{attempt}/answers', [PortalExaminationController::class, 'save'])->name('attempts.answers')->can('view', 'attempt')->middleware('throttle:exam-writes');
            Route::post('attempts/{attempt}/activity', [PortalExaminationController::class, 'activity'])->name('attempts.activity')->can('view', 'attempt')->middleware('throttle:exam-writes');
            Route::post('attempts/{attempt}/focus', [PortalExaminationController::class, 'focus'])->name('attempts.focus')->can('view', 'attempt')->middleware('throttle:exam-focus');
            Route::post('attempts/{attempt}/submit', [PortalExaminationController::class, 'submit'])->name('attempts.submit')->can('view', 'attempt')->middleware('throttle:exam-writes');
            Route::get('attempts/{attempt}/success', [PortalExaminationController::class, 'success'])->name('attempts.success')->can('view', 'attempt');
        });
});

// Unknown URLs still pass through the web middleware (session, shared data),
// so the not-found page renders inside the shell the user normally sees.
Route::fallback(fn () => abort(404));
