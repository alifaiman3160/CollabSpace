<?php

namespace Tests\Feature\Lecturer;

use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Subject;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The lecturer's read-only board uses the student's compact Done rows, and none of its cards can be squashed. */
class MonitorBoardRowsTest extends TestCase
{
    use RefreshDatabase;

    protected function html(int $done = 3, int $todo = 2): string
    {
        $lecturer = User::factory()->lecturer()->create(['must_change_password' => false]);
        $subject = Subject::create(['lecturer_id' => $lecturer->id, 'code' => 'AAA1111', 'name' => 'Name', 'group_size' => 4]);
        $group = Group::create(['subject_id' => $subject->id, 'name' => 'Team', 'group_size' => 4]);
        $student = User::factory()->student()->create(['name' => 'Farah Aqilah Roslan']);
        GroupMember::create(['group_id' => $group->id, 'user_id' => $student->id, 'joined_at' => now()]);

        for ($i = 1; $i <= $done; $i++) {
            Task::create(['group_id' => $group->id, 'created_by' => $student->id, 'assigned_to' => $student->id, 'title' => "Finished {$i}", 'description' => "About {$i}", 'status' => Task::STATUS_DONE, 'completed_at' => now()]);
        }

        for ($i = 1; $i <= $todo; $i++) {
            Task::create(['group_id' => $group->id, 'created_by' => $student->id, 'title' => "Open {$i}", 'status' => Task::STATUS_TODO]);
        }

        return preg_replace('#<aside x-persist.*?</aside>#s', '', $this->actingAs($lecturer)->get(route('lecturer.progress.show', $group))->assertOk()->getContent());
    }

    public function test_done_tasks_are_compact_read_only_rows_that_open_one_at_a_time(): void
    {
        $html = $this->html();

        $this->assertSame(3, substr_count($html, 'data-done-row'));
        $this->assertSame(3, substr_count($html, 'data-done-toggle'));
        $this->assertStringContainsString('doneOpen: null', $html);
        $this->assertStringContainsString('About 2', $html, 'The description is in the row, shown when it opens.');
        $this->assertStringContainsString('Farah Aqilah Roslan', $html);
        $this->assertStringContainsString('Completed ', $html);

        $done = substr($html, strpos($html, 'data-column-cards'), 6000);
        $this->assertStringNotContainsString('<select', $html);
        $this->assertStringNotContainsString('draggable', $html);
        $this->assertDoesNotMatchRegularExpression('/aria-label="(Edit|Delete) /', $html);
        $this->assertStringContainsString('shrink-0', $done);
    }

    public function test_every_card_keeps_its_height_and_every_list_scrolls_inside_its_column(): void
    {
        $html = $this->html(12, 12);

        $this->assertSame(12, preg_match_all('/<article(?=[^>]*data-board-card)(?=[^>]*\sclass="[^"]*\bshrink-0\b)/', $html), 'To Do cards cannot shrink.');
        $this->assertSame(12, preg_match_all('/<article(?=[^>]*data-done-row)(?=[^>]*\sclass="[^"]*\bshrink-0\b)/', $html), 'Done rows cannot shrink.');
        $this->assertSame(3, substr_count($html, 'cs-scroll'));
        $this->assertSame(3, preg_match_all('/class="cs-scroll[^"]*min-h-0[^"]*overflow-y-auto/', $html));
        $this->assertDoesNotMatchRegularExpression('/<article[^>]*\sh-\[/', $html, 'No fixed height on a card.');
    }

    public function test_an_empty_done_column_says_so_without_a_dashed_box(): void
    {
        $html = $this->html(0, 1);

        $this->assertStringContainsString('Nothing done yet.', $html);
        preg_match_all('/data-column-cards.*?<\/section>/s', $html, $columns);
        $this->assertCount(3, $columns[0]);
        $this->assertStringNotContainsString('border-dashed', implode('', $columns[0]));
    }
}
