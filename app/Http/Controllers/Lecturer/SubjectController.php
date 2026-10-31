<?php

namespace App\Http\Controllers\Lecturer;

use App\Http\Requests\Lecturer\StoreSubjectRequest;
use App\Http\Requests\Lecturer\UpdateSubjectRequest;
use App\Models\Announcement;
use App\Models\Group;
use App\Models\Subject;
use App\Support\AnnouncementReach;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class SubjectController extends LecturerController
{
    public function index(): View
    {
        $subjects = $this->subjects()
            ->withCount(['groups', 'groupMembers'])
            ->orderBy('code')
            ->get();

        // Project deadlines, soonest first (an overdue one is the soonest), only for subjects that have one.
        $deadlines = $subjects
            ->filter(fn ($subject) => $subject->project_deadline !== null)
            ->sortBy(fn ($subject) => \Illuminate\Support\Carbon::parse($subject->project_deadline)->startOfDay()->timestamp)
            ->values();

        // The newest five announcements across these subjects, with how far each has reached: three queries in all.
        $announcements = Announcement::query()
            ->whereIn('subject_id', $subjects->modelKeys())
            ->latest()
            ->latest('id')
            ->limit(5)
            ->get();

        $reach = app(AnnouncementReach::class);

        return view('lecturer.subjects.index', [
            'subjects' => $subjects,
            'deadlines' => $deadlines,
            'announcements' => $announcements,
            'audience' => $reach->audience($subjects->modelKeys()),
            'opened' => $reach->opened($announcements->modelKeys()),
        ]);
    }

    public function create(): View
    {
        return view('lecturer.subjects.create');
    }

    public function store(StoreSubjectRequest $request): RedirectResponse
    {
        $subject = Subject::create([
            ...$request->validated(),
            'lecturer_id' => $this->lecturer()->id,
        ]);

        return redirect()->route('lecturer.subjects')
            ->with('status', "{$subject->code} — {$subject->name} has been created.");
    }

    public function edit(Subject $subject): View
    {
        $subject = $this->ownedSubject($subject);

        return view('lecturer.subjects.edit', [
            'subject' => $subject->loadCount('groups'),
        ]);
    }

    public function update(UpdateSubjectRequest $request, Subject $subject): RedirectResponse
    {
        $subject = $this->ownedSubject($subject);

        $subject->update($request->validated());

        return redirect()->route('lecturer.subjects')
            ->with('status', "{$subject->code} has been updated.");
    }

    /**
     * Deleting a subject cascades to its groups, and from there to tasks,
     * documents, messages and activity logs. The UI confirms that first, with
     * the counts it is about to destroy.
     *
     * The groups are deleted one by one through Eloquent rather than left to
     * the database cascade. A cascade fires no model events, so the chat
     * uploads belonging to those groups would survive on disk with nothing left
     * pointing at them. Going through the model means the Group::deleting hook
     * in AppServiceProvider disposes of the files, and the whole thing sits in
     * one transaction so a failure part-way leaves the subject intact.
     */
    public function destroy(Subject $subject): RedirectResponse
    {
        $subject = $this->ownedSubject($subject);

        $label = "{$subject->code} — {$subject->name}";
        $groupCount = $subject->groups()->count();

        DB::transaction(function () use ($subject) {
            \App\Models\AppNotification::query()
                ->where('type', \App\Models\Announcement::TYPE)
                ->whereIn('reference_id', $subject->announcements()->select('id'))
                ->delete();

            $subject->groups()->cursor()->each(fn (Group $group) => $group->delete());

            $subject->delete();
        });

        return redirect()->route('lecturer.subjects')->with(
            'status',
            $groupCount > 0
                ? "{$label} and its {$groupCount} ".str('group')->plural($groupCount)." have been deleted."
                : "{$label} has been deleted."
        );
    }
}
