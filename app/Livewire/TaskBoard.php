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

    /**
     * The task the form is editing, or null when the form is creating a new
     * one. One form serves both: the fields are identical, and two of them
     * would drift apart the first time one was changed.
     */
    public ?int $editingTask = null;

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

    /**
     * Whether the signed-in member may give work to other members: the group's leader, or anyone while the group has no
     * leader (so it never gets stuck). Read fresh on every action, because the lecturer can change the leader at any time.
     */
    protected function canAssignOthers(): bool
    {
        $this->group->refresh();

        return $this->group->leader_id === null || $this->group->leader_id === auth()->id();
    }

    /** The leader manages every task; any other member only the ones they made for themselves. */
    protected function canManage(Task $task): bool
    {
        return $this->canAssignOthers()
            || ($task->created_by === auth()->id() && $task->assigned_to === auth()->id());
    }

    protected function refuseAssign(): void
    {
        $this->addError('assignedTo', 'Only the group leader can assign tasks to other members.');
    }

    protected function refuseManage(): void
    {
        $this->dispatch('toast', message: 'Only the group leader can change or delete tasks that are not your own.', tone: 'danger');
    }

    protected function recorder(): ActivityRecorder
    {
        return app(ActivityRecorder::class);
    }

    /**
     * Whether the signed-in member may move this card between columns.
     *
     * Work that belongs to somebody is moved by that somebody. This is not
     * tidiness — it protects the contribution record, which is the thing the
     * whole system exists to produce.
     *
     * Two holes it closes. Dropping another member's card into Done credits
     * *them* with the points, so anyone could hand a free rider a finished
     * task they never touched. Dragging their finished card back out deletes
     * that contribution row outright, so anyone could quietly wipe a
     * groupmate's credit, twice over, in a marking dispute. Neither is a
     * hypothetical: both are two drags with no barrier at all.
     *
     * An unassigned task belongs to nobody and stays open to everyone — it is
     * the one most likely to be forgotten, and whoever picks it up is credited
     * with it. And a task whose owner has gone quiet is not stuck: any member
     * may reassign it from the edit form, which is a deliberate, recorded act
     * rather than a silent drag.
     */
    protected function mayMove(Task $task): bool
    {
        return $task->assigned_to === null
            || $task->assigned_to === auth()->id();
    }

    /** Says why the card did not move, rather than letting it snap back in silence. */
    protected function refuseMove(Task $task): void
    {
        $owner = $task->assignee?->name ?? 'another member';

        $this->dispatch(
            'toast',
            message: "“{$task->title}” is assigned to {$owner}, so only they can move it. "
                .'If they are not able to, reassign the task first and then move it.',
            tone: 'danger',
        );
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

        if (! $this->mayMove($task)) {
            $this->refuseMove($task);

            return;
        }

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

            $this->announceIfGroupFinished();
        } elseif ($wasDone && ! $nowDone) {
            $task->completed_at = null;
            $task->save();

            $this->recorder()->taskCompletionCleared($task);
        } else {
            $task->save();
        }

        $this->dispatch('board-updated');
    }

    /**
     * The form's rules. Shared, because create and edit write the same four
     * fields and a rule that applied to only one of them would be a hole.
     *
     * @return array<string, mixed>
     */
    protected function formRules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'],
            'deadline' => ['nullable', 'date'],
            // Only a member of this group may be assigned work in it.
            'assignedTo' => [
                'nullable',
                Rule::exists('group_members', 'user_id')->where('group_id', $this->group->id),
            ],
        ];
    }

    /** The form's single submit handler; which branch it takes is its mode. */
    public function saveTask(): void
    {
        $this->editingTask === null
            ? $this->createTask()
            : $this->updateTask();
    }

    /**
     * Tells the lecturer when the last open task on the board is finished.
     *
     * Checked here rather than on a schedule because "finished" is an event,
     * not a date — it happens the moment somebody drops the final card into
     * Done. The Notifier keys it on the group, so a board that is emptied,
     * added to and emptied again announces itself only the first time.
     */
    protected function announceIfGroupFinished(): void
    {
        $total = Task::where('group_id', $this->group->id)->count();

        if ($total === 0) {
            return;
        }

        $open = Task::where('group_id', $this->group->id)
            ->where('status', '!=', Task::STATUS_DONE)
            ->count();

        if ($open === 0) {
            app(Notifier::class)->groupCompleted($this->group, $total);
        }
    }

    public function createTask(): void
    {
        $this->authorizedGroup($this->group);

        $this->validate($this->formRules(), [], [
            'assignedTo' => 'assignee',
        ]);

        // A member who is not the leader can only give a task to themselves.
        if (! $this->canAssignOthers()) {
            if ($this->assignedTo !== null && $this->assignedTo !== auth()->id()) {
                $this->refuseAssign();

                return;
            }

            $this->assignedTo = auth()->id();
        }

        $task = Task::create([
            'group_id' => $this->group->id,
            'created_by' => auth()->id(),
            'assigned_to' => $this->assignedTo,
            'title' => trim($this->title),
            'description' => $this->description !== '' ? $this->description : null,
            'status' => Task::STATUS_TODO,
            'deadline' => $this->deadline ?: null,
        ]);

        // Creating a task earns its creator points only when it is their own. Giving work to someone else, or leaving it
        // unassigned, is worth nothing: being the leader is not a way to score.
        if ($task->assigned_to === auth()->id()) {
            $this->recorder()->taskCreated($task, auth()->id());
        }

        app(Notifier::class)->taskAssigned($task, auth()->user());

        $this->reset(['title', 'description', 'assignedTo', 'deadline', 'showForm']);

        $this->dispatch('board-updated');
    }

    /**
     * Opens the form on an existing task.
     *
     * Use case 3.4.3.3 has the student create, assign, update and delete; until
     * this existed, "update" meant only moving a card between columns, and a
     * mistyped title or a wrong assignee could be corrected only by deleting
     * the task and starting again — which also threw away its contribution
     * credit and its place on the board.
     */
    public function startEdit(int $taskId): void
    {
        $this->authorizedGroup($this->group);

        $task = $this->taskInGroup($taskId);

        if (! $this->canManage($task)) {
            $this->refuseManage();

            return;
        }

        $this->editingTask = $task->getKey();
        $this->title = $task->title;
        $this->description = (string) $task->description;
        $this->assignedTo = $task->assigned_to;
        // The date input wants Y-m-d; the model hands back a Carbon instance.
        $this->deadline = $task->deadline?->format('Y-m-d');

        $this->showForm = true;
        $this->resetValidation();
    }

    /**
     * Saves changes to an existing task.
     *
     * Deliberately does not touch status or completed_at: those belong to the
     * board's drag-and-drop and its status control, and editing a title is not
     * a statement about whether the work is finished.
     *
     * Editing also scores nothing. The task already earned its creator their
     * task_created points, and letting an edit score again would make renaming
     * a task a way to farm contribution — the same reasoning that stops a
     * no-op document save writing a version.
     */
    public function updateTask(): void
    {
        $this->authorizedGroup($this->group);

        if ($this->editingTask === null) {
            return;
        }

        $task = $this->taskInGroup($this->editingTask);

        if (! $this->canManage($task)) {
            $this->refuseManage();

            return;
        }

        $this->validate($this->formRules(), [], [
            'assignedTo' => 'assignee',
        ]);

        if (! $this->canAssignOthers()) {
            // Their own task stays their own.
            if ($this->assignedTo !== null && $this->assignedTo !== auth()->id()) {
                $this->refuseAssign();

                return;
            }

            $this->assignedTo = auth()->id();
        }

        $previousAssignee = $task->assigned_to;

        $task->fill([
            'title' => trim($this->title),
            'description' => $this->description !== '' ? $this->description : null,
            'assigned_to' => $this->assignedTo,
            'deadline' => $this->deadline ?: null,
            'updated_by' => auth()->id(),
        ])->save();

        // Only when the work actually changed hands — re-saving a task that was
        // already theirs should not ping them again.
        if ($task->assigned_to !== null && $task->assigned_to !== $previousAssignee) {
            app(Notifier::class)->taskAssigned($task, auth()->user());
        }

        $this->cancelEdit();

        $this->dispatch('board-updated');
    }

    /** Closes the form and puts it back into create mode. */
    public function cancelEdit(): void
    {
        $this->reset(['title', 'description', 'assignedTo', 'deadline', 'showForm', 'editingTask']);
        $this->resetValidation();
    }

    public function confirmDelete(int $taskId): void
    {
        if (! $this->canManage($this->taskInGroup($taskId))) {
            $this->refuseManage();

            return;
        }

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

        if (! $this->canManage($task)) {
            $this->confirmingDelete = null;
            $this->refuseManage();

            return;
        }

        $this->recorder()->taskDeleted($task);
        $task->delete();

        $this->confirmingDelete = null;

        $this->dispatch('board-updated');
    }

    public function toggleForm(): void
    {
        // Opening the form from the "New task" button always means a new task,
        // even if it was last used to edit one.
        if (! $this->showForm) {
            $this->reset(['title', 'description', 'assignedTo', 'deadline', 'editingTask']);
            $this->resetValidation();
            // A member who is not the leader can only give the task to themselves: it starts on them.
            $this->assignedTo = $this->canAssignOthers() ? null : auth()->id();
            $this->showForm = true;

            return;
        }

        $this->cancelEdit();
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

        // Done: the most recently completed first. Anything without a completion time keeps its place after those.
        $done = $byStatus->get(Task::STATUS_DONE, collect())->values()->sort(function (Task $a, Task $b) {
            return match (true) {
                $a->completed_at !== null && $b->completed_at !== null => $b->completed_at <=> $a->completed_at,
                $a->completed_at !== null => -1,
                $b->completed_at !== null => 1,
                default => 0,
            };
        })->values();

        return view('livewire.task-board', [
            'columns' => [
                Task::STATUS_TODO => $byStatus->get(Task::STATUS_TODO, collect()),
                Task::STATUS_INPROGRESS => $byStatus->get(Task::STATUS_INPROGRESS, collect()),
                Task::STATUS_DONE => $done,
            ],
            'canAssignOthers' => $this->canAssignOthers(),
            'leaderId' => $this->group->leader_id,
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
            // Lets the form title name what is being edited.
            'editing' => $this->editingTask
                ? $tasks->firstWhere('id', $this->editingTask)
                : null,
        ]);
    }
}
