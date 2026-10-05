<?php

use App\Http\Middleware\AddSecurityHeaders;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsureInstitutionWide;
use App\Http\Middleware\EnsurePasswordIsCurrent;
use App\Http\Middleware\EnsureRecordsInCampus;
use App\Http\Middleware\EnsureTwoFactorIsEnabled;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ReplaceInvalidUtf8;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Inertia\ExceptionResponse;
use Inertia\Inertia;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(ReplaceInvalidUtf8::class);

        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddSecurityHeaders::class,
        ]);

        $middleware->alias([
            'active' => EnsureAccountIsActive::class,
            'password.current' => EnsurePasswordIsCurrent::class,
            'two-factor' => EnsureTwoFactorIsEnabled::class,
            'institution' => EnsureInstitutionWide::class,
            'campus' => EnsureRecordsInCampus::class,
        ]);

        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('home'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        Inertia::handleExceptionsUsing(function (ExceptionResponse $response) {
            $status = $response->statusCode();

            // Expired CSRF token: usually an expired session. Return the user
            // to where they were (or to sign-in) with a clear explanation.
            if ($status === 419) {
                Inertia::flash('toast', [
                    'type' => 'warning',
                    'message' => $response->request->user() !== null
                        ? 'This page expired. Please try again.'
                        : 'Your session has expired. Sign in again to continue.',
                ]);

                return redirect()->back(fallback: route('login'));
            }

            if (in_array($status, [403, 404], true)) {
                return $response->render('errors/error', ['status' => $status])->withSharedData();
            }

            // Server errors keep Laravel's detailed page while debugging.
            // Shared data is skipped because it may depend on the failing
            // component (for example the database).
            if (! config('app.debug') && in_array($status, [500, 503], true)) {
                try {
                    return $response
                        ->render('errors/error', ['status' => $status, 'errors' => (object) []])
                        ->toResponse($response->request);
                } catch (Throwable) {
                    return null;
                }
            }

            return null;
        });
    })->create();
