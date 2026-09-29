<?php

use App\Enums\Permission;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\Portal\PortalHomeController;
use App\Http\Controllers\Staff\AcademicPeriodController;
use App\Http\Controllers\Staff\AccountPasswordController;
use App\Http\Controllers\Staff\CandidateController;
use App\Http\Controllers\Staff\ClassBatchController;
use App\Http\Controllers\Staff\ClassSubjectController;
use App\Http\Controllers\Staff\DashboardController;
use App\Http\Controllers\Staff\InstructorAssignmentController;
use App\Http\Controllers\Staff\InstructorController;
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
    Route::middleware('can:'.Permission::AccessStaffArea->value)->group(function (): void {
        Route::get('dashboard', DashboardController::class)->name('dashboard');

        Route::get('users', [UserController::class, 'index'])->name('users.index')->can('viewAny', User::class);
        Route::get('users/create', [UserController::class, 'create'])->name('users.create')->can('create', User::class);
        Route::post('users', [UserController::class, 'store'])->name('users.store')->can('create', User::class);
        Route::get('users/{user}/edit', [UserController::class, 'edit'])->name('users.edit')->can('update', 'user');
        Route::put('users/{user}', [UserController::class, 'update'])->name('users.update')->can('update', 'user');

        Route::get('roles', [RoleController::class, 'index'])->name('roles.index')->can(Permission::ViewRoles->value);

        Route::get('account/password', [AccountPasswordController::class, 'edit'])->name('account.password.edit');
        Route::put('account/password', [AccountPasswordController::class, 'update'])->name('account.password.update');

        // Academic structure.
        Route::middleware('can:'.Permission::ManageAcademicPeriods->value)->group(function (): void {
            Route::get('academic-periods', [AcademicPeriodController::class, 'index'])->name('academic-periods.index');
            Route::get('academic-periods/create', [AcademicPeriodController::class, 'create'])->name('academic-periods.create');
            Route::post('academic-periods', [AcademicPeriodController::class, 'store'])->name('academic-periods.store');
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

        // Teaching staff: read-only view of the classes they teach.
        Route::middleware('can:'.Permission::TeachClasses->value)
            ->prefix('my-classes')
            ->name('teaching.classes.')
            ->group(function (): void {
                Route::get('/', [TeachingClassController::class, 'index'])->name('index');
                Route::get('{classBatch}', [TeachingClassController::class, 'show'])->name('show')->can('viewTeaching', 'classBatch');
            });

        // Candidates.
        Route::get('candidates', [CandidateController::class, 'index'])->name('candidates.index')->can('viewAny', Candidate::class);
        Route::get('candidates/create', [CandidateController::class, 'create'])->name('candidates.create')->can('create', Candidate::class);
        Route::post('candidates', [CandidateController::class, 'store'])->name('candidates.store')->can('create', Candidate::class);
        Route::get('candidates/{candidate}', [CandidateController::class, 'show'])->name('candidates.show')->can('view', 'candidate');
        Route::get('candidates/{candidate}/edit', [CandidateController::class, 'edit'])->name('candidates.edit')->can('update', 'candidate');
        Route::put('candidates/{candidate}', [CandidateController::class, 'update'])->name('candidates.update')->can('update', 'candidate');
    });

    // Candidate examination portal.
    Route::middleware('can:'.Permission::AccessExamPortal->value)
        ->prefix('portal')
        ->name('portal.')
        ->group(function (): void {
            Route::get('/', PortalHomeController::class)->name('home');
        });
});

// Unknown URLs still pass through the web middleware (session, shared data),
// so the not-found page renders inside the shell the user normally sees.
Route::fallback(fn () => abort(404));
