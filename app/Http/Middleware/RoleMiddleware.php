<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates a route to one or more roles.
 *
 *   Route::get(...)->middleware('role:admin');
 *   Route::get(...)->middleware('role:admin,lecturer');
 *
 * Deactivated accounts are not this class's problem any more: that check used
 * to live here, which meant it only ran on role-guarded routes. It is now in
 * EnsureAccountActive, on the whole web group.
 */
class RoleMiddleware
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->route('login');
        }

        if ($roles !== [] && ! $user->hasRole(...$roles)) {
            abort(403, 'You do not have access to this area.');
        }

        return $next($request);
    }
}
