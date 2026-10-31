<?php

namespace Tests\Feature\Student;

use App\Models\ActivityLog;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Subject;
use App\Models\Task;
use App\Models\User;
use App\Support\SubjectPalette;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * My workspace in the dashboard's look: five tiles of one component (the first in the group's colour), and three panels
 * with their titles inside, the feed scrolling in its own card.
 */
class WorkspaceLayoutTest extends TestCase
{
    use RefreshDatabase;

    protected User $me;

    protected Group $group;

    protected function setUp(): void
    {
        parent::setUp();

        $lecturer = User::factory()->lecturer()->create();
        $subject = Subject::create(['lecturer_id' => $lecturer->id, 'code' => 'SECJ3104', 'name' => 'App Dev', 'group_size' => 4]);
        $this->group = Group::create(['subject_id' => $subject->id, 'name' => 'Group SE1', 'group_size' => 4]);

        $this->me = User::factory()->student()->create(['must_change_password' => false]);
        GroupMember::create(['group_id' => $this->group->id, 'user_id' => $this->me->id, 'joined_at' => now()]);
    }

    protected function task(string $status = 'todo', $deadline = null, ?Group $group = null): Task
    {
        return Task::create([
            'group_id' => ($group ?? $this->group)->id, 'created_by' => $this->me->id, 'assigned_to' => $this->me->id,
            'title' => 'Task '.str()->random(4), 'status' => $status, 'deadline' => $deadline,
        ]);
    }

    protected function page(?Group $group = null): string
    {
        return $this->actingAs($this->me)->get(route('student.workspace.show', $group ?? $this->group))->assertOk()->getContent();
    }

    public function test_it_renders_with_no_one_and_several_tasks(): void
    {
        $this->assertStringContainsString('Nothing open. Nice work.', $this->page());

        $this->task();
        $this->assertSame(1, substr_count($this->page(), 'data-open-task'));

        $this->task('inprogress');
        $this->task('done');
        $this->task('todo', now()->subDays(2));

        $html = $this->page();
        $this->assertSame(3, substr_count($html, 'data-open-task'));
        $this->assertStringContainsString('1 overdue', $html);
        $this->assertStringContainsString('25%', $html);
        $this->assertStringContainsString('1 of 4 tasks', $html);
    }

    public function test_there_are_five_tiles_of_one_component_and_the_first_wears_the_groups_colour(): void
    {
        $html = $this->page();

        preg_match('/<section[^>]*data-tiles>(.*?)<\/section>/s', $html, $tiles);
        $this->assertNotEmpty($tiles);

        // Each tile has the same structure: an icon chip, an uppercase label, a big number.
        $this->assertSame(5, substr_count($tiles[1], 'text-label font-bold uppercase tracking-eyebrow'));
        $this->assertSame(5, substr_count($tiles[1], 'rounded-[11px]'), 'An icon chip on each.');
        $this->assertSame(5, substr_count($tiles[1], 'rounded-panel p-4'));

        $colour = SubjectPalette::for($this->group->subject_id);
        $this->assertSame(1, substr_count($tiles[1], 'data-palette="'.$colour['name'].'"'), 'Only the first tile is coloured.');
        $this->assertStringContainsString('background-color: '.$colour['bg'], $tiles[1]);
        $this->assertStringNotContainsString('bg-periwinkle ', $tiles[1], 'No fixed periwinkle any more.');

        // Task progress is a normal tile with a bar along the bottom.
        $this->assertStringContainsString('Task progress', $tiles[1]);
        $this->assertStringContainsString('0%', $tiles[1]);
        $this->assertStringContainsString('No tasks yet', $tiles[1]);
    }

    public function test_the_tile_colour_follows_the_selected_group(): void
    {
        $lecturer = User::factory()->lecturer()->create();
        $other = $this->subjectGroup($lecturer, 'SECR1013');

        $first = SubjectPalette::for($this->group->subject_id)['name'];
        $second = SubjectPalette::for($other->subject_id)['name'];

        $this->assertStringContainsString('data-palette="'.$first.'"', explode('data-tiles>', $this->page())[1]);
        $this->assertStringContainsString('data-palette="'.$second.'"', explode('data-tiles>', $this->page($other))[1]);
    }

    protected function subjectGroup(User $lecturer, string $code): Group
    {
        $subject = Subject::create(['lecturer_id' => $lecturer->id, 'code' => $code, 'name' => "Name {$code}", 'group_size' => 4]);
        $group = Group::create(['subject_id' => $subject->id, 'name' => 'Other', 'group_size' => 4]);
        GroupMember::create(['group_id' => $group->id, 'user_id' => $this->me->id, 'joined_at' => now()]);

        return $group;
    }

