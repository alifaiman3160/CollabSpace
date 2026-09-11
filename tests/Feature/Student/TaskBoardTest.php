<?php

namespace Tests\Feature\Student;

use App\Livewire\TaskBoard;
use App\Models\ActivityLog;
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

        Livewire::actingAs($this->me)
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
