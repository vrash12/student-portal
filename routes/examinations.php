<?php

use App\Http\Controllers\Staff\ExaminationController;
use App\Http\Controllers\Staff\ExaminationGradingController;
use Illuminate\Support\Facades\Route;

Route::middleware('can:examinations.manage')->group(function () {
    Route::get('examinations', [ExaminationController::class, 'index'])->name('examinations.index');
    Route::get('examinations/create', [ExaminationController::class, 'create'])->name('examinations.create');
    Route::post('examinations', [ExaminationController::class, 'store'])->name('examinations.store');
    Route::get('examinations/{examination}/edit', [ExaminationController::class, 'edit'])->name('examinations.edit');
    Route::put('examinations/{examination}', [ExaminationController::class, 'update'])->name('examinations.update');
    Route::put('examinations/{examination}/results', [ExaminationController::class, 'releaseResults'])->name('examinations.results');
    Route::get('examinations/{examination}', [ExaminationController::class, 'show'])->name('examinations.show');
    Route::get('examinations/{examination}/questions', [ExaminationController::class, 'questions'])->name('examinations.questions');
    Route::put('examinations/{examination}/questions', [ExaminationController::class, 'syncQuestions'])->name('examinations.questions.update');
    Route::post('examinations/{examination}/publish', [ExaminationController::class, 'publish'])->name('examinations.publish');
    Route::post('examinations/{examination}/archive', [ExaminationController::class, 'archive'])->name('examinations.archive');
    Route::get('examinations/{examination}/grading', [ExaminationGradingController::class, 'index'])->name('examinations.grading');
    Route::get('examination-attempts/{attempt}/grading', [ExaminationGradingController::class, 'show'])->name('examination-attempts.grading');
    Route::put('examination-attempts/{attempt}/essay-grade', [ExaminationGradingController::class, 'update'])->name('examination-attempts.essay-grade');
});

/*
 * Quizzes and examinations (Milestone 8). Owned by the examination builder
 * module.
 *
 * Loaded by routes/web.php inside the staff area group, so every route here
 * already requires a signed-in, active account with staff_area.access.
 * URL helpers: resources/js/lib/examination-routes.ts (keep in sync).
 */
