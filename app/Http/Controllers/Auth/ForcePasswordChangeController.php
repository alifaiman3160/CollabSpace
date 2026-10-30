<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Support\Notifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * The screen EnsurePasswordChanged holds users on until their temporary
 * password is replaced.
 */
class ForcePasswordChangeController extends Controller
{
    public function edit(Request $request): View|RedirectResponse
    {
        if (! $request->user()->must_change_password) {
            return redirect()->route('dashboard');
        }

        return view('auth.change-password');
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user = $request->user();

        $user->forceFill([
            'password' => $validated['password'],
            'must_change_password' => false,
        ])->save();

        // The handover is complete: the administrator who created this account
        // handed over a temporary password out of band and has had no way of
        // knowing whether it ever arrived. This is the only notification in the
        // system an administrator reliably receives, because it is the only
        // account event they do not perform themselves.
        app(Notifier::class)->accountActivated($user);

        return redirect()->route('dashboard')
            ->with('status', 'Your password has been updated.');
    }
}
