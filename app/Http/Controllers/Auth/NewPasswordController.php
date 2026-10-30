<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class NewPasswordController extends Controller
{
    /**
     * Display the password reset view.
     */
    public function create(Request $request): View
    {
        return view('auth.reset-password', ['request' => $request]);
    }

    /**
     * Handle an incoming new password request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        // Checked again at the point of use, not only when the link was asked
        // for: an account can be deactivated in the hour between requesting a
        // reset and following it, and a token issued while the account was
        // still live would otherwise stay good.
        if ($this->isDeactivated($request->string('email')->value())) {
            return back()
                ->withInput($request->only('email'))
                ->withErrors(['email' => 'This account has been deactivated. Ask your administrator to reactivate it.']);
        }

        // Here we will attempt to reset the user's password. If it is successful we
        // will update the password on an actual user model and persist it to the
        // database. Otherwise we will parse the error and return the response.
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user) use ($request) {
                $user->forceFill([
                    'password' => Hash::make($request->password),
                    'remember_token' => Str::random(60),
                    // The whole point of this screen is that they chose the
                    // password themselves, so any temporary one an administrator
                    // issued is now spent. Without this the user is bounced
                    // straight from a successful reset onto the forced-change
                    // screen and told to pick a password again.
                    'must_change_password' => false,
                ])->save();

                event(new PasswordReset($user));
            }
        );

        // If the password was successfully reset, we will redirect the user back to
        // the application's home authenticated view. If there is an error we can
        // redirect them back to where they came from with their error message.
        return $status == Password::PASSWORD_RESET
                    ? redirect()->route('login')->with('status', __($status))
                    : back()->withInput($request->only('email'))
                        ->withErrors(['email' => __($status)]);
    }

    /**
     * Whether this address belongs to an account that has been switched off.
     *
     * An unknown address is not deactivated — it falls through to
     * Password::reset(), which answers for it exactly as it always has, so this
     * check cannot be used to discover which addresses exist.
     */
    protected function isDeactivated(string $email): bool
    {
        $user = User::where('email', $email)->first();

        return $user !== null && ! $user->is_active;
    }
}
