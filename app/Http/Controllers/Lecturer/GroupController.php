<?php

namespace App\Http\Controllers\Lecturer;

use App\Http\Requests\Lecturer\StoreGroupRequest;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Section;
use App\Models\Subject;
use App\Models\Task;
use App\Models\User;
use App\Support\Notifier;
use App\Support\Sections\Enrolments;
use App\Support\Sections\SubjectSectionFilter;
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

        // The two-step filter (subject, then section), query string only.
        $filter = SubjectSectionFilter::for($request, $this->lecturer());
        $sectionId = $filter['sectionId'];
        $sections = $filter['sections'];

        $groups = $this->groups()
            ->with(['subject', 'section', 'members:users.id,users.name'])
            ->when($subjectId, fn ($q) => $q->where('subject_id', $subjectId))
            ->when($sectionId, fn ($q) => $q->where('section_id', $sectionId))
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
            'sectionId' => $sectionId,
            'sections' => $sections,
            'filter' => $filter,
            'sectionCounts' => $this->sectionCounts($subjectId),
        ]);
    }

    /**
     * "1A: 5 groups, 1B: 6 groups" for the quiet summary line: one grouped count over the lecturer's groups
     * (of the chosen subject, if one is chosen), never one query per section.
     *
     * @return \Illuminate\Support\Collection<int, array{name: string, groups: int}>
     */
    protected function sectionCounts(?int $subjectId)
    {
        $counts = $this->groups()->when($subjectId, fn ($q) => $q->where('subject_id', $subjectId))
            ->whereNotNull('section_id')->groupBy('section_id')->selectRaw('section_id, count(*) as total')->pluck('total', 'section_id');

        return Section::query()->whereIn('id', $counts->keys())->orderBy('subject_id')->orderBy('name')->get()
            ->map(fn (Section $s) => ['name' => $s->name, 'groups' => (int) $counts[$s->id]]);
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
            'sections' => Section::query()->whereIn('subject_id', $subjects->pluck('id'))->orderBy('name')->get(['id', 'subject_id', 'name']),
            'selected' => $selected ?? $subjects->first()->id,
        ]);
    }

    public function store(StoreGroupRequest $request): RedirectResponse
    {
        $subject = $this->ownedSubject(Subject::findOrFail($request->integer('subject_id')));

        // The section: the one chosen, else the subject's only section; with several and none chosen it stays
        // empty and the first member added decides it.
        $section = $this->sectionFor($subject, $request->input('section_id'));

        $group = Group::create([
            'subject_id' => $subject->id,
            'section_id' => $section?->id,
            'name' => $request->string('name')->trim()->value(),
            // Falls back to the subject's default when the field is left alone.
            'group_size' => $request->integer('group_size') ?: $subject->group_size,
            // Recorded, like the automatic modes, so that "manual" is a fact
            // about the group and not just the absence of any other label.
            'formation_method' => Group::FORMED_MANUAL,
            'formed_by' => $request->user()->id,
            'formed_at' => now(),
        ]);

        return redirect()->route('lecturer.groups.show', $group)
            ->with('status', "{$group->name} has been created. Assign its members below.");
    }

    public function show(Group $group): View
    {
        $group = $this->ownedGroup($group);

        $group->load(['subject', 'section', 'leader:id,name', 'members' => fn ($q) => $q->orderBy('name')])
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
        $group->loadMissing('section');

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

        // Groups hold students of one section. The student must be enrolled in this subject, and in this group's
        // section (a group with no section yet takes the section of its first member).
        $enrolled = Enrolments::sectionOf($student, $group->subject_id);

        if ($enrolled === null) {
            return back()->withErrors([
                'user_id' => "{$student->name} is not enrolled in {$group->subject->code}. The administrator enrols students from User accounts.",
            ]);
        }

        if ($group->section_id !== null && $enrolled->id !== $group->section_id) {
            return back()->withErrors([
                'user_id' => "{$student->name} is in section {$enrolled->name}, but {$group->name} is a section {$group->section->name} group.",
            ]);
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

        if ($group->section_id === null) {
            $group->update(['section_id' => $enrolled->id]);
        }

        // The first member of a group is its leader.
        app(\App\Support\GroupLeader\GroupLeader::class)->onMemberAdded($group, $student);

        app(Notifier::class)->addedToGroup($student, $group, $request->user());

        return back()->with('status', "{$student->name} has been added to {$group->name}.");
    }

    /** Only the lecturer who owns the subject changes the leader, and only to a current member. */
    public function setLeader(Request $request, Group $group): RedirectResponse
    {
        $group = $this->ownedGroup($group);

        $validated = $request->validate(['user_id' => ['required', 'integer']], [], ['user_id' => 'student']);

        $member = $group->members()->whereKey($validated['user_id'])->first();

        if ($member === null) {
            return back()->withErrors(['user_id' => 'The leader has to be a member of this group.']);
        }

        if ($group->isLeader($member)) {
            return back()->with('status', "{$member->name} already leads {$group->name}.");
        }

        app(\App\Support\GroupLeader\GroupLeader::class)->setLeader($group, $member, $request->user());

        return back()->with('status', "{$member->name} is now the leader of {$group->name}.");
    }

    public function removeMember(Group $group, User $user): RedirectResponse
    {
        $group = $this->ownedGroup($group);

        $membership = $group->groupMembers()->where('user_id', $user->id)->first();

        abort_if($membership === null, 404);

        $membership->delete();

        // When the leader goes, the member with the highest CGPA takes over (and is told).
        app(\App\Support\GroupLeader\GroupLeader::class)->onMemberRemoved($group, $user, auth()->user());

        app(Notifier::class)->removedFromGroup($user, $group, auth()->user());

        return back()->with('status', "{$user->name} has been removed from {$group->name}.");
    }

    /**
     * Active students enrolled in this group's subject (and, once the group has a section, in that section) who
     * are not yet in any group of the subject. A student belongs to at most one group per subject.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, User>
     */
    protected function assignableStudents(Group $group)
    {
        return Enrolments::students($group->subject, $group->section)
            ->with('enrolments.section:id,name')
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
