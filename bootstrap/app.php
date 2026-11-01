<?php

use App\Http\Middleware\EnsureAccountActive;
use App\Http\Middleware\EnsurePasswordChanged;
use App\Http\Middleware\RoleMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

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

        // title="…" is sent as data-tooltip="…", so the browser's own tooltip never shows (the app draws its own).
        $middleware->appendToGroup('web', \App\Http\Middleware\UpgradeTitleAttributes::class);

        // The sidebar's two preferences are written by the page script, so they are plain (not encrypted) cookies.
        $middleware->encryptCookies(except: [\App\Support\SidebarState::COLLAPSE_COOKIE, \App\Support\SidebarState::OPEN_COOKIE]);

        $middleware->alias([
            'role' => RoleMiddleware::class,
            'password.changed' => EnsurePasswordChanged::class,
            'account.active' => EnsureAccountActive::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        /*
        | "Page expired" — a CSRF token that no longer matches the session.
        |
        | Laravel's stock answer is a dead-end error page reading
        | "419 | PAGE EXPIRED", with no way forward but knowing to clear your
        | cookies. It is reached more often than it should be: anything that
        | ends the session behind an open tab produces it, and
        | `migrate:fresh --seed` does exactly that by dropping the sessions
        | table while browsers still hold their cookies. Someone who had been
        | signed in is then unable to sign in again, because the login form in
        | front of them is itself carrying the stale token.
        |
        | Bouncing them back to the form fixes it on its own: the fresh page
        | carries a valid token, so the retry works. What they typed is handed
        | back with it, apart from the password, which is never put back into
        | a response.
        */
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            // Typed against the HTTP exception rather than TokenMismatchException,
            // because Laravel rewrites the latter into a 419 HttpException before
            // it consults these callbacks — a callback typed against the original
            // is simply never reached. Returning null hands every other status
            // back to the framework untouched.
            if ($e->getStatusCode() !== 419) {
                return null;
            }

            // Livewire and other XHR callers handle a 419 themselves — Livewire
            // reloads the page, which is the right move and gets the same
            // fresh token. A redirect here would be swallowed as a fragment.
            if ($request->expectsJson() || $request->hasHeader('X-Livewire')) {
                return response()->json([
                    'message' => 'Your session expired. Reload the page and try again.',
                ], 419);
            }

            $back = url()->previous();

            // Never bounce back to the endpoint that just rejected us, or the
            // retry lands straight back here.
            if ($back === $request->fullUrl() || ! str_starts_with($back, config('app.url'))) {
                $back = route('login');
            }

            return redirect()->to($back)
                ->withInput($request->except(['password', 'password_confirmation', '_token']))
                ->with('status', 'Your session expired before that was submitted. Nothing was saved — please try again.');
        });
    })->create();
