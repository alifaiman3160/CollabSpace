<?php

namespace App\Http\Controllers\Student;

use App\Models\Message;
use App\Support\Sections\AnnouncementAudience;
use App\Models\Task;
use Illuminate\View\View;

class DashboardController extends StudentController
{
    public function index(): View
    {
        $me = $this->student();
        $groups = $this->myGroups();

        // The subjects the administrator enrolled this student in that have no group of theirs yet: they still get
        // their subject card and its announcements.
        $waiting = $me->enrolments()->with(['subject.assessments', 'section'])->get()
            ->reject(fn ($enrolment) => $groups->contains('subject_id', $enrolment->subject_id))
            ->sortBy(fn ($enrolment) => $enrolment->subject->code)
            ->values();

        // News from the subjects this student is enrolled or grouped in, newest first, for their own section.
        $announcements = AnnouncementAudience::visibleTo($me)
            ->with(['subject:id,code', 'author:id,name', 'section'])
            ->latest()
            ->latest('id')
            ->get();
        $readIds = \App\Models\AnnouncementRead::query()->where('user_id', $me->id)->pluck('announcement_id')->all();

        // How many files each of the waiting subjects has, for the "Files (N)" chip: one grouped count.
        $fileCounts = \App\Models\SubjectFile::query()->whereIn('subject_id', $waiting->pluck('subject_id'))
            ->groupBy('subject_id')->selectRaw('subject_id, count(*) as total')->pluck('total', 'subject_id');

        // A student with no group assignment yet gets a proper empty state
        // rather than a page full of zeroes and broken links.
        if ($groups->isEmpty()) {
            return view('student.dashboard', [
                'groups' => $groups,
                'hasGroup' => false,
                'waiting' => $waiting,
                'fileCounts' => $fileCounts,
                'announcements' => $announcements,
                'readAnnouncementIds' => $readIds,
            ]);
        }

        $groupIds = $groups->modelKeys();

        $groups->loadCount([
            'tasks as tasks_total',
            'tasks as tasks_done' => fn ($q) => $q->where('status', Task::STATUS_DONE),
        ]);

        $mine = Task::query()->whereIn('group_id', $groupIds)->where('assigned_to', $me->id);

        return view('student.dashboard', [
            'hasGroup' => true,
            'groups' => $groups,
            'waiting' => $waiting,
            'fileCounts' => $fileCounts,

            'pendingCount' => (clone $mine)->where('status', '!=', Task::STATUS_DONE)->count(),
            'completedCount' => (clone $mine)->where('status', Task::STATUS_DONE)->count(),
            'overdueCount' => (clone $mine)->overdue()->count(),

            // Pending work assigned to me, soonest deadline first, undated last.
            'pendingTasks' => (clone $mine)
                ->with('group.subject')
                ->where('status', '!=', Task::STATUS_DONE)
                ->orderByRaw('deadline IS NULL')
                ->orderBy('deadline')
                ->take(8)
                ->get(),

            'recentMessages' => Message::query()
                ->whereIn('group_id', $groupIds)
                ->with(['user', 'group'])
                ->latest()
                ->take(8)
                ->get(),

            'announcements' => $announcements,
            'readAnnouncementIds' => $readIds,

            'primaryGroup' => $groups->first(),
        ]);
    }
}
