<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\Group;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ShiftStoredTimesTest extends TestCase
{
    use RefreshDatabase;

    protected string $marker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->marker = storage_path('app/private/stored-times-shifted.json');
        File::delete($this->marker);
    }

    protected function tearDown(): void
    {
        File::delete($this->marker);

        parent::tearDown();
    }

    protected function seedMessage(): Message
    {
        $lecturer = User::factory()->lecturer()->create();
        $subject = Subject::create(['lecturer_id' => $lecturer->id, 'code' => 'SECJ3104', 'name' => 'App Dev', 'group_size' => 4]);
        $group = Group::create(['subject_id' => $subject->id, 'name' => 'G', 'group_size' => 4]);
        $student = User::factory()->student()->create();
        $message = Message::create(['group_id' => $group->id, 'user_id' => $student->id, 'body' => 'Hi']);

        DB::table('messages')->where('id', $message->id)->update(['created_at' => '2026-10-30 18:57:00']);

        return $message;
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        $message = $this->seedMessage();

        $this->artisan('app:shift-stored-times --dry-run')->assertSuccessful();

        $this->assertSame('2026-10-30 18:57:00', DB::table('messages')->where('id', $message->id)->value('created_at'));
        $this->assertFileDoesNotExist($this->marker);
    }

    public function test_stored_utc_times_move_forward_eight_hours_once(): void
    {
        $message = $this->seedMessage();

        $this->artisan('app:shift-stored-times')->assertSuccessful();

        $this->assertSame('2026-10-31 02:57:00', DB::table('messages')->where('id', $message->id)->value('created_at'));
        $this->assertSame('31 Oct 2026, 2:57am', Message::find($message->id)->created_at->format('j M Y, g:ia'));
    }

    public function test_it_refuses_to_run_a_second_time(): void
    {
        $message = $this->seedMessage();

        $this->artisan('app:shift-stored-times')->assertSuccessful();
        $this->artisan('app:shift-stored-times')->assertFailed();

        $this->assertSame('2026-10-31 02:57:00', DB::table('messages')->where('id', $message->id)->value('created_at'), 'Not moved twice.');
    }

    public function test_date_only_columns_are_left_alone(): void
    {
        $message = $this->seedMessage();
        $group = Group::first();

        \App\Models\Task::create(['group_id' => $group->id, 'title' => 'T', 'status' => 'todo', 'deadline' => '2026-10-30', 'created_by' => User::first()->id]);

        $this->artisan('app:shift-stored-times')->assertSuccessful();

        $this->assertSame('2026-10-30', substr((string) DB::table('tasks')->value('deadline'), 0, 10));
    }
}
