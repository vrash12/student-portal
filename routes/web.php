<?php

use App\Enums\Permission;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\CandidatePdfController;
use App\Http\Controllers\CandidatePhotoController;
use App\Http\Controllers\CandidateQrController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\Portal\CandidateProfileController;
use App\Http\Controllers\Portal\ExaminationController as PortalExaminationController;
use App\Http\Controllers\Portal\ExaminationListController;
use App\Http\Controllers\Portal\FitnessController as PortalFitnessController;
use App\Http\Controllers\Portal\GradesController;
use App\Http\Controllers\Portal\MedicalController as PortalMedicalController;
use App\Http\Controllers\Portal\PerformanceController as PortalPerformanceController;
use App\Http\Controllers\Portal\PortalHomeController;
use App\Http\Controllers\Staff\AcademicMonitoringController;
use App\Http\Controllers\Staff\AcademicPeriodController;
use App\Http\Controllers\Staff\AccountCategoryController;
use App\Http\Controllers\Staff\AccountExpenseController;
use App\Http\Controllers\Staff\AccountPasswordController;
use App\Http\Controllers\Staff\AssessmentController;
use App\Http\Controllers\Staff\AssessmentScoreController;
use App\Http\Controllers\Staff\AttendanceScanController;
use App\Http\Controllers\Staff\AttendanceSessionController;
use App\Http\Controllers\Staff\AuditHistoryController;
use App\Http\Controllers\Staff\BackupController;
use App\Http\Controllers\Staff\CandidateController;
use App\Http\Controllers\Staff\ClassBatchController;
use App\Http\Controllers\Staff\ClassSubjectController;
use App\Http\Controllers\Staff\ConductController;
use App\Http\Controllers\Staff\ConductTypeController;
use App\Http\Controllers\Staff\DashboardController;
use App\Http\Controllers\Staff\FitnessEventController;
use App\Http\Controllers\Staff\FitnessTestController;
use App\Http\Controllers\Staff\GradebookController;
use App\Http\Controllers\Staff\GradeCorrectionController;
use App\Http\Controllers\Staff\GradingSchemeController;
use App\Http\Controllers\Staff\GradingSetupController;
use App\Http\Controllers\Staff\GradingThresholdController;
use App\Http\Controllers\Staff\InstructorAssignmentController;
use App\Http\Controllers\Staff\InstructorController;
use App\Http\Controllers\Staff\MedicalDocumentController;
use App\Http\Controllers\Staff\MedicalDownloadController;
use App\Http\Controllers\Staff\MedicalFieldController;
use App\Http\Controllers\Staff\MedicalRecordController;
use App\Http\Controllers\Staff\PerformanceAreaController;
use App\Http\Controllers\Staff\QualificationController;
use App\Http\Controllers\Staff\ReportController;
use App\Http\Controllers\Staff\SubjectController;
use App\Http\Controllers\Staff\TeachingClassController;
use App\Http\Controllers\Staff\TrainingPhaseController;
use App\Http\Controllers\Staff\UserController;
use App\Models\Candidate;
use App\Models\GradeCorrectionRequest;
use App\Models\User;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function (): void {
    Route::get('login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('login', [AuthenticatedSessionController::class, 'store'])->name('login.attempt');
});

