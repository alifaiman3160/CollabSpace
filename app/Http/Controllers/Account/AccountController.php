<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * "My account" — replaces Breeze's stock profile screens for all three roles.
 *
 * Role and matriculation number are deliberately read-only: accounts are
 * administered by the Administrator, and letting someone re-role themselves
 * here would be a privilege-escalation route straight past RoleMiddleware.
 * Account deletion is not offered either — records are kept for grading, and
 * deactivation is the administrator's tool.
 */
class AccountController extends Controller
{
    public function edit(): View
    {
        return view('account.edit', ['user' => auth()->user()]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
        ]);

        $user->fill($validated);

        // Changing the address invalidates the previous verification.
        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        return back()->with('status', 'Your details have been updated.');
    }

    public function password(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ], [
            'current_password.current_password' => 'That is not your current password.',
        ]);

        $request->user()->forceFill([
            'password' => $validated['password'],
            // Clears any temporary password the administrator issued.
            'must_change_password' => false,
        ])->save();

        return back()->with('status', 'Your password has been changed.');
    }
}
