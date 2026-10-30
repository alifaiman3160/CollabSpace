<?php

namespace Tests\Feature\Student;

use App\Livewire\TaskBoard;
use App\Models\ActivityLog;
use App\Models\AppNotification;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Subject;
use App\Models\Task;
use App\Models\User;
use App\Support\ActivityRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TaskBoardTest extends TestCase
{
    use RefreshDatabase;

    protected User $me;

    protected User $mate;

    protected Group $group;

    protected function setUp(): void
    {
        parent::setUp();

        $lecturer = User::factory()->lecturer()->create();
        $subject = Subject::create([
            'lecturer_id' => $lecturer->id, 'code' => 'SECJ3104', 'name' => 'App Dev', 'group_size' => 4,
        ]);
        $this->group = Group::create(['subject_id' => $subject->id, 'name' => 'Group A', 'group_size' => 4]);

        $this->me = User::factory()->student()->create(['must_change_password' => false]);
        $this->mate = User::factory()->student()->create(['must_change_password' => false]);

        foreach ([$this->me, $this->mate] as $member) {
            GroupMember::create(['group_id' => $this->group->id, 'user_id' => $member->id, 'joined_at' => now()]);
        }
    }

    protected function task(array $overrides = []): Task
    {
        return Task::create([
            'group_id' => $this->group->id,
            'created_by' => $this->me->id,
            'assigned_to' => $this->me->id,
            'title' => 'A task',
            'status' => Task::STATUS_TODO,
            ...$overrides,
        ]);
    }

    /*
    | Who may move a card
    |
    | Work that belongs to somebody is moved by that somebody. Not tidiness —
    | the contribution record is what this whole system exists to produce, and
    | leaving every card open to every member put it at the mercy of two drags.
    */

    public function test_a_member_cannot_move_someone_elses_task(): void
    {
        $task = $this->task(['assigned_to' => $this->mate->id, 'title' => 'Draft chapter 1']);

        Livewire::actingAs($this->me)
            ->test(TaskBoard::class, ['group' => $this->group])
            ->call('moveTask', $task->id, Task::STATUS_DONE)
            ->assertDispatched('toast');

        $this->assertSame(Task::STATUS_TODO, $task->fresh()->status);
        $this->assertNull($task->fresh()->completed_at);
    }

    public function test_the_refusal_names_the_owner_and_says_what_to_do(): void
    {
        $task = $this->task(['assigned_to' => $this->mate->id, 'title' => 'Draft chapter 1']);

        Livewire::actingAs($this->me)
            ->test(TaskBoard::class, ['group' => $this->group])
            ->call('moveTask', $task->id, Task::STATUS_DONE)
            ->assertDispatched('toast', function (string $event, array $params) {
                return str_contains($params['message'], $this->mate->name)
                    && str_contains($params['message'], 'Draft chapter 1')
                    && str_contains($params['message'], 'reassign')
                    && $params['tone'] === 'danger';
            });
    }

    public function test_nobody_can_hand_someone_else_a_completed_task(): void
    {
        // The first hole: dropping another member's card into Done credits
        // *them* with the points, so anyone could gift a free rider a task they
        // never touched.
        $task = $this->task(['assigned_to' => $this->mate->id]);

        Livewire::actingAs($this->me)
            ->test(TaskBoard::class, ['group' => $this->group])
            ->call('moveTask', $task->id, Task::STATUS_DONE);

        $this->assertSame(0, ActivityLog::where('activity_type', 'task_completed')->count());
        $this->assertSame(0, $this->mate->contributionScoreFor($this->group));
    }

    public function test_nobody_can_wipe_someone_elses_earned_credit(): void
    {
        // The second and worse hole: dragging a finished card back out deletes
        // the contribution row, so anyone could quietly erase a groupmate's
        // credit in the middle of a marking dispute.
        $task = $this->task(['assigned_to' => $this->mate->id]);

        Livewire::actingAs($this->mate)
            ->test(TaskBoard::class, ['group' => $this->group])
            ->call('moveTask', $task->id, Task::STATUS_DONE);

        $earned = $this->mate->contributionScoreFor($this->group);
        $this->assertGreaterThan(0, $earned);

        Livewire::actingAs($this->me)
            ->test(TaskBoard::class, ['group' => $this->group])
            ->call('moveTask', $task->id, Task::STATUS_TODO)
            ->assertDispatched('toast');

        $this->assertSame(Task::STATUS_DONE, $task->fresh()->status);
        $this->assertSame($earned, $this->mate->contributionScoreFor($this->group));
    }

    public function test_an_unassigned_task_stays_open_to_everyone(): void
    {
        // Nobody owns it, and it is the one most likely to be forgotten.
        $task = $this->task(['assigned_to' => null]);

        Livewire::actingAs($this->mate)
            ->test(TaskBoard::class, ['group' => $this->group])
            ->call('moveTask', $task->id, Task::STATUS_DONE)
            ->assertNotDispatched('toast');

        $this->assertSame(Task::STATUS_DONE, $task->fresh()->status);

        // And whoever picked it up is the one credited.
        $this->assertGreaterThan(0, $this->mate->contributionScoreFor($this->group));
        $this->assertSame(0, $this->me->contributionScoreFor($this->group));
    }

    public function test_you_can_always_move_your_own_task(): void
    {
        $task = $this->task(['assigned_to' => $this->me->id]);

        Livewire::actingAs($this->me)
            ->test(TaskBoard::class, ['group' => $this->group])
            ->call('moveTask', $task->id, Task::STATUS_INPROGRESS)
            ->assertNotDispatched('toast');

        $this->assertSame(Task::STATUS_INPROGRESS, $task->fresh()->status);
    }

    public function test_reassigning_is_the_way_round_a_member_who_has_gone_quiet(): void
    {
        // The escape hatch, so a group is never stuck with a frozen card: any
        // member may reassign, which is deliberate and recorded, and then the
        // new owner can move it.
        $task = $this->task(['assigned_to' => $this->mate->id]);

        Livewire::actingAs($this->me)
            ->test(TaskBoard::class, ['group' => $this->group])
            ->call('startEdit', $task->id)
            ->set('assignedTo', $this->me->id)
            ->call('saveTask')
            ->assertHasNoErrors();

        Livewire::actingAs($this->me)
            ->test(TaskBoard::class, ['group' => $this->group])
            ->call('moveTask', $task->id, Task::STATUS_DONE)
            ->assertNotDispatched('toast');

        $this->assertSame(Task::STATUS_DONE, $task->fresh()->status);
    }

    /*
    | Editing — use case 3.4.3.3, "create, assign, update and delete"
    */

    public function test_starting_an_edit_fills_the_form_from_the_task(): void
    {
        $task = $this->task([
            'title' => 'Draft chapter 1',
            'description' => 'Introduction and problem statement',
            'assigned_to' => $this->mate->id,
            'deadline' => '2026-10-15',
        ]);

        Livewire::actingAs($this->me)
            ->test(TaskBoard::class, ['group' => $this->group])
            ->call('startEdit', $task->id)
            ->assertSet('editingTask', $task->id)
            ->assertSet('title', 'Draft chapter 1')
            ->assertSet('description', 'Introduction and problem statement')
            ->assertSet('assignedTo', $this->mate->id)
            // The date input needs Y-m-d, not the Carbon instance the cast returns.
            ->assertSet('deadline', '2026-10-15')
            ->assertSet('showForm', true);
    }

    public function test_editing_saves_every_field(): void
    {
        $task = $this->task(['title' => 'Old title', 'assigned_to' => $this->me->id]);

        Livewire::actingAs($this->me)
            ->test(TaskBoard::class, ['group' => $this->group])
            ->call('startEdit', $task->id)
            ->set('title', 'New title')
            ->set('description', 'Now with detail')
            ->set('assignedTo', $this->mate->id)
            ->set('deadline', '2026-11-01')
            ->call('saveTask')
            ->assertHasNoErrors();

        $task->refresh();

        $this->assertSame('New title', $task->title);
        $this->assertSame('Now with detail', $task->description);
        $this->assertSame($this->mate->id, $task->assigned_to);
        $this->assertSame('2026-11-01', $task->deadline->toDateString());
        $this->assertSame($this->me->id, $task->updated_by);
    }

    public function test_editing_leaves_the_column_and_the_completion_alone(): void
    {
        $task = $this->task(['status' => Task::STATUS_DONE, 'completed_at' => now()->subDay()]);
        $completedAt = $task->completed_at;

        Livewire::actingAs($this->me)
            ->test(TaskBoard::class, ['group' => $this->group])
            ->call('startEdit', $task->id)
            ->set('title', 'Renamed while done')
            ->call('saveTask')
            ->assertHasNoErrors();

        $task->refresh();

        $this->assertSame(Task::STATUS_DONE, $task->status);
        $this->assertSame($completedAt->toDateTimeString(), $task->completed_at->toDateTimeString());
    }

    public function test_editing_scores_nothing(): void
    {
        $task = $this->task();
        app(ActivityRecorder::class)->taskCreated($task, $this->me->id);

        $before = ActivityLog::where('group_id', $this->group->id)->count();

        Livewire::actingAs($this->me)
            ->test(TaskBoard::class, ['group' => $this->group])
            ->call('startEdit', $task->id)
            ->set('title', 'Edited over and over')
            ->call('saveTask')
            ->call('startEdit', $task->id)
            ->set('title', 'And again')
            ->call('saveTask');

        $this->assertSame($before, ActivityLog::where('group_id', $this->group->id)->count());
    }

    public function test_reassigning_notifies_the_new_assignee_only(): void
    {
        $task = $this->task(['assigned_to' => $this->me->id, 'title' => 'Build the ERD']);

        Livewire::actingAs($this->me)
            ->test(TaskBoard::class, ['group' => $this->group])
            ->call('startEdit', $task->id)
            ->set('assignedTo', $this->mate->id)
            ->call('saveTask')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $this->mate->id,
            'type' => 'task_assigned',
        ]);

        $this->assertDatabaseMissing('app_notifications', [
            'user_id' => $this->me->id,
            'type' => 'task_assigned',
        ]);
    }

    public function test_saving_without_changing_the_assignee_does_not_ping_them_again(): void
    {
        $task = $this->task(['assigned_to' => $this->mate->id]);

        Livewire::actingAs($this->me)
            ->test(TaskBoard::class, ['group' => $this->group])
            ->call('startEdit', $task->id)
            ->set('title', 'Only the title moved')
            ->call('saveTask');

        $this->assertSame(0, AppNotification::where('type', 'task_assigned')->count());
    }

    public function test_an_edit_cannot_assign_someone_outside_the_group(): void
    {
        $task = $this->task();
        $outsider = User::factory()->student()->create();

        Livewire::actingAs($this->me)
            ->test(TaskBoard::class, ['group' => $this->group])
            ->call('startEdit', $task->id)
            ->set('assignedTo', $outsider->id)
            ->call('saveTask')
            ->assertHasErrors('assignedTo');

        $this->assertNotSame($outsider->id, $task->fresh()->assigned_to);
    }

    public function test_an_edit_still_requires_a_title(): void
    {
        $task = $this->task();

        Livewire::actingAs($this->me)
            ->test(TaskBoard::class, ['group' => $this->group])
            ->call('startEdit', $task->id)
            ->set('title', '')
            ->call('saveTask')
            ->assertHasErrors('title');

        $this->assertSame('A task', $task->fresh()->title);
    }

    public function test_a_task_from_another_group_cannot_be_edited(): void
    {
        $otherGroup = Group::create([
            'subject_id' => $this->group->subject_id, 'name' => 'Group B', 'group_size' => 4,
        ]);
        $stranger = Task::create([
            'group_id' => $otherGroup->id, 'created_by' => $this->mate->id,
            'title' => 'Not mine', 'status' => Task::STATUS_TODO,
        ]);

        Livewire::actingAs($this->me)
            ->test(TaskBoard::class, ['group' => $this->group])
            ->call('startEdit', $stranger->id)
            ->assertNotFound();
    }

    public function test_cancelling_an_edit_clears_the_form(): void
    {
        $task = $this->task(['title' => 'Untouched']);

        Livewire::actingAs($this->me)
            ->test(TaskBoard::class, ['group' => $this->group])
            ->call('startEdit', $task->id)
            ->set('title', 'Half-typed change')
            ->call('cancelEdit')
            ->assertSet('editingTask', null)
            ->assertSet('title', '')
            ->assertSet('showForm', false);

        $this->assertSame('Untouched', $task->fresh()->title);
    }

    public function test_the_new_task_button_leaves_edit_mode(): void
    {
        $task = $this->task(['title' => 'Being edited']);

        Livewire::actingAs($this->me)
            ->test(TaskBoard::class, ['group' => $this->group])
            ->call('startEdit', $task->id)
            ->assertSet('editingTask', $task->id)
            // Reopening from the hero button always means a new task.
            ->call('toggleForm')
            ->call('toggleForm')
            ->assertSet('editingTask', null)
            ->assertSet('title', '')
            ->assertSet('showForm', true);
    }

    public function test_saving_with_no_task_open_creates_one_instead(): void
    {
        Livewire::actingAs($this->me)
            ->test(TaskBoard::class, ['group' => $this->group])
            ->set('title', 'Created through saveTask')
            ->call('saveTask')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('tasks', [
            'group_id' => $this->group->id,
            'title' => 'Created through saveTask',
        ]);
    }

    public function test_the_board_offers_an_edit_control_for_each_task(): void
    {
        $task = $this->task(['title' => 'Draft chapter 1']);

        Livewire::actingAs($this->me)
            ->test(TaskBoard::class, ['group' => $this->group])
            ->assertSee('startEdit('.$task->id.')', false)
            ->assertSee('Edit Draft chapter 1', false);
    }

    /*
    | Creating
    */

    public function test_creating_a_task_writes_one_task_created_log_at_the_configured_weight(): void
    {
        Livewire::actingAs($this->me)
            ->test(TaskBoard::class, ['group' => $this->group])
            ->set('title', 'Draft chapter 1')
            ->set('assignedTo', $this->mate->id)
            ->set('deadline', now()->addWeek()->toDateString())
            ->call('createTask')
            ->assertHasNoErrors();

        $task = Task::where('title', 'Draft chapter 1')->firstOrFail();

        $this->assertSame(Task::STATUS_TODO, $task->status);
        $this->assertSame($this->mate->id, $task->assigned_to);
        $this->assertSame($this->me->id, $task->created_by);

        $log = ActivityLog::where('activity_type', 'task_created')
            ->where('reference_id', $task->id)
            ->sole();

        // Credited to the creator, not the assignee, at the configured weight.
        $this->assertSame($this->me->id, $log->user_id);
        $this->assertSame($this->group->id, $log->group_id);
        $this->assertSame(config('contribution.weights.task_created'), $log->weight);
    }

    public function test_a_task_cannot_be_assigned_to_someone_outside_the_group(): void
    {
        $outsider = User::factory()->student()->create();

        Livewire::actingAs($this->me)
            ->test(TaskBoard::class, ['group' => $this->group])
            ->set('title', 'Sneaky')
            ->set('assignedTo', $outsider->id)
            ->call('createTask')
            ->assertHasErrors('assignedTo');

        $this->assertDatabaseMissing('tasks', ['title' => 'Sneaky']);
    }

    public function test_a_title_is_required(): void
    {
        Livewire::actingAs($this->me)
            ->test(TaskBoard::class, ['group' => $this->group])
            ->set('title', '')
            ->call('createTask')
            ->assertHasErrors('title');
    }

    /*
    | Moving
    */

    public function test_completing_a_task_stamps_completed_at_and_logs_it(): void
    {
        $task = $this->task(['assigned_to' => $this->mate->id]);

        // Moved by the member it belongs to — nobody else may, see the
        // ownership tests below.
        Livewire::actingAs($this->mate)
            ->test(TaskBoard::class, ['group' => $this->group])
            ->call('moveTask', $task->id, Task::STATUS_DONE);

        $task->refresh();

        $this->assertSame(Task::STATUS_DONE, $task->status);
        $this->assertNotNull($task->completed_at);

        $log = ActivityLog::where('activity_type', 'task_completed')
            ->where('reference_id', $task->id)
            ->sole();

        // Credited to the assignee, not whoever dragged the card.
        $this->assertSame($this->mate->id, $log->user_id);
        $this->assertSame(config('contribution.weights.task_completed'), $log->weight);
    }

    public function test_an_unassigned_task_credits_whoever_completed_it(): void
    {
        $task = $this->task(['assigned_to' => null]);

        Livewire::actingAs($this->me)
            ->test(TaskBoard::class, ['group' => $this->group])
            ->call('moveTask', $task->id, Task::STATUS_DONE);

        $this->assertSame(
            $this->me->id,
            ActivityLog::where('activity_type', 'task_completed')->where('reference_id', $task->id)->value('user_id'),
        );
    }

    public function test_moving_back_out_of_done_clears_completed_at_and_removes_the_log(): void
    {
        $task = $this->task();

        $board = Livewire::actingAs($this->me)->test(TaskBoard::class, ['group' => $this->group]);

        $board->call('moveTask', $task->id, Task::STATUS_DONE);
        $this->assertSame(1, ActivityLog::where('activity_type', 'task_completed')->where('reference_id', $task->id)->count());

        $board->call('moveTask', $task->id, Task::STATUS_INPROGRESS);

        $task->refresh();

        $this->assertSame(Task::STATUS_INPROGRESS, $task->status);
        $this->assertNull($task->completed_at);
        $this->assertSame(0, ActivityLog::where('activity_type', 'task_completed')->where('reference_id', $task->id)->count());
    }

    public function test_completing_twice_does_not_double_log(): void
    {
        $task = $this->task();

        $board = Livewire::actingAs($this->me)->test(TaskBoard::class, ['group' => $this->group]);

        $board->call('moveTask', $task->id, Task::STATUS_DONE);
        $board->call('moveTask', $task->id, Task::STATUS_DONE);

        $this->assertSame(1, ActivityLog::where('activity_type', 'task_completed')->where('reference_id', $task->id)->count());
    }

    public function test_an_unknown_status_is_ignored(): void
    {
        $task = $this->task();

        Livewire::actingAs($this->me)
            ->test(TaskBoard::class, ['group' => $this->group])
            ->call('moveTask', $task->id, 'archived');

        $this->assertSame(Task::STATUS_TODO, $task->fresh()->status);
    }

    /*
    | Deleting
    */

    public function test_deleting_a_task_removes_it_and_its_contribution_rows(): void
    {
        $task = $this->task();

        $board = Livewire::actingAs($this->me)->test(TaskBoard::class, ['group' => $this->group]);

        // Give the task both kinds of row, then delete it.
        app(ActivityRecorder::class)->taskCreated($task, $this->me->id);
        $board->call('moveTask', $task->id, Task::STATUS_DONE);

        $this->assertSame(2, ActivityLog::where('reference_id', $task->id)
            ->whereIn('activity_type', ['task_created', 'task_completed'])->count());

        $board->call('confirmDelete', $task->id)->call('deleteTask');

        $this->assertDatabaseMissing('tasks', ['id' => $task->id]);
        $this->assertSame(0, ActivityLog::where('reference_id', $task->id)
            ->whereIn('activity_type', ['task_created', 'task_completed'])->count());
    }

    /*
    | Scoping
    */

    public function test_a_student_cannot_mount_another_groups_board(): void
    {
        $otherGroup = Group::create([
            'subject_id' => $this->group->subject_id, 'name' => 'Group B', 'group_size' => 4,
        ]);

        $this->actingAs($this->me)
            ->get(route('student.board.show', $otherGroup))
            ->assertNotFound();
    }

    public function test_a_task_from_another_group_cannot_be_moved(): void
    {
        $otherGroup = Group::create([
            'subject_id' => $this->group->subject_id, 'name' => 'Group B', 'group_size' => 4,
        ]);
        $foreign = Task::create([
            'group_id' => $otherGroup->id, 'created_by' => $this->me->id,
            'title' => 'Not yours', 'status' => Task::STATUS_TODO,
        ]);

        Livewire::actingAs($this->me)
            ->test(TaskBoard::class, ['group' => $this->group])
            ->call('moveTask', $foreign->id, Task::STATUS_DONE)
            ->assertStatus(404);

        $this->assertSame(Task::STATUS_TODO, $foreign->fresh()->status);
    }

    public function test_a_task_from_another_group_cannot_be_deleted(): void
    {
        $otherGroup = Group::create([
            'subject_id' => $this->group->subject_id, 'name' => 'Group B', 'group_size' => 4,
        ]);
        $foreign = Task::create([
            'group_id' => $otherGroup->id, 'created_by' => $this->me->id,
            'title' => 'Not yours', 'status' => Task::STATUS_TODO,
        ]);

        Livewire::actingAs($this->me)
            ->test(TaskBoard::class, ['group' => $this->group])
            ->call('confirmDelete', $foreign->id)
            ->assertStatus(404);

        $this->assertDatabaseHas('tasks', ['id' => $foreign->id]);
    }

    /*
    | Rendering
    */

    public function test_the_board_renders_three_columns_and_the_overdue_pill(): void
    {
        $this->task(['title' => 'Queued']);
        $this->task(['title' => 'Started', 'status' => Task::STATUS_INPROGRESS]);
        $this->task(['title' => 'Finished', 'status' => Task::STATUS_DONE, 'completed_at' => now()]);
        $this->task(['title' => 'Late one', 'deadline' => now()->subWeek()->toDateString()]);

        Livewire::actingAs($this->me)
            ->test(TaskBoard::class, ['group' => $this->group])
            ->assertSee('To Do')
            ->assertSee('In Progress')
            ->assertSee('Done')
            ->assertSee('Queued')
            ->assertSee('Started')
            ->assertSee('Finished')
            ->assertSee('Late one')
            ->assertSee('Overdue');
    }

    public function test_empty_columns_show_their_own_empty_state(): void
    {
        Livewire::actingAs($this->me)
            ->test(TaskBoard::class, ['group' => $this->group])
            ->assertSee('Nothing queued')
            ->assertSee('No one has started')
            ->assertSee('Nothing finished yet');
    }
}
