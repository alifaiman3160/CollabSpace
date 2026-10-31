<?php

namespace Tests\Feature\Lecturer;

use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Subject;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Groups list shows the same facts it always did (member counts, task
 * progress, overdue numbers, full or not) in the new cell layout. These pin the
 * facts, not the markup.
 */
class GroupsTableTest extends TestCase
{
    use RefreshDatabase;

    protected User $lecturer;

    protected Subject $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lecturer = User::factory()->lecturer()->create(['must_change_password' => false]);
        $this->subject = Subject::create([
            'lecturer_id' => $this->lecturer->id, 'code' => 'SECJ3104', 'name' => 'Application Development', 'group_size' => 4,
        ]);
    }

    protected function group(string $name, int $members, int $size = 4, ?Subject $subject = null, string $method = 'manual'): Group
    {
        $group = Group::create([
            'subject_id' => ($subject ?? $this->subject)->id, 'name' => $name, 'group_size' => $size, 'formation_method' => $method,
        ]);

        User::factory()->student()->count($members)->create()->each(
            fn ($s) => GroupMember::create(['group_id' => $group->id, 'user_id' => $s->id, 'joined_at' => now()]),
        );

        return $group;
    }

    protected function tasks(Group $group, int $done, int $open, int $overdue): void
    {
        $make = fn (array $a) => Task::create([
            'group_id' => $group->id, 'created_by' => $this->lecturer->id, 'title' => 'T', ...$a,
        ]);

        for ($i = 0; $i < $done; $i++) {
            $make(['status' => Task::STATUS_DONE, 'completed_at' => now()]);
        }
        for ($i = 0; $i < $open; $i++) {
            $make(['status' => Task::STATUS_TODO]);
        }
        for ($i = 0; $i < $overdue; $i++) {
            $make(['status' => Task::STATUS_TODO, 'deadline' => now()->subWeek()->toDateString()]);
        }
    }

    protected function page(): string
    {
        return $this->actingAs($this->lecturer)->get(route('lecturer.groups'))->assertOk()->getContent();
    }

    public function test_a_full_group_shows_its_count_and_a_full_pill(): void
    {
        $this->group('Alpha', 4);

        $html = $this->page();

        $this->assertStringContainsString('4/4', $html);
        $this->assertStringContainsString('Full', $html);
        $this->assertStringNotContainsString('Open</', $html);
    }

    public function test_the_capacity_status_follows_how_full_the_group_is(): void
    {
        $this->group('Three', 3);   // one short
        $html = $this->page();
        $this->assertStringContainsString('3/4', $html);
        $this->assertMatchesRegularExpression('/>\s*Open\s*</', $html, 'A group with room says Open.');
        $this->assertDoesNotMatchRegularExpression('/>\s*Full\s*</', $html);

        $this->group('One', 1);
        $this->assertStringContainsString('1/4', $this->page());
    }

    public function test_exactly_half_full_is_not_flagged(): void
    {
        $this->group('Half', 2);

        $html = $this->page();

        $this->assertStringContainsString('2/4', $html);
    }

    public function test_more_than_four_members_collapse_into_a_plus_n(): void
    {
        $this->group('Big', 7, 10);

        $html = $this->page();

        $this->assertStringContainsString('7/10', $html);
        $this->assertStringContainsString('+3', $html);
    }

    public function test_progress_and_overdue_numbers_match_the_tasks(): void
    {
        $group = $this->group('Busy', 2);
        $this->tasks($group, done: 9, open: 0, overdue: 5);   // 14 tasks, 9 done, 5 late

        $html = $this->page();

        $this->assertStringContainsString('9 of 14 tasks', $html);
        $this->assertStringContainsString('64%', $html);
        $this->assertStringContainsString('5 overdue', $html);
    }

    public function test_a_group_with_nothing_overdue_shows_no_overdue_pill(): void
    {
        $group = $this->group('Calm', 2);
        $this->tasks($group, done: 2, open: 2, overdue: 0);

        $html = $this->page();

        $this->assertStringContainsString('2 of 4 tasks', $html);
        $this->assertStringNotContainsString('overdue', $html);
    }

    public function test_a_group_with_no_tasks_says_so(): void
    {
        $this->group('Empty', 2);

        $html = $this->page();

        $this->assertStringContainsString('No tasks yet', $html);
        $this->assertStringContainsString('0%', $html);
    }

    public function test_the_subject_name_and_formation_method_show_under_the_cells(): void
    {
        $this->group('Made', 2, method: 'balanced');

        $html = $this->page();

        $this->assertStringContainsString('SECJ3104', $html);
        $this->assertStringContainsString('Application Development', $html);
        $this->assertStringContainsString('Balanced', $html);
    }

    public function test_delete_keeps_its_confirmation_and_is_always_rendered(): void
    {
        $group = $this->group('Doomed', 2);

        $html = $this->page();

        $this->assertStringContainsString('Delete group', $html);
        $this->assertStringContainsString(route('lecturer.groups.destroy', $group), $html);
        $this->assertStringContainsString('aria-label="Delete Doomed"', $html);
        // Visible without hover: no opacity or hidden class waiting on a pointer.
        $this->assertDoesNotMatchRegularExpression('/aria-label="Delete Doomed"[^>]*(opacity-0|hidden|invisible)/', $html);
    }

    public function test_the_groups_are_cards_in_a_grid_not_a_table(): void
    {
        $this->group('Any', 2);

        $html = $this->page();

        $this->assertStringNotContainsString('<table', $html);
        $this->assertStringContainsString('grid-cols-[repeat(auto-fill,minmax(20rem,1fr))]', $html);
        $this->assertStringContainsString('data-group-card-block', $html);
    }

    public function test_another_lecturers_groups_never_appear(): void
    {
        $other = User::factory()->lecturer()->create(['must_change_password' => false]);
        $theirs = Subject::create(['lecturer_id' => $other->id, 'code' => 'SECV9999', 'name' => 'Not Mine', 'group_size' => 4]);
        $this->group('Theirs', 4, subject: $theirs);
        $this->group('Mine', 1);

        $html = $this->page();

        $this->assertStringContainsString('Mine', $html);
        $this->assertStringNotContainsString('Theirs', $html);
        $this->assertStringNotContainsString('Not Mine', $html);
    }
}
