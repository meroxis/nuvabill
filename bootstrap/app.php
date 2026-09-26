<?php

use App\Http\Controllers\WebhookController;
use App\Http\Middleware\EnsureAdminPermission;
use App\Http\Middleware\EnsureClientIsActive;
use App\Http\Middleware\PreventRequestsDuringMaintenance;
use App\Http\Middleware\RedirectToInstaller;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::middleware('web')
                ->prefix(config('nuvabill.admin_path'))
                ->name('admin.')
                ->group(base_path('routes/admin.php'));

            Route::middleware('web')
                ->prefix('install')
                ->name('install.')
                ->group(base_path('routes/install.php'));

            Route::post('webhooks/{gateway}', WebhookController::class)
                ->where('gateway', '[a-z0-9_-]+')
                ->name('webhooks.gateway');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [RedirectToInstaller::class]);

        $middleware->replace(
            Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance::class,
            PreventRequestsDuringMaintenance::class,
        );

        $middleware->alias([
            'admin.can' => EnsureAdminPermission::class,
            'client.active' => EnsureClientIsActive::class,
        ]);

        $middleware->redirectGuestsTo(fn (Request $request): string => $request->routeIs('admin.*')
            ? route('admin.login')
            : route('client.login'));

        $middleware->redirectUsersTo(fn (Request $request): string => $request->routeIs('admin.*')
            ? route('admin.dashboard')
            : route('client.dashboard'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
