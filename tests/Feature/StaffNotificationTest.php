<?php

namespace Tests\Feature;

use App\Livewire\TaskBoard;
use App\Models\AppNotification;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Subject;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Notifications for the two roles that used to receive none.
 *
 * Every Notifier method wrote to students, so the lecturer and administrator
 * notification screens existed but could never fill. These cover what now
 * reaches them — and, just as importantly, what deliberately does not: a
 * lecturer teaching several groups must not be handed every chat message, and
 * an administrator must not be told about the thing they just did themselves.
 */
class StaffNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function admin(string $name = 'Admin One'): User
    {
        return User::factory()->admin()->create(['name' => $name, 'must_change_password' => false]);
    }

    protected function inbox(User $user, ?string $type = null)
    {
        return AppNotification::where('user_id', $user->id)
            ->when($type, fn ($q) => $q->where('type', $type));
    }

    /*
    | Lecturer — a group finishing its board
    */

    public function test_finishing_the_last_task_tells_the_lecturer(): void
    {
        [$lecturer, $group, $student] = $this->group();

        $first = $this->task($group, $student, 'Draft chapter 1');
        $second = $this->task($group, $student, 'Draft chapter 2');

        $board = Livewire::actingAs($student)->test(TaskBoard::class, ['group' => $group]);

        $board->call('moveTask', $first->id, Task::STATUS_DONE);

        // One still open, so nothing yet.
        $this->assertSame(0, $this->inbox($lecturer, 'group_completed')->count());

        $board->call('moveTask', $second->id, Task::STATUS_DONE);

        $notification = $this->inbox($lecturer, 'group_completed')->sole();

        $this->assertStringContainsString('Group A', $notification->message);
        $this->assertStringContainsString('all 2', $notification->message);
        $this->assertStringContainsString('/lecturer/progress/'.$group->id, $notification->link);
    }

    public function test_a_group_is_only_congratulated_once(): void
    {
        [$lecturer, $group, $student] = $this->group();

        $task = $this->task($group, $student, 'Only task');

        $board = Livewire::actingAs($student)->test(TaskBoard::class, ['group' => $group]);

        // Finished, reopened, finished again — one milestone, not three.
        $board->call('moveTask', $task->id, Task::STATUS_DONE)
            ->call('moveTask', $task->id, Task::STATUS_TODO)
            ->call('moveTask', $task->id, Task::STATUS_DONE);

        $this->assertSame(1, $this->inbox($lecturer, 'group_completed')->count());
    }

    public function test_an_empty_board_is_not_a_finished_one(): void
    {
        [$lecturer, $group, $student] = $this->group();

        Livewire::actingAs($student)->test(TaskBoard::class, ['group' => $group]);

        $this->assertSame(0, $this->inbox($lecturer, 'group_completed')->count());
    }

    public function test_a_lecturer_is_not_sent_the_groups_chatter(): void
    {
        [$lecturer, $group, $student] = $this->group();

        $task = $this->task($group, $student, 'Something');

        Livewire::actingAs($student)
            ->test(TaskBoard::class, ['group' => $group])
            ->set('title', 'A new task')
            ->call('createTask');

        // Creating work, assigning it and moving it about is the group's own
        // business — the monitoring screen shows it far better than a bell.
        Livewire::actingAs($student)
            ->test(TaskBoard::class, ['group' => $group])
            ->call('moveTask', $task->id, Task::STATUS_INPROGRESS);

        $this->assertSame(0, $this->inbox($lecturer)->count());
    }

    /*
    | Administrator — account lifecycle
    */

    public function test_setting_your_own_password_tells_every_administrator(): void
    {
        $first = $this->admin('Admin One');
        $second = $this->admin('Admin Two');

        $user = User::factory()->student()->create([
            'name' => 'Nurul Izzah',
            'password' => 'temp-password',
            'must_change_password' => true,
        ]);

        $this->actingAs($user)->put(route('password.change.update'), [
            'current_password' => 'temp-password',
            'password' => 'a-much-better-password',
            'password_confirmation' => 'a-much-better-password',
        ])->assertRedirect(route('dashboard'));

        foreach ([$first, $second] as $admin) {
            $notification = $this->inbox($admin, 'account_activated')->sole();

            $this->assertStringContainsString('Nurul Izzah', $notification->message);
            $this->assertSame($user->id, $notification->reference_id);
        }
    }

    public function test_creating_an_account_tells_the_other_administrators(): void
    {
        $actor = $this->admin('Admin One');
        $other = $this->admin('Admin Two');

        $this->actingAs($actor)->post(route('admin.users.store'), [
            'name' => 'Dr Tan',
            'email' => 'tan@collabspace.test',
            'role' => 'lecturer',
        ])->assertSessionHasNoErrors();

        $notification = $this->inbox($other, 'account_created')->sole();

        $this->assertStringContainsString('Admin One', $notification->message);
        $this->assertStringContainsString('Dr Tan', $notification->message);

        // Telling you what you just did is noise.
        $this->assertSame(0, $this->inbox($actor, 'account_created')->count());
    }

    public function test_deactivating_and_reactivating_both_report(): void
    {
        $actor = $this->admin('Admin One');
        $other = $this->admin('Admin Two');
        $user = User::factory()->create(['name' => 'Farah Aqilah']);

        $this->actingAs($actor)->put(route('admin.users.status', $user));
        $this->actingAs($actor)->put(route('admin.users.status', $user));

        $messages = $this->inbox($other, 'account_status')->orderBy('id')->pluck('message');

        $this->assertCount(2, $messages);
        $this->assertStringContainsString('deactivated', $messages[0]);
        $this->assertStringContainsString('reactivated', $messages[1]);
    }

    public function test_issuing_a_temporary_password_reports(): void
    {
        $actor = $this->admin('Admin One');
        $other = $this->admin('Admin Two');
        $user = User::factory()->create(['name' => 'Syafiq Idris']);

        $this->actingAs($actor)->put(route('admin.users.password', $user));

        $this->assertStringContainsString(
            'Syafiq Idris',
            $this->inbox($other, 'account_password')->sole()->message,
        );
    }

    public function test_a_deactivated_administrator_is_not_notified(): void
    {
        $actor = $this->admin('Admin One');
        $retired = User::factory()->admin()->deactivated()->create(['name' => 'Admin Gone']);

        $this->actingAs($actor)->post(route('admin.users.store'), [
            'name' => 'Dr Tan',
            'email' => 'tan@collabspace.test',
            'role' => 'lecturer',
        ]);

        $this->assertSame(0, $this->inbox($retired)->count());
    }

    public function test_lecturers_and_students_hear_nothing_about_other_accounts(): void
    {
        $actor = $this->admin();
        $lecturer = User::factory()->lecturer()->create();
        $student = User::factory()->student()->create();

        $this->actingAs($actor)->post(route('admin.users.store'), [
            'name' => 'Dr Tan',
            'email' => 'tan@collabspace.test',
            'role' => 'lecturer',
        ]);

        $this->assertSame(0, $this->inbox($lecturer)->count());
        $this->assertSame(0, $this->inbox($student)->count());
    }

    /*
    | The screens themselves
    */

    public function test_the_notification_screen_renders_for_a_lecturer(): void
    {
        [$lecturer, $group, $student] = $this->group();

        $task = $this->task($group, $student, 'Only task');

        Livewire::actingAs($student)
            ->test(TaskBoard::class, ['group' => $group])
            ->call('moveTask', $task->id, Task::STATUS_DONE);

        $this->actingAs($lecturer)
            ->get(route('lecturer.notifications'))
            ->assertOk()
            ->assertSee('Group A');
    }

    public function test_the_notification_screen_renders_for_an_administrator(): void
    {
        $actor = $this->admin('Admin One');
        $other = $this->admin('Admin Two');

        $this->actingAs($actor)->post(route('admin.users.store'), [
            'name' => 'Dr Tan',
            'email' => 'tan@collabspace.test',
            'role' => 'lecturer',
        ]);

        $this->actingAs($other)
            ->get(route('admin.notifications'))
            ->assertOk()
            ->assertSee('Dr Tan');
    }

    /* Helpers */

    /** @return array{0: User, 1: Group, 2: User} */
    protected function group(): array
    {
        $lecturer = User::factory()->lecturer()->create(['must_change_password' => false]);

        $subject = Subject::create([
            'lecturer_id' => $lecturer->id, 'code' => 'SECJ3104', 'name' => 'App Dev', 'group_size' => 4,
        ]);

        $group = Group::create(['subject_id' => $subject->id, 'name' => 'Group A', 'group_size' => 4]);

        $student = User::factory()->student()->create(['must_change_password' => false]);
        GroupMember::create(['group_id' => $group->id, 'user_id' => $student->id, 'joined_at' => now()]);

        return [$lecturer, $group, $student];
    }

    protected function task(Group $group, User $student, string $title): Task
    {
        return Task::create([
            'group_id' => $group->id,
            'created_by' => $student->id,
            'assigned_to' => $student->id,
            'title' => $title,
            'status' => Task::STATUS_TODO,
        ]);
    }
}
