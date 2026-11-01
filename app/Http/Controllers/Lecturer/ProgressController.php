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
    public function index(\Illuminate\Http\Request $request): View|RedirectResponse
    {
        $filter = \App\Support\Sections\SubjectSectionFilter::for($request, $this->lecturer());
        $groups = $this->groups()
            ->when($filter['subjectId'], fn ($q, $id) => $q->where('subject_id', $id))
            ->when($filter['sectionId'], fn ($q, $id) => $q->where('section_id', $id))
            ->with(['subject.lecturer', 'section', 'members:users.id,users.name'])
            ->withCount([
                'groupMembers as members_count',
                'tasks as tasks_total',
                'tasks as tasks_done' => fn ($q) => $q->where('status', Task::STATUS_DONE),
                'tasks as tasks_overdue' => fn ($q) => $q->overdue(),
            ])
            ->orderBy('subject_id')
            ->orderBy('name')
            ->get();

        // Groups that need attention come first: most overdue tasks, then the lowest progress.
        $groups = \App\Support\GroupNeed::sort($groups);

        if ($groups->count() === 1 && $filter['subjectId'] === null && $filter['sectionId'] === null) {
            return redirect()->route('lecturer.progress.show', $groups->first());
        }

        // Members flagged for low participation: the contribution report's own rule and numbers, for all groups from
        // one read of the log (see ContributionReport::forMany).
        $reports = $this->reports->forMany($groups);
        $ratio = (float) config('contribution.low_participation_ratio');

        $flagged = $groups->flatMap(fn (Group $group) => $reports[$group->id]['flagged']->map(fn ($row) => [
            'group' => $group,
            'user' => $row['user'],
            'score' => (int) $row['score'],
        ]))->values();

        return view('lecturer.progress.index', [
            'filter' => $filter,
            'groups' => $groups,
            'subjects' => $groups->pluck('subject')->unique('id')->sortBy('code')->values(),
            'flagged' => $flagged,
            'reason' => 'Below '.($ratio === 0.5 ? 'half' : round($ratio * 100).'%').' the group average',
            'latest' => ActivityLog::query()
                ->whereIn('group_id', $groups->modelKeys())
                ->with('user:id,name')
                ->latest('created_at')
                ->latest('id')
                ->take(10)
                ->get(),
        ]);
    }

    public function show(Group $group): View
    {
        $group = $this->ownedGroup($group);

        $group->load(['subject', 'section', 'members' => fn ($q) => $q->orderBy('name')]);

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
            'groups' => $this->groups()->with(['subject', 'section'])->orderBy('name')->get(),
            'report' => $report,

            // Read-only board: the same three columns the students drag between.
            'columns' => [
                Task::STATUS_TODO => $tasks->where('status', Task::STATUS_TODO)->values(),
                Task::STATUS_INPROGRESS => $tasks->where('status', Task::STATUS_INPROGRESS)->values(),
                Task::STATUS_DONE => $tasks->where('status', Task::STATUS_DONE)->values(),
            ],
            'taskProgress' => $tasks->count() > 0 ? (int) round($tasks->where('status', Task::STATUS_DONE)->count() / $tasks->count() * 100) : 0,
            'taskTotal' => $tasks->count(),
            'taskDone' => $tasks->where('status', Task::STATUS_DONE)->count(),
            'taskDoing' => $tasks->where('status', Task::STATUS_INPROGRESS)->count(),
            'taskOverdue' => $tasks->filter->isOverdue()->count(),

            'recentMessages' => Message::where('group_id', $group->id)
                ->with('user')
                ->latest()
                ->take(30)
                ->get(),

            // The group's shared writing, openable read-only — the counts in
            // the contribution report say how much was edited, this says what.
            'documents' => $group->documents()
                ->with('lastEditor')
                ->withCount('versions')
                ->orderByDesc('updated_at')
                ->get(),

            // Deleted by the group, kept for the lecturer: who removed it, when, and what it held.
            'deletedDocuments' => $group->documents()
                ->onlyTrashed()
                ->with('deleter')
                ->withCount('versions')
                ->orderByDesc('deleted_at')
                ->get(),

            'recentActivity' => ActivityLog::where('group_id', $group->id)
                ->with('user')
                ->latest('created_at')
                ->take(30)
                ->get(),
        ]);
    }
}
