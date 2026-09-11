<?php

namespace App\Livewire;

use App\Models\Group;
use App\Models\Task;
use App\Models\User;
use App\Support\ActivityRecorder;
use App\Support\Notifier;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * The group task board: three columns, drag-and-drop plus an explicit status
 * control on every card.
 *
 * Membership is re-checked on every action, not just at mount — a Livewire
 * request carries the component's state back from the browser, so the group id
 * and every task id must be treated as untrusted on each round trip.
 */
class TaskBoard extends Component
{
    public Group $group;

    /** Create-task form. */
    public bool $showForm = false;

    #[Validate('required|string|max:200')]
    public string $title = '';

    #[Validate('nullable|string|max:2000')]
    public string $description = '';

    #[Validate('nullable|integer')]
    public ?int $assignedTo = null;

    #[Validate('nullable|date')]
    public ?string $deadline = null;

    /** Task pending delete confirmation. */
    public ?int $confirmingDelete = null;

    public function mount(Group $group): void
    {
        $this->group = $this->authorizedGroup($group);
    }

    /*
    |--------------------------------------------------------------------------
    | Guards
    |--------------------------------------------------------------------------
    */

    /** The signed-in student must be a member of this group. */
    protected function authorizedGroup(Group $group): Group
    {
        abort_unless(
            auth()->check()
                && auth()->user()->groupMemberships()->where('group_id', $group->getKey())->exists(),
            404,
        );

        return $group;
    }

    /**
     * Resolves a task id from the request, refusing anything outside this
     * group. Every mutating action goes through here.
     */
    protected function taskInGroup(int $taskId): Task
    {
        $task = Task::where('id', $taskId)
            ->where('group_id', $this->group->id)
            ->first();

        abort_if($task === null, 404);

        return $task;
    }

    protected function recorder(): ActivityRecorder
    {
        return app(ActivityRecorder::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Actions
    |--------------------------------------------------------------------------
    */

    /**
     * Moves a task between columns. Completing it stamps completed_at and
     * writes the contribution row; moving it back out clears both, so a score
     * only ever reflects work that is currently finished.
     */
    public function moveTask(int $taskId, string $status): void
    {
        $this->authorizedGroup($this->group);

        if (! in_array($status, [Task::STATUS_TODO, Task::STATUS_INPROGRESS, Task::STATUS_DONE], true)) {
            return;
        }

        $task = $this->taskInGroup($taskId);

        if ($task->status === $status) {
            return;
        }

        $wasDone = $task->status === Task::STATUS_DONE;
        $nowDone = $status === Task::STATUS_DONE;

        $task->status = $status;
        $task->updated_by = auth()->id();

        if ($nowDone && ! $wasDone) {
            $task->completed_at = now();
            $task->save();

            // Credited to whoever the task is assigned to; an unassigned task
            // is credited to the member who moved it.
            $this->recorder()->taskCompleted($task, $task->assigned_to ?? auth()->id());
        } elseif ($wasDone && ! $nowDone) {
            $task->completed_at = null;
            $task->save();

            $this->recorder()->taskCompletionCleared($task);
        } else {
            $task->save();
        }

        $this->dispatch('board-updated');
    }

    public function createTask(): void
    {
        $this->authorizedGroup($this->group);

        $this->validate([
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'],
            'deadline' => ['nullable', 'date'],
            // Only a member of this group may be assigned work in it.
            'assignedTo' => [
                'nullable',
                Rule::exists('group_members', 'user_id')->where('group_id', $this->group->id),
            ],
        ], [], [
            'assignedTo' => 'assignee',
        ]);

        $task = Task::create([
            'group_id' => $this->group->id,
            'created_by' => auth()->id(),
            'assigned_to' => $this->assignedTo,
            'title' => trim($this->title),
            'description' => $this->description !== '' ? $this->description : null,
            'status' => Task::STATUS_TODO,
            'deadline' => $this->deadline ?: null,
        ]);

        $this->recorder()->taskCreated($task, auth()->id());

        app(Notifier::class)->taskAssigned($task, auth()->user());

        $this->reset(['title', 'description', 'assignedTo', 'deadline', 'showForm']);

        $this->dispatch('board-updated');
    }

    public function confirmDelete(int $taskId): void
    {
        $this->taskInGroup($taskId);

        $this->confirmingDelete = $taskId;
    }

    public function cancelDelete(): void
    {
        $this->confirmingDelete = null;
    }

    /** Takes the task's contribution rows with it — see ActivityRecorder. */
    public function deleteTask(): void
    {
        $this->authorizedGroup($this->group);

        if ($this->confirmingDelete === null) {
            return;
        }

        $task = $this->taskInGroup($this->confirmingDelete);

        $this->recorder()->taskDeleted($task);
        $task->delete();

        $this->confirmingDelete = null;

        $this->dispatch('board-updated');
    }

    public function toggleForm(): void
    {
        $this->showForm = ! $this->showForm;

        if (! $this->showForm) {
            $this->reset(['title', 'description', 'assignedTo', 'deadline']);
            $this->resetValidation();
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Render
    |--------------------------------------------------------------------------
    */

    public function render(): View
    {
        $tasks = Task::query()
            ->where('group_id', $this->group->id)
            ->with('assignee')
            ->orderByRaw('deadline IS NULL')
            ->orderBy('deadline')
            ->orderBy('id')
            ->get();

        $byStatus = $tasks->groupBy('status');

        return view('livewire.task-board', [
            'columns' => [
                Task::STATUS_TODO => $byStatus->get(Task::STATUS_TODO, collect()),
                Task::STATUS_INPROGRESS => $byStatus->get(Task::STATUS_INPROGRESS, collect()),
                Task::STATUS_DONE => $byStatus->get(Task::STATUS_DONE, collect()),
            ],
            'total' => $tasks->count(),
            'doneCount' => $byStatus->get(Task::STATUS_DONE, collect())->count(),
            'doingCount' => $byStatus->get(Task::STATUS_INPROGRESS, collect())->count(),
            'overdueCount' => $tasks->filter->isOverdue()->count(),
            'members' => User::query()
                ->whereIn('id', $this->group->groupMembers()->select('user_id'))
                ->orderBy('name')
                ->get(),
            'deletingTask' => $this->confirmingDelete
                ? $tasks->firstWhere('id', $this->confirmingDelete)
                : null,
        ]);
    }
}
