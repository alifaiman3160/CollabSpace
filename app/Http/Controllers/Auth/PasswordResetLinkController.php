<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PasswordResetLinkController extends Controller
{
    /**
     * Display the password reset link request view.
     */
    public function create(): View
    {
        return view('auth.forgot-password');
    }

    /**
     * Handle an incoming password reset link request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        // A deactivated account must not be able to let itself back in through
        // the side door. Password::sendResetLink() knows nothing about
        // is_active — it finds the user, issues a token and emails it — so
        // without this check, deactivation would stop somebody signing in while
        // still handing them a working way to change the password and try
        // again. Checked here rather than in the mail, because the token would
        // already exist by then and remain usable.
        if ($this->isDeactivated($request->string('email')->value())) {
            return back()
                ->withInput($request->only('email'))
                ->withErrors(['email' => 'This account has been deactivated. Ask your administrator to reactivate it.']);
        }

        // We will send the password reset link to this user. Once we have attempted
        // to send the link, we will examine the response then see the message we
        // need to show to the user. Finally, we'll send out a proper response.
        //
        // Wrapped, because Password::sendResetLink() lets a transport failure
        // through: a wrong SMTP password turns "I forgot mine" into a 500 page,
        // which tells the person nothing and tells whoever runs the system even
        // less. Reported rather than swallowed — claiming the link was sent
        // when it was not is the one answer that would waste their time.
        try {
            $status = Password::sendResetLink(
                $request->only('email')
            );
        } catch (\Throwable $e) {
            report($e);

            return back()
                ->withInput($request->only('email'))
                ->withErrors(['email' => 'The reset link could not be sent. Please tell your administrator, and try again shortly.']);
        }

        return $status == Password::RESET_LINK_SENT
                    ? back()->with('status', __($status))
                    : back()->withInput($request->only('email'))
                        ->withErrors(['email' => __($status)]);
    }

    /**
     * Whether this address belongs to an account that has been switched off.
     *
     * An address with no account at all is not deactivated — it falls through
     * to Password::sendResetLink(), which answers for it the same way it always
     * has. Saying "deactivated" here for an unknown address would tell an
     * outsider which addresses exist.
     */
    protected function isDeactivated(string $email): bool
    {
        $user = User::where('email', $email)->first();

        return $user !== null && ! $user->is_active;
    }
}
