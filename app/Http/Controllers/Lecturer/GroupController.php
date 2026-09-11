<?php

namespace App\Http\Controllers\Lecturer;

use App\Http\Requests\Lecturer\StoreGroupRequest;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Subject;
use App\Models\Task;
use App\Models\User;
use App\Support\Notifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class GroupController extends LecturerController
{
    public function index(Request $request): View
    {
        $subjects = $this->subjects()->orderBy('code')->get();

        // Optional subject filter, itself scoped: an id the lecturer does not
        // own simply matches nothing rather than leaking another cohort.
        $subjectId = $request->integer('subject') ?: null;
        if ($subjectId !== null && ! $subjects->contains('id', $subjectId)) {
            abort(404);
        }

        $groups = $this->groups()
            ->with('subject')
            ->when($subjectId, fn ($q) => $q->where('subject_id', $subjectId))
            ->withCount([
                'groupMembers as members_count',
                'tasks as tasks_total',
                'tasks as tasks_done' => fn ($q) => $q->where('status', Task::STATUS_DONE),
                'tasks as tasks_overdue' => fn ($q) => $q->overdue(),
                // Feed the delete confirmation with what the cascade would take.
                'documents as documents_count',
                'messages as messages_count',
            ])
            ->orderBy('subject_id')
            ->orderBy('name')
            ->get();

        return view('lecturer.groups.index', [
            'groups' => $groups,
            'subjects' => $subjects,
            'subjectId' => $subjectId,
        ]);
    }

    public function create(Request $request): View
    {
        $subjects = $this->subjects()->orderBy('code')->get();

        abort_if($subjects->isEmpty(), 404);

        $selected = $request->integer('subject') ?: null;
        if ($selected !== null && ! $subjects->contains('id', $selected)) {
            abort(404);
        }

        return view('lecturer.groups.create', [
            'subjects' => $subjects,
            'selected' => $selected ?? $subjects->first()->id,
        ]);
    }

    public function store(StoreGroupRequest $request): RedirectResponse
    {
        $subject = $this->ownedSubject(Subject::findOrFail($request->integer('subject_id')));

        $group = Group::create([
            'subject_id' => $subject->id,
            'name' => $request->string('name')->trim()->value(),
            // Falls back to the subject's default when the field is left alone.
            'group_size' => $request->integer('group_size') ?: $subject->group_size,
        ]);

        return redirect()->route('lecturer.groups.show', $group)
            ->with('status', "{$group->name} has been created. Assign its members below.");
    }

    public function show(Group $group): View
    {
        $group = $this->ownedGroup($group);

        $group->load(['subject', 'members' => fn ($q) => $q->orderBy('name')])
            ->loadCount([
                'tasks as tasks_total',
                'tasks as tasks_done' => fn ($q) => $q->where('status', Task::STATUS_DONE),
                'tasks as tasks_overdue' => fn ($q) => $q->overdue(),
                'documents as documents_count',
                'messages as messages_count',
            ]);

        return view('lecturer.groups.show', [
            'group' => $group,
            'assignable' => $this->assignableStudents($group),
        ]);
    }

    /**
     * Deleting a group cascades to its tasks, documents, messages and activity
     * logs. The UI confirms first, with the counts it is about to destroy.
     *
     * Its chat uploads go too — see the Group::deleting hook in
     * AppServiceProvider, which runs inside this transaction.
     */
    public function destroy(Group $group): RedirectResponse
    {
        $group = $this->ownedGroup($group);

        $name = $group->name;
        $subjectId = $group->subject_id;

        DB::transaction(fn () => $group->delete());

        return redirect()->route('lecturer.groups', ['subject' => $subjectId])
            ->with('status', "{$name} and everything recorded in it have been deleted.");
    }

    public function addMember(Request $request, Group $group): RedirectResponse
    {
        $group = $this->ownedGroup($group);

        $validated = $request->validate(
            ['user_id' => ['required', 'integer']],
            [],
            ['user_id' => 'student'],
        );

        $student = User::find($validated['user_id']);

        // Every rule re-checked here, not just in the dropdown that produced it:
        // the form can be replayed with any id.
        if (! $student || $student->role !== 'student' || ! $student->is_active) {
            return back()->withErrors(['user_id' => 'That is not an active student account.']);
        }

        if ($group->members()->whereKey($student->id)->exists()) {
            return back()->withErrors(['user_id' => "{$student->name} is already in this group."]);
        }

        if ($this->isInAnotherGroupOfSubject($student, $group)) {
            return back()->withErrors([
                'user_id' => "{$student->name} already belongs to another group in {$group->subject->code}.",
            ]);
        }

        if ($group->groupMembers()->count() >= $group->group_size) {
            return back()->withErrors([
                'user_id' => "{$group->name} is full — its size limit is {$group->group_size}.",
            ]);
        }

        GroupMember::create([
            'group_id' => $group->id,
            'user_id' => $student->id,
            'joined_at' => now(),
        ]);

        app(Notifier::class)->addedToGroup($student, $group, $request->user());

        return back()->with('status', "{$student->name} has been added to {$group->name}.");
    }

    public function removeMember(Group $group, User $user): RedirectResponse
    {
        $group = $this->ownedGroup($group);

        $membership = $group->groupMembers()->where('user_id', $user->id)->first();

        abort_if($membership === null, 404);

        $membership->delete();

        app(Notifier::class)->removedFromGroup($user, $group, auth()->user());

        return back()->with('status', "{$user->name} has been removed from {$group->name}.");
    }

    /**
     * Active students who are not yet in any group of this group's subject.
     * A student belongs to at most one group per subject.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, User>
     */
    protected function assignableStudents(Group $group)
    {
        return User::query()
            ->where('role', 'student')
            ->where('is_active', true)
            ->whereDoesntHave('groups', fn ($q) => $q->where('subject_id', $group->subject_id))
            ->orderBy('name')
            ->get();
    }

    protected function isInAnotherGroupOfSubject(User $student, Group $group): bool
    {
        return $student->groups()
            ->where('subject_id', $group->subject_id)
            ->whereKeyNot($group->id)
            ->exists();
    }
}
