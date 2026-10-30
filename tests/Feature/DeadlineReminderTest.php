<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Subject;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The scheduled deadline notifications.
 *
 * The property that matters most here is that running the command twice, or
 * every day for a week, does not produce a second notification for the same
 * task. A reminder that repeats itself daily is one people learn to ignore,
 * which would defeat the whole feature.
 */
class DeadlineReminderTest extends TestCase
{
    use RefreshDatabase;

    protected User $lecturer;

    protected User $owner;

    protected User $mate;

    protected Group $group;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lecturer = User::factory()->lecturer()->create(['must_change_password' => false]);

        $subject = Subject::create([
            'lecturer_id' => $this->lecturer->id,
            'code' => 'SECJ3104',
            'name' => 'App Dev',
            'group_size' => 4,
        ]);

        $this->group = Group::create([
            'subject_id' => $subject->id,
            'name' => 'Group A',
            'group_size' => 4,
        ]);

        $this->owner = User::factory()->student()->create(['must_change_password' => false]);
        $this->mate = User::factory()->student()->create(['must_change_password' => false]);

        foreach ([$this->owner, $this->mate] as $member) {
            GroupMember::create(['group_id' => $this->group->id, 'user_id' => $member->id, 'joined_at' => now()]);
        }
    }

    protected function task(array $overrides = []): Task
    {
        return Task::create([
            'group_id' => $this->group->id,
            'created_by' => $this->owner->id,
            'assigned_to' => $this->owner->id,
            'title' => 'Draft chapter 1',
            'status' => Task::STATUS_TODO,
            ...$overrides,
        ]);
    }

    protected function remind(array $options = []): void
    {
        $this->artisan('collabspace:deadline-reminders', $options)->assertSuccessful();
    }

    protected function notifications(User $user, string $type)
    {
        return AppNotification::where('user_id', $user->id)->where('type', $type);
    }

    /*
    | Due soon
    */

    public function test_a_task_due_inside_the_lead_time_notifies_its_assignee(): void
    {
        $task = $this->task(['deadline' => now()->addDay()->toDateString()]);

        $this->remind();

        $this->assertSame(1, $this->notifications($this->owner, 'deadline')->count());

        $notification = $this->notifications($this->owner, 'deadline')->sole();

        $this->assertSame($task->id, $notification->reference_id);
        $this->assertStringContainsString('Draft chapter 1', $notification->message);
        $this->assertStringContainsString('tomorrow', $notification->message);
    }

    public function test_a_task_due_today_says_so(): void
    {
        $this->task(['deadline' => now()->toDateString()]);

        $this->remind();

        $this->assertStringContainsString(
            'today',
            $this->notifications($this->owner, 'deadline')->sole()->message,
        );
    }

    public function test_a_task_beyond_the_lead_time_stays_quiet(): void
    {
        $this->task(['deadline' => now()->addMonth()->toDateString()]);

        $this->remind();

        $this->assertSame(0, AppNotification::count());
    }

    public function test_the_lead_time_can_be_widened(): void
    {
        $this->task(['deadline' => now()->addDays(6)->toDateString()]);

        $this->remind();
        $this->assertSame(0, AppNotification::count());

        $this->remind(['--days' => 7]);
        $this->assertSame(1, $this->notifications($this->owner, 'deadline')->count());
    }

    public function test_an_unassigned_task_goes_to_the_whole_group(): void
    {
        // Nobody owns it, so nobody would otherwise hear about it — and an
        // unassigned task with a deadline is the one most likely to be missed.
        $this->task(['assigned_to' => null, 'deadline' => now()->addDay()->toDateString()]);

        $this->remind();

        $this->assertSame(1, $this->notifications($this->owner, 'deadline')->count());
        $this->assertSame(1, $this->notifications($this->mate, 'deadline')->count());
    }

    /*
    | Overdue
    */

    public function test_an_overdue_task_notifies_the_assignee_and_the_lecturer(): void
    {
        $task = $this->task(['deadline' => now()->subDays(3)->toDateString()]);

        $this->remind();

        $this->assertSame(1, $this->notifications($this->owner, 'task_overdue')->count());

        $forLecturer = $this->notifications($this->lecturer, 'task_overdue')->sole();

        $this->assertSame($task->id, $forLecturer->reference_id);
        $this->assertStringContainsString('Group A', $forLecturer->message);
        $this->assertStringContainsString('SECJ3104', $forLecturer->message);
        $this->assertStringContainsString('/lecturer/progress/'.$this->group->id, $forLecturer->link);
    }

    public function test_the_lecturer_can_be_left_out_by_configuration(): void
    {
        config(['notifications.notify_lecturer_on_overdue' => false]);

        $this->task(['deadline' => now()->subDay()->toDateString()]);

        $this->remind();

        $this->assertSame(1, $this->notifications($this->owner, 'task_overdue')->count());
        $this->assertSame(0, $this->notifications($this->lecturer, 'task_overdue')->count());
    }

    public function test_an_overdue_task_is_not_also_announced_as_due_soon(): void
    {
        $this->task(['deadline' => now()->subDay()->toDateString()]);

        $this->remind();

        $this->assertSame(0, $this->notifications($this->owner, 'deadline')->count());
        $this->assertSame(1, $this->notifications($this->owner, 'task_overdue')->count());
    }

    /*
    | Not everybody's problem
    */

    public function test_a_finished_task_is_never_chased(): void
    {
        $this->task([
            'status' => Task::STATUS_DONE,
            'completed_at' => now(),
            'deadline' => now()->subWeek()->toDateString(),
        ]);

        $this->remind();

        $this->assertSame(0, AppNotification::count());
    }

    public function test_a_task_with_no_deadline_cannot_be_late(): void
    {
        $this->task(['deadline' => null]);

        $this->remind();

        $this->assertSame(0, AppNotification::count());
    }

    public function test_only_the_owning_lecturer_hears_about_it(): void
    {
        $stranger = User::factory()->lecturer()->create();

        $this->task(['deadline' => now()->subDay()->toDateString()]);

        $this->remind();

        $this->assertSame(0, AppNotification::where('user_id', $stranger->id)->count());
    }

    /*
    | The subject's own project deadline
    */

    public function test_an_approaching_project_deadline_reaches_every_student_in_the_subject(): void
    {
        $subject = $this->group->subject;
        $subject->forceFill(['project_deadline' => now()->addDays(3)->toDateString()])->save();

        $this->remind();

        foreach ([$this->owner, $this->mate] as $student) {
            $notification = $this->notifications($student, 'project_deadline')->sole();

            $this->assertSame($subject->id, $notification->reference_id);
            $this->assertStringContainsString('SECJ3104', $notification->message);
            $this->assertStringContainsString('due in 3 days', $notification->message);
        }
    }

    public function test_a_project_due_today_or_tomorrow_says_so(): void
    {
        $subject = $this->group->subject;

        $subject->forceFill(['project_deadline' => now()->toDateString()])->save();
        $this->remind();

        $this->assertStringContainsString(
            'due today',
            $this->notifications($this->owner, 'project_deadline')->sole()->message,
        );
    }

    public function test_a_project_further_out_than_the_lead_time_stays_quiet(): void
    {
        // The lead time for a project is longer than for a task, but it is
        // still a lead time — a deadline three months off is not news.
        $this->group->subject->forceFill([
            'project_deadline' => now()->addDays(60)->toDateString(),
        ])->save();

        $this->remind();

        $this->assertSame(0, AppNotification::count());
    }

    public function test_the_project_lead_time_is_its_own_setting(): void
    {
        $this->group->subject->forceFill([
            'project_deadline' => now()->addDays(20)->toDateString(),
        ])->save();

        $this->remind();
        $this->assertSame(0, AppNotification::count());

        config(['notifications.project_reminder_days' => 30]);

        $this->remind();
        $this->assertSame(1, $this->notifications($this->owner, 'project_deadline')->count());
    }

    public function test_a_project_deadline_already_past_is_not_announced(): void
    {
        $this->group->subject->forceFill([
            'project_deadline' => now()->subDay()->toDateString(),
        ])->save();

        $this->remind();

        $this->assertSame(0, AppNotification::count());
    }

    public function test_a_subject_with_no_groups_has_nobody_to_tell(): void
    {
        $empty = Subject::create([
            'lecturer_id' => $this->lecturer->id,
            'code' => 'SECR1013',
            'name' => 'Networks',
            'project_deadline' => now()->addDay()->toDateString(),
            'group_size' => 4,
        ]);

        $this->remind();

        $this->assertSame(0, AppNotification::where('reference_id', $empty->id)
            ->where('type', 'project_deadline')
            ->count());
    }

    public function test_a_project_reminder_is_sent_once_however_often_it_runs(): void
    {
        $this->group->subject->forceFill([
            'project_deadline' => now()->addDays(3)->toDateString(),
        ])->save();

        $this->remind();
        $this->remind();

        $this->travel(2)->days();

        $this->remind();

        $this->assertSame(1, $this->notifications($this->owner, 'project_deadline')->count());
    }

    public function test_a_project_reminder_does_not_collide_with_a_task_reminder(): void
    {
        // reference_id is a plain column shared across tables, so a subject id
        // and a task id can be the same integer. The type has to keep them
        // apart, or one would silently suppress the other.
        $subject = $this->group->subject;
        $subject->forceFill(['project_deadline' => now()->addDays(3)->toDateString()])->save();

        $task = $this->task(['deadline' => now()->addDay()->toDateString()]);

        $this->remind();

        $this->assertSame(1, $this->notifications($this->owner, 'project_deadline')->count());
        $this->assertSame(1, $this->notifications($this->owner, 'deadline')->count());

        $this->assertSame(
            $subject->id,
            $this->notifications($this->owner, 'project_deadline')->sole()->reference_id,
        );
        $this->assertSame(
            $task->id,
            $this->notifications($this->owner, 'deadline')->sole()->reference_id,
        );
    }

    /*
    | Idempotence — the reason this is safe to schedule daily
    */

    public function test_running_twice_says_nothing_the_second_time(): void
    {
        $this->task(['deadline' => now()->addDay()->toDateString()]);

        $this->remind();
        $this->remind();
        $this->remind();

        $this->assertSame(1, $this->notifications($this->owner, 'deadline')->count());
    }

    public function test_a_task_that_stays_overdue_is_not_chased_every_day(): void
    {
        $this->task(['deadline' => now()->subDay()->toDateString()]);

        $this->remind();

        $this->travel(5)->days();

        $this->remind();

        $this->assertSame(1, $this->notifications($this->owner, 'task_overdue')->count());
        $this->assertSame(1, $this->notifications($this->lecturer, 'task_overdue')->count());
    }

    public function test_a_task_that_slips_from_due_soon_to_overdue_gets_both(): void
    {
        // Two different states of the same task, so two notifications is right:
        // "due tomorrow" and "you have missed it" are not the same message.
        $this->task(['deadline' => now()->addDay()->toDateString()]);

        $this->remind();

        $this->travel(4)->days();

        $this->remind();

        $this->assertSame(1, $this->notifications($this->owner, 'deadline')->count());
        $this->assertSame(1, $this->notifications($this->owner, 'task_overdue')->count());
    }

    public function test_a_member_who_already_read_the_reminder_is_not_told_again(): void
    {
        $this->task(['deadline' => now()->addDay()->toDateString()]);

        $this->remind();

        AppNotification::query()->update(['is_read' => true]);

        $this->remind();

        $this->assertSame(1, $this->notifications($this->owner, 'deadline')->count());
    }

    /*
    | The command itself
    */

    public function test_a_dry_run_writes_nothing(): void
    {
        $this->task(['deadline' => now()->addDay()->toDateString()]);

        $this->artisan('collabspace:deadline-reminders', ['--dry-run' => true])
            ->expectsOutputToContain('Dry run')
            ->assertSuccessful();

        $this->assertSame(0, AppNotification::count());
    }

    public function test_a_negative_lead_time_is_refused(): void
    {
        $this->artisan('collabspace:deadline-reminders', ['--days' => -1])->assertFailed();
    }

    public function test_the_command_is_on_the_schedule(): void
    {
        // Without this the command exists but never runs, which is the same as
        // not having built it.
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->map(fn ($event) => $event->command)
            ->filter();

        $this->assertTrue(
            $events->contains(fn ($command) => str_contains($command, 'collabspace:deadline-reminders')),
            'The deadline reminder command is not scheduled.',
        );
    }
}
