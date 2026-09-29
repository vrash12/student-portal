<?php

use App\Enums\Permission;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\Portal\PortalHomeController;
use App\Http\Controllers\Staff\AccountPasswordController;
use App\Http\Controllers\Staff\DashboardController;
use App\Http\Controllers\Staff\RoleController;
use App\Http\Controllers\Staff\UserController;
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
