<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Subject;
use App\Models\Task;
use App\Models\User;
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
        $user = auth()->user();

        return view('account.edit', ['user' => $user, 'stats' => $this->stats($user)]);
    }

    /**
     * The small numbers on the profile card: the signed-in user's own, with the same meaning as on their dashboard,
     * and a fixed handful of count queries whatever they own. An administrator has none beyond "Member since".
     *
     * @return list<array{label: string, value: int}>
     */
    protected function stats(User $user): array
    {
        if ($user->isStudent()) {
            $groupIds = $user->groupMemberships()->select('group_id');

            return [
                ['label' => 'Groups', 'value' => $user->groupMemberships()->count()],
                // "Completed by me": tasks of my groups assigned to me and done.
                ['label' => 'Tasks completed', 'value' => Task::query()
                    ->whereIn('group_id', $groupIds)
                    ->where('assigned_to', $user->id)
                    ->where('status', Task::STATUS_DONE)
                    ->count()],
            ];
        }

        if ($user->isLecturer()) {
            $subjects = Subject::query()->where('lecturer_id', $user->id);
            $groups = Group::query()->whereIn('subject_id', (clone $subjects)->select('id'));

            return [
                ['label' => 'Subjects', 'value' => (clone $subjects)->count()],
                ['label' => 'Groups', 'value' => (clone $groups)->count()],
                // A student in several of the lecturer's groups counts once.
                ['label' => 'Students', 'value' => GroupMember::query()->whereIn('group_id', (clone $groups)->select('id'))->distinct()->count('user_id')],
            ];
        }

        return [];
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
