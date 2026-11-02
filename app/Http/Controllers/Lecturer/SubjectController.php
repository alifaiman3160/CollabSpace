<?php

namespace App\Http\Controllers\Lecturer;

use App\Http\Requests\Lecturer\UpdateSubjectRequest;
use App\Models\Announcement;
use App\Models\Enrolment;
use App\Models\GroupMember;
use App\Models\Subject;
use App\Support\AnnouncementReach;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class SubjectController extends LecturerController
{
    public function index(): View
    {
        $subjects = $this->subjects()
            ->with('assessments')
            ->withCount(['groups', 'groupMembers'])
            ->orderBy('code')
            ->get();

        // Project deadlines, soonest first (an overdue one is the soonest), only for subjects that have one.
        $deadlines = $subjects
            ->filter(fn ($subject) => $subject->projectDeadline() !== null)
            ->sortBy(fn ($subject) => \Illuminate\Support\Carbon::parse($subject->projectDeadline())->startOfDay()->timestamp)
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

    /**
     * Subjects are created by the administrator and given to a lecturer. The route stays so that a hand-made request is
     * refused plainly (403) instead of hitting a missing page.
     */
    public function store(): never
    {
        abort(403, 'Subjects are created by the administrator.');
    }
    public function edit(Subject $subject): View
    {
        $subject = $this->ownedSubject($subject);

        $subject->loadCount('groups')->load(['assessments', 'files' => fn ($q) => $q->with('assessment:id,title')->orderBy('assessment_id')->orderBy('kind')->orderBy('title'), 'lecturer:id,name', 'sections' => fn ($q) => $q->withCount('enrolments')->orderBy('name')]);

        // The numbers of the summary strip, from three small queries: enrolments (with their section), groups per
        // section, and who is already in a group of this subject.
        $enrolments = Enrolment::query()->where('subject_id', $subject->getKey())->get(['user_id', 'section_id']);
        $grouped = GroupMember::query()->join('groups', 'groups.id', '=', 'group_members.group_id')
            ->where('groups.subject_id', $subject->getKey())->pluck('group_members.user_id');
        $groupsBySection = $subject->groups()->whereNotNull('section_id')->groupBy('section_id')->selectRaw('section_id, count(*) as total')->pluck('total', 'section_id');
        $studentsBySection = $enrolments->countBy('section_id');

        return view('lecturer.subjects.edit', [
            'subject' => $subject,
            'summary' => [
                'groups' => (int) $subject->groups_count,
                'students' => $enrolments->count(),
                'ungrouped' => $enrolments->reject(fn ($e) => $grouped->contains($e->user_id))->count(),
                'assessments' => $subject->assessments->count(),
                'sections' => $subject->sections->count() >= 2
                    ? $subject->sections->map(fn ($section) => ['name' => $section->name, 'students' => (int) ($studentsBySection[$section->id] ?? 0), 'groups' => (int) ($groupsBySection[$section->id] ?? 0)])->all()
                    : [],
            ],
        ]);
    }

    public function update(UpdateSubjectRequest $request, Subject $subject): RedirectResponse
    {
        $subject = $this->ownedSubject($subject);

        $data = $request->validated();

        // With assessments, the dates and the brief live on them: the subject's own are no longer edited here.
        if ($subject->assessments()->exists()) {
            unset($data['project_deadline'], $data['brief']);
        }

        $subject->update($data);

        return redirect()->route('lecturer.subjects')
            ->with('status', "{$subject->code} has been updated.");
    }

    /** The administrator deletes subjects (and only empty ones); a lecturer cannot. */
    public function destroy(Subject $subject): never
    {
        abort(403, 'Subjects are deleted by the administrator.');
    }
}