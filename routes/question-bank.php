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
use App\Http\Controllers\Staff\QuestionImportController;
use App\Http\Controllers\Staff\QuestionMediaController;
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

// Importing questions from a CSV file into a subject the user teaches
// (checked by QuestionImportRequest).
Route::middleware('can:'.Permission::ManageQuestionBank->value)
    ->prefix('question-bank/import')
    ->name('question-bank.import.')
    ->controller(QuestionImportController::class)
    ->group(function (): void {
        Route::get('/', 'create')->name('create')->can('create', Question::class);
        Route::post('/', 'store')->name('store')->can('create', Question::class);
        Route::get('template', 'template')->name('template')->can('create', Question::class);
    });

// Images, audio, and video of a question (changes follow question edits).
Route::middleware('can:'.Permission::ManageQuestionBank->value)
    ->prefix('question-bank/{question}/media')
    ->name('question-bank.media.')
    ->controller(QuestionMediaController::class)
    ->whereNumber(['question', 'medium'])
    ->scopeBindings()
    ->group(function (): void {
        Route::post('/', 'store')->name('store')->can('update', 'question');
        Route::put('{medium}', 'update')->name('update')->can('update', 'question');
        Route::delete('{medium}', 'destroy')->name('destroy')->can('update', 'question');
    });

// Viewing media: staff who teach the question's subject (checked in the controller).
Route::get('question-media/{medium}', [QuestionMediaController::class, 'show'])->name('question-media.show')->whereNumber('medium');
