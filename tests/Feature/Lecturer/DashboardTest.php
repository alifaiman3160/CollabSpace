<?php

namespace Tests\Feature\Lecturer;

use App\Models\Announcement;
use App\Models\AnnouncementRead;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Subject;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The lecturer dashboard: a card for each subject (with the shared announcements strip), the totals, the
 * groups that need attention, and recent activity.
 */
class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected User $lecturer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lecturer = User::factory()->lecturer()->create(['must_change_password' => false]);
    }

    protected function subject(?User $owner = null, string $code = 'SECJ3104'): Subject
    {
        return Subject::create(['lecturer_id' => ($owner ?? $this->lecturer)->id, 'code' => $code, 'name' => "Name of {$code}", 'group_size' => 4]);
    }

    protected function group(Subject $subject, string $name, int $done = 0, int $todo = 0, int $overdue = 0): Group
    {
        $group = Group::create(['subject_id' => $subject->id, 'name' => $name, 'group_size' => 4]);
        $student = User::factory()->student()->create();
        GroupMember::create(['group_id' => $group->id, 'user_id' => $student->id, 'joined_at' => now()]);

        for ($i = 0; $i < $done; $i++) {
            $this->task($group, $student, Task::STATUS_DONE);
        }
        for ($i = 0; $i < $todo; $i++) {
            $this->task($group, $student, 'todo');
        }
        for ($i = 0; $i < $overdue; $i++) {
            $this->task($group, $student, 'todo', now()->subDays(3));
        }

        return $group;
    }

    protected function task(Group $group, User $student, string $status, $deadline = null): Task
    {
        return Task::create([
            'group_id' => $group->id, 'created_by' => $student->id, 'assigned_to' => $student->id,
            'title' => 'Task '.str()->random(5), 'status' => $status, 'deadline' => $deadline,
        ]);
    }

    protected function page(): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->lecturer)->get(route('lecturer.dashboard'))->assertOk();
    }

    public function test_it_renders_for_a_lecturer_with_no_one_one_or_three_subjects(): void
    {
        $this->page()->assertSee('No subjects yet')->assertDontSee('data-subject-card', false);

        $one = $this->subject(code: 'AAA1111');
        $this->group($one, 'Group One');
        $html = $this->page()->getContent();
        $this->assertSame(1, substr_count($html, 'data-subject-card'));

        $this->subject(code: 'BBB2222');
        $this->subject(code: 'CCC3333');
        $html = $this->page()->getContent();
        $this->assertSame(3, substr_count($html, 'data-subject-card'));
    }

    public function test_only_the_lecturers_own_subjects_and_groups_show(): void
    {
        $mine = $this->subject(code: 'MINE1111');
        $this->group($mine, 'My Group');

        $other = User::factory()->lecturer()->create();
        $theirs = $this->subject($other, 'THEIRS222');
        $this->group($theirs, 'Their Group');
        Announcement::create(['subject_id' => $theirs->id, 'author_id' => $other->id, 'title' => 'Not for you', 'body' => 'x']);

        $this->page()
            ->assertSee('MINE1111')->assertSee('My Group')
            ->assertDontSee('THEIRS222')->assertDontSee('Their Group')->assertDontSee('Not for you');
    }

    public function test_groups_needing_attention_are_ordered_by_overdue_then_lowest_progress(): void
    {
        $subject = $this->subject();
        $this->group($subject, 'Alpha Steady', done: 3, todo: 1);          // 75%, no overdue
        $this->group($subject, 'Bravo Behind', done: 1, todo: 3);          // 25%, no overdue
        $this->group($subject, 'Charlie Late', done: 1, overdue: 1);       // 1 overdue
        $this->group($subject, 'Delta Later', done: 0, overdue: 3);        // 3 overdue
        $this->group($subject, 'Echo Late Too', done: 3, overdue: 1);      // 1 overdue, better progress

        $html = preg_replace('#<aside x-persist.*?</aside>#s', '', $this->page()->getContent());   // not the sidebar's own group shortcuts
        $order = ['Delta Later', 'Charlie Late', 'Echo Late Too', 'Bravo Behind', 'Alpha Steady'];

        $positions = array_map(fn ($name) => strpos($html, 'data-attention-row') === false ? false : strpos($html, '>'.$name.'<'), $order);

        $this->assertNotContains(false, $positions);
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions, 'Most overdue first, then lowest progress.');
    }

    public function test_needs_attention_and_awaiting_marking_tiles_use_current_data(): void
    {
        $subject = $this->subject();
        $this->group($subject, 'Late Group', overdue: 2);
        $this->group($subject, 'Fine Group', done: 2);

        $this->page()
            ->assertSee('Needs attention')
            ->assertSee('1 group with overdue tasks'.'', false)
            ->assertSee('Awaiting marking')
            ->assertSee('No rubric set up yet');
    }

    public function test_the_strip_shows_the_latest_announcement_and_who_opened_it(): void
    {
        $subject = $this->subject();
        $group = $this->group($subject, 'G1');
        $student = $group->members()->first();

        Announcement::create(['subject_id' => $subject->id, 'author_id' => $this->lecturer->id, 'title' => 'Older notice', 'body' => 'old']);
        $latest = Announcement::create(['subject_id' => $subject->id, 'author_id' => $this->lecturer->id, 'title' => 'Latest notice', 'body' => 'new']);
        AnnouncementRead::create(['announcement_id' => $latest->id, 'user_id' => $student->id, 'read_at' => now()]);

        $html = $this->page()->assertSee('data-announcement-strip', false)->getContent();

        $this->assertLessThan(strpos($html, 'Older notice'), strpos($html, 'Latest notice'));
        $this->assertStringContainsString('Opened by 1 of 1', $html);
        $this->assertStringContainsString('Post announcement', $html);
        $this->assertStringContainsString(route('lecturer.announcements', $subject), $html);
    }

    public function test_a_subject_without_announcements_says_so(): void
    {
        $this->subject();

        $this->page()->assertSee('No announcements yet')->assertSee('data-announcements-empty', false);
    }

    public function test_there_is_no_dashed_box_and_the_panels_scroll_inside(): void
    {
        $this->subject();

        $html = $this->page()->getContent();

        $this->assertStringNotContainsString('border-dashed', $html);
        $this->assertStringContainsString('data-bottom-panels', $html);
        $this->assertStringContainsString('data-attention-list', $html);
        $this->assertStringContainsString('overflow-y-auto', $html);
    }

    public function test_the_number_of_queries_does_not_grow_with_subjects_and_groups(): void
    {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($this->lecturer)->get(route('lecturer.dashboard'))->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $one = $this->subject(code: 'AAA1111');
        $this->group($one, 'G1', done: 1, overdue: 1);
        Announcement::create(['subject_id' => $one->id, 'author_id' => $this->lecturer->id, 'title' => 'T', 'body' => 'b']);
        $small = $count();

        foreach (['BBB2222', 'CCC3333', 'DDD4444'] as $code) {
            $subject = $this->subject(code: $code);
            Announcement::create(['subject_id' => $subject->id, 'author_id' => $this->lecturer->id, 'title' => 'T', 'body' => 'b']);
            foreach (['X', 'Y', 'Z'] as $name) {
                $this->group($subject, "{$code} {$name}", done: 2, overdue: 1);
            }
        }
        $large = $count();

        $this->assertSame($small, $large, "Queries grew from {$small} to {$large}.");
        $this->assertLessThanOrEqual(30, $large);
    }

    public function test_a_student_cannot_open_it(): void
    {
        $student = User::factory()->student()->create(['must_change_password' => false]);

        $this->actingAs($student)->get(route('lecturer.dashboard'))->assertForbidden();
    }
}
