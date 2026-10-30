<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Group;
use App\Models\Message;
use App\Models\Subject;
use App\Models\Task;
use App\Models\User;
use App\Support\ActivityRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The application runs in Malaysia time: "today" ends at midnight there, not at
 * 08:00, and a stored time is read back in the same zone.
 */
class TimezoneTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function group(): array
    {
        $lecturer = User::factory()->lecturer()->create();
        $subject = Subject::create(['lecturer_id' => $lecturer->id, 'code' => 'SECJ3104', 'name' => 'App Dev', 'group_size' => 4]);
        $group = Group::create(['subject_id' => $subject->id, 'name' => 'My Group', 'group_size' => 4]);
        $student = User::factory()->student()->create();

        return [$group, $student];
    }


    public function test_the_application_timezone_is_kuala_lumpur(): void
    {
        $this->assertSame('Asia/Kuala_Lumpur', config('app.timezone'));
        $this->assertSame('Asia/Kuala_Lumpur', now()->timezoneName);
        $this->assertSame('+08:00', now()->format('P'));
    }

    public function test_messages_at_2330_and_0030_the_next_day_in_malaysia_count_as_different_days(): void
    {
        config(['contribution.message_cap_per_day' => 1]);

        [$group, $student] = $this->group();
        $recorder = app(ActivityRecorder::class);

        $late = Message::create(['group_id' => $group->id, 'user_id' => $student->id, 'body' => 'Late']);
        $early = Message::create(['group_id' => $group->id, 'user_id' => $student->id, 'body' => 'Early']);
        $again = Message::create(['group_id' => $group->id, 'user_id' => $student->id, 'body' => 'Again']);

        $at2330 = Carbon::parse('2026-10-30 23:30', 'Asia/Kuala_Lumpur');
        $at0030 = Carbon::parse('2026-10-31 00:30', 'Asia/Kuala_Lumpur');

        // In UTC these are 15:30 and 16:30 on the same day, so a UTC day would cap the second.
        $this->assertSame($at2330->copy()->utc()->toDateString(), $at0030->copy()->utc()->toDateString());

        $this->assertNotNull($recorder->messageSent($late, $at2330), 'First message of 30 Oct.');
        $this->assertNotNull($recorder->messageSent($early, $at0030), 'First message of 31 Oct: a new day, so it counts.');
        $this->assertNull($recorder->messageSent($again, $at0030->copy()->addMinutes(5)), 'Second on 31 Oct is over the cap.');

        $this->assertSame(2, ActivityLog::where('activity_type', 'message_sent')->count());
    }

    public function test_a_stored_time_is_read_back_in_malaysia_time(): void
    {
        [$group, $student] = $this->group();

        Carbon::setTestNow(Carbon::parse('2026-10-30 18:57:00', 'UTC'));   // 02:57 on 31 Oct in Malaysia

        $message = Message::create(['group_id' => $group->id, 'user_id' => $student->id, 'body' => 'Hi']);
        $read = Message::find($message->id)->created_at;

        $this->assertSame('31 Oct 2026, 2:57am', $read->format('j M Y, g:ia'));
        $this->assertSame('2026-10-31', $read->toDateString());
    }

    public function test_a_task_due_that_day_is_not_overdue_at_2300_but_is_after_midnight(): void
    {
        [$group, $student] = $this->group();

        $task = Task::create(['group_id' => $group->id, 'title' => 'Report', 'status' => 'todo', 'deadline' => '2026-10-30', 'created_by' => $student->id]);

        Carbon::setTestNow(Carbon::parse('2026-10-30 23:00', 'Asia/Kuala_Lumpur'));
        $this->assertFalse($task->fresh()->isOverdue(), 'Due on the 30th; it is still the 30th.');
        $this->assertSame(0, Task::overdue()->count());

        Carbon::setTestNow(Carbon::parse('2026-10-30 23:59', 'Asia/Kuala_Lumpur'));
        $this->assertFalse($task->fresh()->isOverdue());

        Carbon::setTestNow(Carbon::parse('2026-10-31 00:01', 'Asia/Kuala_Lumpur'));
        $this->assertTrue($task->fresh()->isOverdue());
        $this->assertSame(1, Task::overdue()->count(), 'The scope and the method agree.');
    }

    public function test_a_finished_task_is_never_overdue(): void
    {
        [$group, $student] = $this->group();

        $task = Task::create(['group_id' => $group->id, 'title' => 'Report', 'status' => 'done', 'deadline' => '2026-10-01', 'created_by' => $student->id]);

        $this->assertFalse($task->isOverdue());
    }
}
