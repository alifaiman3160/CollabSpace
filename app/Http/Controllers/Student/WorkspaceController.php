<?php

namespace App\Http\Controllers\Student;

use App\Models\ActivityLog;
use App\Models\Group;
use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WorkspaceController extends StudentController
{
    /** Without a group id, land on the student's first group. */
    public function index(Request $request): View|\Illuminate\Http\RedirectResponse
    {
        $groups = $this->myGroups();

        if ($groups->isEmpty()) {
            return view('student.workspace', ['hasGroup' => false, 'groups' => $groups]);
        }

        return redirect()->route('student.workspace.show', $groups->first());
    }

    public function show(Group $group): View
    {
        $group = $this->ownedGroup($group);

        $group->load([
            'subject',
            'members' => fn ($q) => $q->orderBy('name'),
        ])->loadCount([
            'tasks as tasks_total',
            'tasks as tasks_done' => fn ($q) => $q->where('status', Task::STATUS_DONE),
            'tasks as tasks_doing' => fn ($q) => $q->where('status', Task::STATUS_INPROGRESS),
            'tasks as tasks_overdue' => fn ($q) => $q->overdue(),
        ]);

        // Contribution score per member, from the same weighted rows the
        // lecturer's reporting reads.
        $scores = ActivityLog::query()
            ->where('group_id', $group->id)
            ->selectRaw('user_id, SUM(weight) AS score')
            ->groupBy('user_id')
            ->pluck('score', 'user_id');

        return view('student.workspace', [
            'hasGroup' => true,
            'groups' => $this->myGroups(),
            'group' => $group,
            'scores' => $scores,
            'openTasks' => $group->tasks()
                ->with('assignee')
                ->where('status', '!=', Task::STATUS_DONE)
                ->orderByRaw('deadline IS NULL')
                ->orderBy('deadline')
                ->take(6)
                ->get(),
            'recentActivity' => ActivityLog::query()
                ->where('group_id', $group->id)
                ->with('user')
                ->latest('created_at')
                ->take(12)
                ->get(),
        ]);
    }
}
