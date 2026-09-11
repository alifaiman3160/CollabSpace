<?php

namespace App\Http\Controllers\Student;

use App\Models\Message;
use App\Models\Task;
use Illuminate\View\View;

class DashboardController extends StudentController
{
    public function index(): View
    {
        $me = $this->student();
        $groups = $this->myGroups();

        // A student with no group assignment yet gets a proper empty state
        // rather than a page full of zeroes and broken links.
        if ($groups->isEmpty()) {
            return view('student.dashboard', [
                'groups' => $groups,
                'hasGroup' => false,
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
                ->take(5)
                ->get(),

            'primaryGroup' => $groups->first(),
        ]);
    }
}
