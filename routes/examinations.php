<?php

use App\Http\Controllers\Staff\ExaminationController;
use App\Http\Controllers\Staff\ExaminationGradebookController;
use App\Http\Controllers\Staff\ExaminationGradingController;
use App\Http\Controllers\Staff\ExaminationItemAnalysisController;
use Illuminate\Support\Facades\Route;

// Routes with {examination} or {attempt} check the policy before validation,
// so another instructor's records are refused outright.
Route::middleware('can:examinations.manage')->group(function () {
    Route::get('examinations/{examination}/gradebook', [ExaminationGradebookController::class, 'show'])->name('examinations.gradebook')->can('view', 'examination');
    Route::post('examinations/{examination}/gradebook', [ExaminationGradebookController::class, 'store'])->name('examinations.gradebook.post')->can('view', 'examination');
    Route::get('examinations', [ExaminationController::class, 'index'])->name('examinations.index');
    Route::get('examinations/create', [ExaminationController::class, 'create'])->name('examinations.create');
    Route::post('examinations', [ExaminationController::class, 'store'])->name('examinations.store');
    Route::get('examinations/{examination}/edit', [ExaminationController::class, 'edit'])->name('examinations.edit')->can('view', 'examination');
    Route::put('examinations/{examination}', [ExaminationController::class, 'update'])->name('examinations.update')->can('view', 'examination');
    Route::put('examinations/{examination}/results', [ExaminationController::class, 'releaseResults'])->name('examinations.results')->can('view', 'examination');
    Route::get('examinations/{examination}', [ExaminationController::class, 'show'])->name('examinations.show')->can('view', 'examination');
    Route::get('examinations/{examination}/questions', [ExaminationController::class, 'questions'])->name('examinations.questions')->can('view', 'examination')->middleware('can:question_bank.manage');
    Route::put('examinations/{examination}/questions', [ExaminationController::class, 'syncQuestions'])->name('examinations.questions.update')->can('view', 'examination');
    Route::post('examinations/{examination}/publish', [ExaminationController::class, 'publish'])->name('examinations.publish')->can('view', 'examination');
    Route::post('examinations/{examination}/archive', [ExaminationController::class, 'archive'])->name('examinations.archive')->can('view', 'examination');
    Route::get('examinations/{examination}/grading', [ExaminationGradingController::class, 'index'])->name('examinations.grading')->can('view', 'examination');
    Route::get('examinations/{examination}/analysis', [ExaminationItemAnalysisController::class, 'show'])->name('examinations.analysis')->can('view', 'examination');
    Route::get('examination-attempts/{attempt}/grading', [ExaminationGradingController::class, 'show'])->name('examination-attempts.grading')->can('grade', 'attempt');
    Route::put('examination-attempts/{attempt}/essay-grade', [ExaminationGradingController::class, 'update'])->name('examination-attempts.essay-grade')->can('grade', 'attempt');
});

/*
 * Quizzes and examinations (Milestone 8). Owned by the examination builder
 * module.
 *
 * Loaded by routes/web.php inside the staff area group, so every route here
 * already requires a signed-in, active account with staff_area.access.
 * URL helpers: resources/js/lib/examination-routes.ts (keep in sync).
 */
