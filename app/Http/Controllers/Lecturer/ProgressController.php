<?php

namespace App\Http\Controllers\Lecturer;

use App\Models\ActivityLog;
use App\Models\Group;
use App\Models\Message;
use App\Models\Task;
use App\Support\ContributionReport;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Monitor progress: the read-only view into one group.
 *
 * This is where the dashboard's "Monitor" button points. It is deliberately
 * separate from GroupController@show, which is the *management* screen for
 * membership — monitoring is observation, and mixing the two would put
 * destructive controls on a screen a lecturer opens to read.
 */
class ProgressController extends LecturerController
{
    public function __construct(protected ContributionReport $reports) {}

    /** Group picker; goes straight through when there is only one. */
    public function index(): View|RedirectResponse
    {
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

        if ($groups->count() === 1) {
            return redirect()->route('lecturer.progress.show', $groups->first());
        }

        return view('lecturer.progress.index', ['groups' => $groups]);
    }

    public function show(Group $group): View
    {
        $group = $this->ownedGroup($group);

        $group->load(['subject', 'members' => fn ($q) => $q->orderBy('name')]);

        $tasks = Task::query()
            ->where('group_id', $group->id)
            ->with('assignee')
            ->orderByRaw('deadline IS NULL')
            ->orderBy('deadline')
            ->orderBy('id')
            ->get();

        $report = $this->reports->for($group);

        return view('lecturer.progress.show', [
            'group' => $group,
            'groups' => $this->groups()->with('subject')->orderBy('name')->get(),
            'report' => $report,

            // Read-only board: the same three columns the students drag between.
            'columns' => [
                Task::STATUS_TODO => $tasks->where('status', Task::STATUS_TODO)->values(),
                Task::STATUS_INPROGRESS => $tasks->where('status', Task::STATUS_INPROGRESS)->values(),
                Task::STATUS_DONE => $tasks->where('status', Task::STATUS_DONE)->values(),
            ],
            'taskTotal' => $tasks->count(),
            'taskDone' => $tasks->where('status', Task::STATUS_DONE)->count(),
            'taskDoing' => $tasks->where('status', Task::STATUS_INPROGRESS)->count(),
            'taskOverdue' => $tasks->filter->isOverdue()->count(),

            'recentMessages' => Message::where('group_id', $group->id)
                ->with('user')
                ->latest()
                ->take(12)
                ->get(),

            'recentActivity' => ActivityLog::where('group_id', $group->id)
                ->with('user')
                ->latest('created_at')
                ->take(15)
                ->get(),
        ]);
    }
}
