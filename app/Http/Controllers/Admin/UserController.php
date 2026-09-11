<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Mail\AccountCreated;
use App\Models\User;
use App\Support\TemporaryPassword;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;

class UserController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search', ''));
        $role = $request->query('role');

        $users = User::query()
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('matric_no', 'like', "%{$search}%");
                });
            })
            ->when(in_array($role, ['admin', 'lecturer', 'student'], true),
                fn ($query) => $query->where('role', $role))
            // CASE rather than MySQL's FIELD(), so this also runs on SQLite.
            ->orderByRaw("CASE role WHEN 'admin' THEN 1 WHEN 'lecturer' THEN 2 ELSE 3 END")
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return view('admin.users.index', [
            'users' => $users,
            'search' => $search,
            'role' => $role,
        ]);
    }

    public function create(): View
    {
        return view('admin.users.create');
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        $temporary = TemporaryPassword::generate();

        $user = User::create([
            ...$request->validated(),
            'password' => $temporary,
            'must_change_password' => true,
            'is_active' => true,
        ]);

        // The account notification: login instructions and the temporary
        // password, to the address the account was created with. Failure to
        // send must not lose the account that was just created, so it is
        // reported rather than thrown.
        $mailed = true;

        try {
            Mail::to($user->email)->send(new AccountCreated($user, $temporary, $request->user()?->name));
        } catch (\Throwable $e) {
            $mailed = false;
            report($e);
        }

        // Still shown to the admin once, so the password can be passed on by
        // hand if the email did not go out.
        return redirect()->route('admin.users')
            ->with('status', $mailed
                ? "Account created for {$user->name}. The sign-in details have been emailed to {$user->email}."
                : "Account created for {$user->name}, but the email could not be sent. Pass the password on directly.")
            ->with('temporary_password', $temporary)
            ->with('temporary_password_for', $user->name);
    }

    public function edit(User $user): View
    {
        return view('admin.users.edit', ['user' => $user]);
    }

    /**
     * An admin may not change their own role. Demoting yourself to student
     * takes effect on the next request and locks you straight out of the admin
     * area — the same self-inflicted lockout that toggleStatus() already
     * refuses, and it needs another administrator to undo. Every other field on
     * your own account stays editable.
     */
    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $validated = $request->validated();

        if ($user->is($request->user()) && $validated['role'] !== $user->role) {
            return back()
                ->withInput()
                ->withErrors(['role' => 'You cannot change your own role. Another administrator can do it for you.']);
        }

        $user->update($validated);

        return redirect()->route('admin.users')
            ->with('status', "{$user->name}'s account has been updated.");
    }

    /**
     * Activate or deactivate. Deactivating is confirmed in the UI; an admin
     * cannot deactivate their own account and lock themselves out.
     */
    public function toggleStatus(Request $request, User $user): RedirectResponse
    {
        if ($user->is($request->user())) {
            return back()->withErrors(['status' => 'You cannot deactivate your own account.']);
        }

        $user->update(['is_active' => ! $user->is_active]);

        return back()->with('status', $user->is_active
            ? "{$user->name}'s account has been reactivated."
            : "{$user->name}'s account has been deactivated.");
    }

    /** Issues a fresh temporary password and forces a change at next sign-in. */
    public function resetPassword(User $user): RedirectResponse
    {
        $temporary = TemporaryPassword::generate();

        $user->forceFill([
            'password' => $temporary,
            'must_change_password' => true,
        ])->save();

        return back()
            ->with('status', "A new temporary password was issued for {$user->name}.")
            ->with('temporary_password', $temporary)
            ->with('temporary_password_for', $user->name);
    }
}