    public function test_three_panels_keep_their_titles_inside_the_card_with_counts(): void
    {
        $this->task();
        $this->task('inprogress');

        $html = $this->page();

        foreach (['Group members' => '1', 'Open tasks' => '2', 'Recent activity' => null] as $title => $count) {
            preg_match('/<section[^>]*aria-label="'.$title.'"[^>]*>(.*?)<\/section>/s', $html, $panel);

            $this->assertNotEmpty($panel, $title);
            // The heading is the first thing in the card.
            $this->assertMatchesRegularExpression('/^\s*<h2[^>]*>\s*'.$title.'/s', $panel[1], $title);

            if ($count !== null) {
                $this->assertMatchesRegularExpression('/data-panel-count>\s*'.$count.'\s*</', $panel[1], "{$title} count");
            }
        }

        // No title outside a card any more.
        $this->assertStringNotContainsString('<h2 class="text-h3 font-extrabold tracking-title">Group members</h2>', $html);
    }

    public function test_the_feed_scrolls_inside_its_own_card_and_the_footer_goes_to_the_board(): void
    {
        ActivityLog::create(['user_id' => $this->me->id, 'group_id' => $this->group->id, 'activity_type' => 'message_sent', 'weight' => 1]);

        $html = $this->page();

        preg_match('/<section[^>]*aria-label="Recent activity"[^>]*>(.*?)<\/section>/s', $html, $feed);
        $this->assertStringContainsString('overflow-y-auto', $feed[1]);
        $this->assertStringContainsString('data-feed', $feed[1]);
        $this->assertStringContainsString('lg:absolute lg:inset-0', $html, 'The feed fills the column; it does not stretch the page.');

        preg_match('/<section[^>]*aria-label="Open tasks"[^>]*>(.*?)<\/section>/s', $html, $open);
        $this->assertStringContainsString(route('student.board.show', $this->group), $open[1]);
        $this->assertStringContainsString('Open board', $open[1]);
    }

    public function test_the_entrance_is_staggered_and_never_sideways(): void
    {
        $html = $this->page();

        foreach (range(0, 7) as $i) {
            $this->assertStringContainsString("--i: {$i}", $html);
        }

        $css = file_get_contents(resource_path('css/app.css'));
        $this->assertStringNotContainsString('translateX', substr($css, strpos($css, '@keyframes cs-card-in'), 200));
    }

    public function test_members_and_points_logic_are_unchanged(): void
    {
        $mate = User::factory()->student()->create(['must_change_password' => false]);
        GroupMember::create(['group_id' => $this->group->id, 'user_id' => $mate->id, 'joined_at' => now()]);
        ActivityLog::create(['user_id' => $this->me->id, 'group_id' => $this->group->id, 'activity_type' => 'task_completed', 'weight' => 5]);

        $html = $this->page();

        $this->assertSame(2, substr_count($html, 'data-member-row'));
        $this->assertSame(1, substr_count($html, '(you)'));
        $this->assertStringContainsString('5 pts', $html);
        $this->assertStringContainsString('0 pts', $html);
        $this->assertStringContainsString('Low activity', $html);
        $this->assertStringContainsString('Group average is 2.5', $html);
    }

    public function test_a_non_member_cannot_open_another_groups_workspace(): void
    {
        $stranger = User::factory()->student()->create(['must_change_password' => false]);

        $this->actingAs($stranger)->get(route('student.workspace.show', $this->group))->assertNotFound();
    }

    public function test_the_query_count_is_fixed(): void
    {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($this->me)->get(route('student.workspace.show', $this->group))->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        // One of each first (the assignee and the activity's user are each one eager load, only when there is something).
        $this->task('todo', now()->addDay());
        ActivityLog::create(['user_id' => $this->me->id, 'group_id' => $this->group->id, 'activity_type' => 'message_sent', 'weight' => 1]);

        $small = $count();

        for ($i = 0; $i < 5; $i++) {
            $this->task('todo', now()->addDays($i));
            $mate = User::factory()->student()->create();
            GroupMember::create(['group_id' => $this->group->id, 'user_id' => $mate->id, 'joined_at' => now()]);
            ActivityLog::create(['user_id' => $mate->id, 'group_id' => $this->group->id, 'activity_type' => 'message_sent', 'weight' => 1]);
        }

        $this->assertSame($small, $count());
        $this->assertLessThanOrEqual(25, $small);
    }
}
