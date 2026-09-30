<?php

/*
 * Question bank (Milestone 7). Owned by the question bank module.
 *
 * Loaded by routes/web.php inside the staff area group, so every route here
 * already requires a signed-in, active account with staff_area.access.
 * URL helpers: resources/js/lib/question-bank-routes.ts (keep in sync).
 *
 * Every route also requires question_bank.manage, and QuestionPolicy limits
 * each question to staff who teach its subject.
 */

use App\Enums\Permission;
use App\Http\Controllers\Staff\QuestionBankController;
use App\Models\Question;
use Illuminate\Support\Facades\Route;

Route::middleware('can:'.Permission::ManageQuestionBank->value)
    ->prefix('question-bank')
    ->name('question-bank.')
    ->controller(QuestionBankController::class)
    ->group(function (): void {
        Route::get('/', 'index')->name('index')->can('viewAny', Question::class);
        Route::get('create', 'create')->name('create')->can('create', Question::class);
        Route::post('/', 'store')->name('store')->can('create', Question::class);

        Route::get('{question}', 'show')->name('show')->whereNumber('question')->can('view', 'question');
        Route::get('{question}/edit', 'edit')->name('edit')->whereNumber('question')->can('update', 'question');
        Route::put('{question}', 'update')->name('update')->whereNumber('question')->can('update', 'question');
        Route::post('{question}/activate', 'activate')->name('activate')->whereNumber('question')->can('activate', 'question');
        Route::post('{question}/deactivate', 'deactivate')->name('deactivate')->whereNumber('question')->can('deactivate', 'question');
        Route::post('{question}/duplicate', 'duplicate')->name('duplicate')->whereNumber('question')->can('duplicate', 'question');
    });
