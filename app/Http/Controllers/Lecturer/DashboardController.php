<?php

namespace App\Http\Controllers\Lecturer;

use App\Models\GroupMember;
use App\Models\Task;
use Illuminate\View\View;

class DashboardController extends LecturerController
{
    public function index(): View
    {
        $subjectIds = $this->subjects()->pluck('id');
        $groupIds = $this->groups()->pluck('id');

        $tasks = Task::query()->whereIn('group_id', $groupIds);

        $counts = [
            'subjects' => $subjectIds->count(),
            'groups' => $groupIds->count(),
            // A student can sit in several of this lecturer's groups across
            // different subjects, so count them once.
            'students' => GroupMember::whereIn('group_id', $groupIds)->distinct()->count('user_id'),
            'completed' => (clone $tasks)->where('status', Task::STATUS_DONE)->count(),
            'overdue' => (clone $tasks)->overdue()->count(),
        ];

        $groups = $this->groups()
            ->with('subject')
            ->withCount([
                'groupMembers as members_count',
                'tasks as tasks_total',
                'tasks as tasks_done' => fn ($q) => $q->where('status', Task::STATUS_DONE),
                'tasks as tasks_overdue' => fn ($q) => $q->overdue(),
            ])
            ->orderBy('subject_id')
            ->orderBy('name')
            ->get();

        return view('lecturer.dashboard', [
            'counts' => $counts,
            'groups' => $groups,
        ]);
    }
}
