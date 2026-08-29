<?php

use App\Http\Middleware\EnsureAdminRole;
use App\Http\Middleware\EnsureProfessorRole;
use App\Http\Middleware\EnsureRegistrarOrAdminRole;
use App\Http\Middleware\EnsureRegistrarStaffRole;
use App\Http\Middleware\EnsureStudentRole;
use App\Http\Middleware\RequireStepUpAuthentication;
use App\Http\Middleware\EnsureMonitoringAccess;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role.admin' => EnsureAdminRole::class,
            'role.registrar-staff' => EnsureRegistrarStaffRole::class,
            'role.professor' => EnsureProfessorRole::class,
            'role.student' => EnsureStudentRole::class,
            'role.registrar-or-admin' => EnsureRegistrarOrAdminRole::class,
            'step-up' => RequireStepUpAuthentication::class,
            'role.monitoring' => EnsureMonitoringAccess::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        $exceptions->render(function (AuthenticationException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'success' => false,
                'message' => $exception->getMessage() ?: 'Unauthenticated.',
            ], 401);
        });

        $exceptions->render(function (ValidationException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $exception->errors(),
            ], $exception->status);
        });
    })->create();
