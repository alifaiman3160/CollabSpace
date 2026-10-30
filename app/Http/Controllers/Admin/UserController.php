<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Mail\AccountCreated;
use App\Mail\TemporaryPasswordIssued;
use App\Models\User;
use App\Support\Notifier;
use App\Support\TemporaryPassword;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;

class UserController extends Controller
{
    public function __construct(protected Notifier $notifier) {}

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
        $mailed = $this->deliver(
            fn () => Mail::to($user->email)->send(new AccountCreated($user, $temporary, $request->user()?->name))
        );

        $this->notifier->accountCreated($user, $request->user());

        return redirect()->route('admin.users')->with(
            $this->handover($user, $temporary, $mailed,
                "Account created for {$user->name}. The sign-in details have been emailed to {$user->email}.")
        );
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

        $this->notifier->accountStatusChanged($user, $request->user());

        return back()->with('status', $user->is_active
            ? "{$user->name}'s account has been reactivated."
            : "{$user->name}'s account has been deactivated.");
    }

    /** Issues a fresh temporary password and forces a change at next sign-in. */
    public function resetPassword(Request $request, User $user): RedirectResponse
    {
        $temporary = TemporaryPassword::generate();

        $user->forceFill([
            'password' => $temporary,
            'must_change_password' => true,
        ])->save();

        // This used to go nowhere: the old password stopped working and the new
        // one existed only on the administrator's screen, so navigating away
        // locked the account out. It goes to its owner now.
        $mailed = $this->deliver(
            fn () => Mail::to($user->email)->send(new TemporaryPasswordIssued($user, $temporary, $request->user()?->name))
        );

        $this->notifier->passwordIssued($user, $request->user());

        return back()->with(
            $this->handover($user, $temporary, $mailed,
                "A new temporary password was issued for {$user->name} and emailed to {$user->email}.")
        );
    }

    /**
     * Sends something, reporting failure rather than throwing it.
     *
     * The account or the password change has already happened by the time this
     * runs, and losing that to a mail server being down would be far worse than
     * a message that did not arrive.
     */
    protected function deliver(\Closure $send): bool
    {
        try {
            $send();

            return true;
        } catch (\Throwable $e) {
            report($e);

            return false;
        }
    }

    /**
     * The flash payload for an issued password.
     *
     * On the normal path the administrator is told the email went out and
     * nothing more. They do not see the password, and there is no reason they
     * should: it belongs to one person, it reaches that person directly, and a
     * secret on a screen is a secret in whatever is behind that screen —
     * a shoulder, a projector in a lab, a screenshot in a report.
     *
     * The one exception is a failed send. Then the email is not a channel, and
     * the administrator is the only remaining way for the password to reach its
     * owner, so it is shown once with the failure stated plainly. Rare by
     * design, and loud when it happens.
     *
     * @return array<string, mixed>
     */
    protected function handover(User $user, string $temporary, bool $mailed, string $sentMessage): array
    {
        if ($mailed) {
            return ['status' => $sentMessage];
        }

        return [
            'status' => "The password for {$user->name} could not be emailed to {$user->email}. "
                .'Pass it on directly, then check the mail settings — it is shown here once and cannot be looked up again.',
            'temporary_password' => $temporary,
            'temporary_password_for' => $user->name,
        ];
    }
}
