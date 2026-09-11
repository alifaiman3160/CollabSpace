<?php

use App\Http\Middleware\EnsureAccountActive;
use App\Http\Middleware\EnsurePasswordChanged;
use App\Http\Middleware\RoleMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Runs on every web request, so a session that was already open when
        // the account was deactivated is ended on its very next request — not
        // only on the routes that happen to carry a role guard.
        $middleware->appendToGroup('web', EnsureAccountActive::class);

        $middleware->alias([
            'role' => RoleMiddleware::class,
            'password.changed' => EnsurePasswordChanged::class,
            'account.active' => EnsureAccountActive::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
