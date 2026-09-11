<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Accounts are created by an admin with a temporary password, so
 * must_change_password starts true. Until it is cleared, the user is held on
 * the password-change screen.
 */
class EnsurePasswordChanged
{
    /**
     * Routes reachable while the password is still temporary — otherwise the
     * user would be redirected in a loop, or unable to log out.
     *
     * @var list<string>
     */
    protected array $except = [
        'password.change',
        'password.change.update',
        'logout',
        'verification.notice',
        'verification.verify',
        'verification.send',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->must_change_password && ! $this->isExcepted($request)) {
            if ($request->expectsJson()) {
                abort(403, 'You must change your password before continuing.');
            }

            return redirect()->route('password.change')
                ->with('status', 'Please choose a new password before continuing.');
        }

        return $next($request);
    }

    protected function isExcepted(Request $request): bool
    {
        $name = $request->route()?->getName();

        return $name !== null && in_array($name, $this->except, true);
    }
}