Route::middleware(['auth', 'active'])->group(function (): void {
    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    Route::get('/', HomeController::class)->name('home');

    // The address inside every candidate's QR code: the profile for staff who may see the candidate.
    Route::get('q/{token}', [CandidateQrController::class, 'open'])->name('qr.open')->where('token', '[A-Za-z0-9]{1,64}');

    // Staff area: administrators and instructors.
    Route::middleware(['can:'.Permission::AccessStaffArea->value, 'password.current'])->group(function (): void {
        Route::get('dashboard', DashboardController::class)->name('dashboard');
        Route::get('reports', ReportController::class)->name('reports.index')->can(Permission::ViewReports->value);
        Route::get('reports/pdf', [ReportController::class, 'pdf'])->name('reports.pdf')->can(Permission::ViewReports->value)->middleware('throttle:pdf-downloads');
        Route::get('audit-history', AuditHistoryController::class)->name('audit-history.index')->can(Permission::ViewAuditHistory->value);
        // Encrypted backups of the whole system (BackupController; Admin only).
        Route::middleware('can:'.Permission::ManageBackups->value)->group(function () {
            Route::get('backups', [BackupController::class, 'index'])->name('backups.index');
            Route::post('backups', [BackupController::class, 'store'])->name('backups.store')->middleware('throttle:backup-actions');
            Route::post('backups/verify', [BackupController::class, 'verify'])->name('backups.verify')->middleware('throttle:backup-actions');
            Route::post('backups/{backup}/restore', [BackupController::class, 'restore'])->name('backups.restore')->where('backup', '[0-9]{8}-[0-9]{6}-[a-z0-9-]+')->middleware('throttle:backup-actions');
        });

        // Academic monitoring, scoped by MonitoringScope (all candidates, or the subjects taught).
        Route::get('monitoring', AcademicMonitoringController::class)
            ->name('academic-monitoring.index')
            ->can(Permission::ViewAcademicMonitoring->value);

        // Military fitness: viewing (fitness.view), tests and results (fitness.manage), events and
        // their points (fitness.configure). Tests are scoped by class (FitnessTestPolicy): instructors
        // only see and record the classes they teach. Route-level `can` runs before validation.
        Route::middleware('can:'.Permission::ViewFitness->value)->group(function (): void {
            Route::get('fitness', [FitnessTestController::class, 'index'])->name('fitness.index');
            Route::get('fitness/tests/{fitnessTest}', [FitnessTestController::class, 'show'])->name('fitness.tests.show')->whereNumber('fitnessTest')->can('view', 'fitnessTest');

            Route::middleware('can:'.Permission::ManageFitness->value)->group(function (): void {
                Route::get('fitness/tests/create', [FitnessTestController::class, 'create'])->name('fitness.tests.create');
                Route::post('fitness/tests', [FitnessTestController::class, 'store'])->name('fitness.tests.store');
                Route::get('fitness/tests/{fitnessTest}/edit', [FitnessTestController::class, 'edit'])->name('fitness.tests.edit')->can('manage', 'fitnessTest');
                Route::put('fitness/tests/{fitnessTest}', [FitnessTestController::class, 'update'])->name('fitness.tests.update')->can('manage', 'fitnessTest');
                Route::delete('fitness/tests/{fitnessTest}', [FitnessTestController::class, 'destroy'])->name('fitness.tests.destroy')->can('manage', 'fitnessTest');
                Route::put('fitness/tests/{fitnessTest}/results', [FitnessTestController::class, 'recordResults'])->name('fitness.tests.results')->can('manage', 'fitnessTest');
            });

            Route::middleware('can:'.Permission::ConfigureFitness->value)->group(function (): void {
                Route::get('fitness/standards', [FitnessEventController::class, 'index'])->name('fitness.standards.index');
                Route::get('fitness/standards/create', [FitnessEventController::class, 'create'])->name('fitness.standards.create');
                Route::post('fitness/standards', [FitnessEventController::class, 'store'])->name('fitness.standards.store');
                Route::get('fitness/standards/{fitnessEvent}/edit', [FitnessEventController::class, 'edit'])->name('fitness.standards.edit');
                Route::put('fitness/standards/{fitnessEvent}', [FitnessEventController::class, 'update'])->name('fitness.standards.update');
            });
        });

        // Expenses assigned to candidates, voiding a mistaken charge, and the
        // account categories (accounts.manage). Staff only.
        Route::middleware('can:'.Permission::ManageAccounts->value)->group(function (): void {
            Route::post('account-entries/{accountEntry}/void', [AccountExpenseController::class, 'void'])->name('accounts.entries.void')->whereNumber('accountEntry');

            Route::get('account-expenses', [AccountExpenseController::class, 'index'])->name('accounts.expenses.index');
            Route::get('account-expenses/create', [AccountExpenseController::class, 'create'])->name('accounts.expenses.create');
            Route::post('account-expenses', [AccountExpenseController::class, 'store'])->name('accounts.expenses.store');
            Route::get('account-expenses/{accountExpense}', [AccountExpenseController::class, 'show'])->name('accounts.expenses.show')->whereNumber('accountExpense');
            Route::get('account-expenses/{accountExpense}/edit', [AccountExpenseController::class, 'edit'])->name('accounts.expenses.edit')->whereNumber('accountExpense');
            Route::put('account-expenses/{accountExpense}', [AccountExpenseController::class, 'update'])->name('accounts.expenses.update')->whereNumber('accountExpense');
            Route::post('account-expenses/{accountExpense}/assignments', [AccountExpenseController::class, 'assign'])->name('accounts.expenses.assign')->whereNumber('accountExpense');

            Route::get('account-categories', [AccountCategoryController::class, 'index'])->name('accounts.categories.index');
            Route::get('account-categories/create', [AccountCategoryController::class, 'create'])->name('accounts.categories.create');
            Route::post('account-categories', [AccountCategoryController::class, 'store'])->name('accounts.categories.store');
            Route::get('account-categories/{accountCategory}/edit', [AccountCategoryController::class, 'edit'])->name('accounts.categories.edit')->whereNumber('accountCategory');
            Route::put('account-categories/{accountCategory}', [AccountCategoryController::class, 'update'])->name('accounts.categories.update')->whereNumber('accountCategory');
        });

        // Merits and demerits (conduct.manage; scoped to the classes the user
        // teaches unless they can view all candidates). Types: performance.configure.
        Route::middleware('can:'.Permission::ManageConduct->value)->group(function (): void {
            Route::get('conduct', [ConductController::class, 'index'])->name('conduct.index');
            Route::get('conduct/candidates/{candidate}', [ConductController::class, 'show'])->name('conduct.show')->whereNumber('candidate');
            Route::post('conduct/candidates/{candidate}/entries', [ConductController::class, 'store'])->name('conduct.entries.store')->whereNumber('candidate');
            Route::post('conduct-entries/{conductEntry}/void', [ConductController::class, 'void'])->name('conduct.entries.void')->whereNumber('conductEntry');
        });
        Route::middleware('can:'.Permission::ConfigurePerformance->value)->group(function (): void {
            Route::get('conduct/types', [ConductTypeController::class, 'index'])->name('conduct.types.index');
            Route::get('conduct/types/create', [ConductTypeController::class, 'create'])->name('conduct.types.create');
            Route::post('conduct/types', [ConductTypeController::class, 'store'])->name('conduct.types.store');
            Route::get('conduct/types/{conductType}/edit', [ConductTypeController::class, 'edit'])->name('conduct.types.edit')->whereNumber('conductType');
            Route::put('conduct/types/{conductType}', [ConductTypeController::class, 'update'])->name('conduct.types.update')->whereNumber('conductType');
        });

        // Attendance (attendance.manage; scoped to the classes the user teaches
        // unless they can view all candidates).
        Route::middleware('can:'.Permission::ManageAttendance->value)->group(function (): void {
            Route::get('attendance', [AttendanceSessionController::class, 'index'])->name('attendance.index');
            Route::get('attendance/sessions/create', [AttendanceSessionController::class, 'create'])->name('attendance.sessions.create');
            Route::post('attendance/sessions', [AttendanceSessionController::class, 'store'])->name('attendance.sessions.store');
            Route::get('attendance/sessions/{attendanceSession}', [AttendanceSessionController::class, 'show'])->name('attendance.sessions.show')->whereNumber('attendanceSession');
            Route::get('attendance/sessions/{attendanceSession}/edit', [AttendanceSessionController::class, 'edit'])->name('attendance.sessions.edit')->whereNumber('attendanceSession');
            Route::put('attendance/sessions/{attendanceSession}', [AttendanceSessionController::class, 'update'])->name('attendance.sessions.update')->whereNumber('attendanceSession');
            Route::delete('attendance/sessions/{attendanceSession}', [AttendanceSessionController::class, 'destroy'])->name('attendance.sessions.destroy')->whereNumber('attendanceSession');
            Route::put('attendance/sessions/{attendanceSession}/records', [AttendanceSessionController::class, 'recordAttendance'])->name('attendance.sessions.records')->whereNumber('attendanceSession');
            Route::post('attendance/sessions/{attendanceSession}/scan', AttendanceScanController::class)->name('attendance.sessions.scan')->whereNumber('attendanceSession')->can('manage', 'attendanceSession')->middleware('throttle:attendance-scans');
        });

        // Grade correction requests: instructors file them, administrators approve or reject them (GradeCorrectionRequestPolicy).
        Route::get('grade-corrections', [GradeCorrectionController::class, 'index'])->name('grade-corrections.index')->can('viewAny', GradeCorrectionRequest::class);
        Route::get('grade-corrections/{gradeCorrectionRequest}', [GradeCorrectionController::class, 'show'])->name('grade-corrections.show')->whereNumber('gradeCorrectionRequest')->can('view', 'gradeCorrectionRequest');
        Route::get('grade-corrections/{gradeCorrectionRequest}/pdf', [GradeCorrectionController::class, 'pdf'])->name('grade-corrections.pdf')->whereNumber('gradeCorrectionRequest')->can('view', 'gradeCorrectionRequest')->middleware('throttle:pdf-downloads');
        Route::post('grade-corrections/{gradeCorrectionRequest}/approve', [GradeCorrectionController::class, 'approve'])->name('grade-corrections.approve')->whereNumber('gradeCorrectionRequest')->can('decide', 'gradeCorrectionRequest');
        Route::post('grade-corrections/{gradeCorrectionRequest}/reject', [GradeCorrectionController::class, 'reject'])->name('grade-corrections.reject')->whereNumber('gradeCorrectionRequest')->can('decide', 'gradeCorrectionRequest');
        Route::post('grade-corrections/{gradeCorrectionRequest}/cancel', [GradeCorrectionController::class, 'cancel'])->name('grade-corrections.cancel')->whereNumber('gradeCorrectionRequest')->can('cancel', 'gradeCorrectionRequest');

        // Performance areas and qualification: ranking of every candidate
        // (performance.view); configuration (performance.configure).
        // Candidate medical records (owner request, 2026-10-02): administrators define the
        // fields (medical.configure), view (medical.view) and record (medical.manage) them.
        Route::get('medical-records', [MedicalRecordController::class, 'index'])->name('medical.records.index')->can(Permission::ViewMedical->value);
        Route::get('medical-records/{candidate}/edit', [MedicalRecordController::class, 'edit'])->name('medical.records.edit')->whereNumber('candidate')->can('manageMedical', 'candidate');
        Route::put('medical-records/{candidate}', [MedicalRecordController::class, 'update'])->name('medical.records.update')->whereNumber('candidate')->can('manageMedical', 'candidate');
        // Medical documents candidates upload (MedicalDocumentController): the review queue and
        // accept/return (medical.manage), files for medical staff (medical.view), and the
        // protected, view-only feed for instructors with approved access.
        Route::get('medical-records/documents', [MedicalDocumentController::class, 'index'])->name('medical.documents.index')->can(Permission::ManageMedical->value);
        Route::get('medical-documents/{medicalDocument}/file', [MedicalDocumentController::class, 'file'])->name('medical.documents.file')->whereNumber('medicalDocument')->can('view', 'medicalDocument');
        Route::get('medical-documents/{medicalDocument}/protected', [MedicalDocumentController::class, 'protected'])->name('medical.documents.protected')->whereNumber('medicalDocument')->can('viewProtected', 'medicalDocument');
        Route::post('medical-documents/{medicalDocument}/accept', [MedicalDocumentController::class, 'accept'])->name('medical.documents.accept')->whereNumber('medicalDocument')->can('review', 'medicalDocument');
        Route::post('medical-documents/{medicalDocument}/print-screen', [MedicalDocumentController::class, 'printScreen'])->name('medical.documents.print-screen')->whereNumber('medicalDocument')->can('viewProtected', 'medicalDocument')->middleware('throttle:medical-screen-events');
        // Instructors' requests to download a document they may view (MedicalDownloadController).
        Route::get('medical-records/download-requests', [MedicalDownloadController::class, 'index'])->name('medical.downloads.index')->can(Permission::ManageMedical->value);
        Route::post('medical-documents/{medicalDocument}/download-requests', [MedicalDownloadController::class, 'store'])->name('medical.downloads.store')->whereNumber('medicalDocument')->can('requestDownload', 'medicalDocument');
        Route::post('medical-download-requests/{medicalDownloadRequest}/cancel', [MedicalDownloadController::class, 'cancel'])->name('medical.downloads.cancel')->whereNumber('medicalDownloadRequest')->can('cancel', 'medicalDownloadRequest');
        Route::post('medical-download-requests/{medicalDownloadRequest}/approve', [MedicalDownloadController::class, 'approve'])->name('medical.downloads.approve')->whereNumber('medicalDownloadRequest')->can('decide', 'medicalDownloadRequest');
        Route::post('medical-download-requests/{medicalDownloadRequest}/reject', [MedicalDownloadController::class, 'reject'])->name('medical.downloads.reject')->whereNumber('medicalDownloadRequest')->can('decide', 'medicalDownloadRequest');
        Route::post('medical-download-requests/{medicalDownloadRequest}/revoke', [MedicalDownloadController::class, 'revoke'])->name('medical.downloads.revoke')->whereNumber('medicalDownloadRequest')->can('revoke', 'medicalDownloadRequest');
        Route::get('medical-download-requests/{medicalDownloadRequest}/file', [MedicalDownloadController::class, 'file'])->name('medical.downloads.file')->whereNumber('medicalDownloadRequest')->can('download', 'medicalDownloadRequest')->middleware('throttle:record-downloads');
        Route::post('medical-documents/{medicalDocument}/return', [MedicalDocumentController::class, 'return'])->name('medical.documents.return')->whereNumber('medicalDocument')->can('review', 'medicalDocument');
        Route::middleware('can:'.Permission::ConfigureMedical->value)->group(function (): void {
            Route::get('medical-records/fields', [MedicalFieldController::class, 'index'])->name('medical.fields.index');
            Route::get('medical-records/fields/create', [MedicalFieldController::class, 'create'])->name('medical.fields.create');
            Route::post('medical-records/fields', [MedicalFieldController::class, 'store'])->name('medical.fields.store');
            Route::get('medical-records/fields/{medicalField}/edit', [MedicalFieldController::class, 'edit'])->name('medical.fields.edit')->whereNumber('medicalField');
            Route::put('medical-records/fields/{medicalField}', [MedicalFieldController::class, 'update'])->name('medical.fields.update')->whereNumber('medicalField');
            Route::delete('medical-records/fields/{medicalField}', [MedicalFieldController::class, 'destroy'])->name('medical.fields.destroy')->whereNumber('medicalField');
        });

        Route::get('qualification', [QualificationController::class, 'index'])->name('qualification.index')->can(Permission::ViewPerformance->value);
        Route::get('qualification/pdf', [QualificationController::class, 'pdf'])->name('qualification.pdf')->can(Permission::ViewPerformance->value)->middleware('throttle:pdf-downloads');
        Route::middleware('can:'.Permission::ConfigurePerformance->value)->group(function (): void {
            Route::get('performance-areas', [PerformanceAreaController::class, 'index'])->name('performance-areas.index');
            Route::get('performance-areas/create', [PerformanceAreaController::class, 'create'])->name('performance-areas.create');
            Route::post('performance-areas', [PerformanceAreaController::class, 'store'])->name('performance-areas.store');
            Route::get('performance-areas/{performanceArea}/edit', [PerformanceAreaController::class, 'edit'])->name('performance-areas.edit')->whereNumber('performanceArea');
            Route::put('performance-areas/{performanceArea}', [PerformanceAreaController::class, 'update'])->name('performance-areas.update')->whereNumber('performanceArea');
        });

        Route::get('users', [UserController::class, 'index'])->name('users.index')->can('viewAny', User::class);
        Route::get('users/create', [UserController::class, 'create'])->name('users.create')->can('create', User::class);
        Route::post('users', [UserController::class, 'store'])->name('users.store')->can('create', User::class);
        Route::get('users/{user}/edit', [UserController::class, 'edit'])->name('users.edit')->can('update', 'user');
        Route::put('users/{user}', [UserController::class, 'update'])->name('users.update')->can('update', 'user');

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
            // Training phases of the course; subjects are placed in a phase on their class's page.
            Route::get('training-phases', [TrainingPhaseController::class, 'index'])->name('training-phases.index');
            Route::post('training-phases', [TrainingPhaseController::class, 'store'])->name('training-phases.store');
            Route::put('training-phases/{trainingPhase}', [TrainingPhaseController::class, 'update'])->name('training-phases.update')->whereNumber('trainingPhase');
            Route::delete('training-phases/{trainingPhase}', [TrainingPhaseController::class, 'destroy'])->name('training-phases.destroy')->whereNumber('trainingPhase');
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
            Route::put('classes/{classBatch}/subjects/{classSubject}', [ClassSubjectController::class, 'update'])
                ->name('classes.subjects.update')
                ->scopeBindings();
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

        // Passing and warning grades of an academic period, and the Grading
        // Setup overview with "Copy Weights" (administrative).
        Route::middleware('can:'.Permission::ConfigureGrading->value)->group(function (): void {
            Route::get('grading-setup', [GradingSetupController::class, 'index'])->name('grading-setup.index');
            Route::post('grading-setup/copy-weights', [GradingSetupController::class, 'copy'])->name('grading-setup.copy');
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
                // Finalized scores change only through an approved correction request (owner request, 2026-10-02).
                Route::post('assessments/{assessment}/correction-requests', [GradeCorrectionController::class, 'store'])->name('assessments.correction-requests.store');
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
        Route::get('candidates/{candidate}/qr', [CandidateQrController::class, 'show'])->name('candidates.qr')->can('view', 'candidate');
        Route::get('candidates/{candidate}/qr/pdf', [CandidateQrController::class, 'pdf'])->name('candidates.qr.pdf')->can('view', 'candidate')->middleware('throttle:pdf-downloads');
        Route::post('candidates/{candidate}/qr', [CandidateQrController::class, 'reissue'])->name('candidates.qr.reissue')->can('update', 'candidate');
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
            Route::get('examinations', ExaminationListController::class)->name('examinations.index');
            Route::get('grades', GradesController::class)->name('grades');
            Route::get('fitness', PortalFitnessController::class)->name('fitness');
            Route::get('profile', CandidateProfileController::class)->name('profile');
            Route::get('profile/photo', [CandidatePhotoController::class, 'own'])->name('profile.photo');
            Route::get('profile/qr', [CandidateQrController::class, 'own'])->name('profile.qr');
            Route::get('profile/qr/pdf', [CandidateQrController::class, 'ownPdf'])->name('profile.qr.pdf')->middleware('throttle:pdf-downloads');
            // The candidate's own medical documents (uploads, review, withdraw) and shared record fields.
            Route::get('medical', [PortalMedicalController::class, 'show'])->name('medical');
            Route::post('medical/documents', [PortalMedicalController::class, 'store'])->name('medical.documents.store')->middleware('throttle:medical-uploads');
            Route::delete('medical/documents/{medicalDocument}', [PortalMedicalController::class, 'destroy'])->name('medical.documents.destroy')->whereNumber('medicalDocument')->can('withdraw', 'medicalDocument');
            Route::get('medical/documents/{medicalDocument}/file', [PortalMedicalController::class, 'file'])->name('medical.documents.file')->whereNumber('medicalDocument')->can('view', 'medicalDocument');
            // The candidate's own areas, qualification, merits/demerits and attendance (no rank).
            Route::get('performance', [PortalPerformanceController::class, 'show'])->name('performance');
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
