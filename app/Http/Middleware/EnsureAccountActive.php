<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ends the session of an account that has been deactivated.
 *
 * The check at sign-in (LoginRequest) only covers the moment of signing in, and
 * RoleMiddleware only covers the routes that carry a role. That left an already
 * open session alive: an administrator could deactivate someone who was signed
 * in, and that person kept working until they logged out — "My account" and
 * every other route without a role guard stayed reachable.
 *
 * So this runs on every web request instead. A guest passes straight through;
 * a deactivated user is logged out on their very next request, whichever route
 * it is.
 */
class EnsureAccountActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->is_active) {
            auth()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            // Livewire and any other XHR gets a status rather than a redirect
            // body it cannot follow.
            if ($request->expectsJson()) {
                abort(401, 'This account has been deactivated.');
            }

            return redirect()->route('login')
                ->withErrors(['email' => 'This account has been deactivated.']);
        }

        return $next($request);
    }
}
