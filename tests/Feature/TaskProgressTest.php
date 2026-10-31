<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\Subject;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The progress block: eyebrow, percentage, bar, counts.
 *
 * Two things were wrong with it, and both only showed up on screen. The
 * percentage sat about three and a half pixels above its label, because the
 * row aligned baselines while the two texts had very different line heights.
 * And inside a table whose column was already headed "Task progress", the
 * block repeated that heading on every single row.
 */
class TaskProgressTest extends TestCase
{
    use RefreshDatabase;

    protected User $lecturer;

    protected Group $group;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lecturer = User::factory()->lecturer()->create(['must_change_password' => false]);

        $subject = Subject::create([
            'lecturer_id' => $this->lecturer->id, 'code' => 'SECJ3104', 'name' => 'App Dev', 'group_size' => 4,
        ]);

        $this->group = Group::create([
            'subject_id' => $subject->id, 'name' => 'Group A', 'group_size' => 4,
        ]);

        foreach ([Task::STATUS_DONE, Task::STATUS_DONE, Task::STATUS_TODO, Task::STATUS_TODO] as $i => $status) {
            Task::create([
                'group_id' => $this->group->id,
                'created_by' => $this->lecturer->id,
                'title' => 'Task '.$i,
                'status' => $status,
            ]);
        }
    }

    public function test_the_percentage_is_level_with_its_label(): void
    {
        // Baseline alignment is right for text sharing a line. These two do
        // not share one: 10.5px on a 1.3 line box beside 13px on a 1.5 one,
        // so a shared baseline lifted the percentage clear of the label.
        $html = $this->actingAs($this->lecturer)
            ->get(route('lecturer.groups.show', $this->group))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('flex items-center justify-between gap-2', $html);
        $this->assertStringNotContainsString('flex items-baseline justify-between gap-2', $html);
    }

    public function test_the_percentage_carries_no_extra_leading(): void
    {
        // text-body is 1.5 line height, which added height the row did not
        // need and pushed the number off centre inside its own box.
        $this->actingAs($this->lecturer)
            ->get(route('lecturer.groups.show', $this->group))
            ->assertOk()
            ->assertSee('text-body font-extrabold leading-none', false);
    }

    public function test_the_percentage_is_correct(): void
    {
        $this->actingAs($this->lecturer)
            ->get(route('lecturer.groups.show', $this->group))
            ->assertOk()
            ->assertSee('50%');
    }

    /*
    | The duplicated heading
    */

    public function test_a_table_column_does_not_repeat_its_heading_on_every_row(): void
    {
        // The dashboard lists the same progress cells without any column heading at all.
        foreach (['lecturer.dashboard', 'lecturer.groups'] as $screen) {
            $html = $this->actingAs($this->lecturer)->get(route($screen))->assertOk()->getContent();

            // Once as the column heading, and nowhere else.
            $this->assertSame(0, substr_count($html, 'Task progress'),
                "“Task progress” appears more than once on {$screen}.");

            // The number itself is still there — only the eyebrow went.
            $this->assertStringContainsString('50%', $html);
        }
    }

    public function test_the_label_still_shows_where_there_is_no_column_heading(): void
    {
        // On a panel rather than in a table, the block has to say what it is.
        $this->actingAs($this->lecturer)
            ->get(route('lecturer.groups.show', $this->group))
            ->assertOk()
            ->assertSee('Task progress');
    }
}
