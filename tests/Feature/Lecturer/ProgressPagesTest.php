<?php

namespace Tests\Feature\Lecturer;

use App\Models\ActivityLog;
use App\Models\Document;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Message;
use App\Models\Subject;
use App\Models\Task;
use App\Models\User;
use App\Support\ContributionReport;
use App\Support\SubjectPalette;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Monitor progress (the list) and the single-group monitor page, in the portal's look.
 */
class ProgressPagesTest extends TestCase
{
    use RefreshDatabase;

    protected User $lecturer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lecturer = User::factory()->lecturer()->create(['must_change_password' => false]);
    }

    protected function subject(string $code, ?User $owner = null): Subject
    {
        return Subject::create(['lecturer_id' => ($owner ?? $this->lecturer)->id, 'code' => $code, 'name' => "Name of {$code}", 'group_size' => 4]);
    }

    /** @param  list<int>  $points */
    protected function group(Subject $subject, string $name, array $points = [3, 3], int $done = 0, int $todo = 0, int $overdue = 0): Group
    {
        $group = Group::create(['subject_id' => $subject->id, 'name' => $name, 'group_size' => 4]);
        $first = null;

        foreach ($points as $score) {
            $student = User::factory()->student()->create();
            $first ??= $student;
            GroupMember::create(['group_id' => $group->id, 'user_id' => $student->id, 'joined_at' => now()]);

            if ($score > 0) {
                ActivityLog::create(['user_id' => $student->id, 'group_id' => $group->id, 'activity_type' => 'task_completed', 'weight' => $score]);
            }
        }

        $owner = $first ?? $this->lecturer;
        $make = fn (string $status, $deadline = null) => Task::create(['group_id' => $group->id, 'created_by' => $owner->id, 'assigned_to' => $owner->id, 'title' => 'T', 'status' => $status, 'deadline' => $deadline]);
        foreach (range(1, max($done, 1)) as $i) { if ($done >= $i) { $make(Task::STATUS_DONE); } }
        foreach (range(1, max($todo, 1)) as $i) { if ($todo >= $i) { $make('todo'); } }
        foreach (range(1, max($overdue, 1)) as $i) { if ($overdue >= $i) { $make('todo', now()->subDays(3)); } }

        return $group;
    }

    protected function list(): string
    {
        return $this->actingAs($this->lecturer)->get(route('lecturer.progress'))->assertOk()->getContent();
    }

    protected function tiles(string $html): string
    {
        preg_match('/<section[^>]*data-tiles>(.*?)<\/section>/s', $html, $m);

        return trim(preg_replace('/\s+/', ' ', strip_tags($m[1] ?? '')));
    }

    /*
    | The list
    */

    public function test_the_tiles_show_groups_attention_overdue_and_average_progress(): void
    {
        $subject = $this->subject('AAA1111');
        $this->group($subject, 'One', done: 1, todo: 1, overdue: 0);          // 50%
        $this->group($subject, 'Two', done: 0, todo: 0, overdue: 2);          // 0%, 2 overdue
        $this->group($subject, 'Three', done: 2, todo: 0, overdue: 0);        // 100%

        $text = $this->tiles($this->list());

        $this->assertStringContainsString('Groups 3', $text);
        $this->assertStringContainsString('Need attention 1', $text);
        $this->assertStringContainsString('Overdue tasks 2', $text);
        $this->assertStringContainsString('Average progress 50%', $text, '(50 + 0 + 100) / 3.');
    }

    public function test_the_tiles_follow_the_subject_filter(): void
    {
        $a = $this->subject('AAA1111');
        $b = $this->subject('BBB2222');
        $this->group($a, 'Alpha');
        $this->group($b, 'Beta');
        $this->group($b, 'Beta two', overdue: 1);

        $html = $this->list();

        // One shared filter block above the tiles, in each subject's colour; no second subject row below them.
        $this->assertSame(1, substr_count($html, 'data-subject-filter'));
        $this->assertLessThan(strpos($html, 'data-tiles'), strpos($html, 'data-filter-block'));
        $this->assertStringContainsString('data-palette="'.SubjectPalette::for($a->id)['name'].'"', substr($html, strpos($html, 'data-subject-filter')));
        $this->assertStringNotContainsString('x-on:click="pick(', $html);
        $this->assertSame(3, substr_count($html, 'data-pg="'));

        // Choosing a subject narrows the cards and the tiles with them.
        $one = $this->actingAs($this->lecturer)->get(route('lecturer.progress', ['subject' => $b->id]))->assertOk()->getContent();
        $this->assertSame(2, substr_count($one, 'data-pg="'));
        $this->assertStringContainsString('data-subject="'.$b->id.'"', $one);
        $this->assertStringNotContainsString('data-subject="'.$a->id.'"', $one);
        $this->assertStringContainsString('Groups 2 Being monitored', $this->tiles($one));

        // A subject that is not the lecturer's is ignored rather than an error.
        $other = $this->subject('OTHER333', User::factory()->lecturer()->create());
        $this->actingAs($this->lecturer)->get(route('lecturer.progress', ['subject' => $other->id]))->assertOk();
    }
    public function test_latest_activity_is_at_most_ten_rows_from_the_lecturers_own_groups(): void
    {
        $mineSubject = $this->subject('MINE1111');
        $this->group($mineSubject, 'Second of mine', [1, 1]);
        $mine = $this->group($mineSubject, 'Mine', [1, 1]);
        $theirs = $this->group($this->subject('THEIRS22', User::factory()->lecturer()->create()), 'Theirs', [1, 1]);
        $member = $mine->members()->first();
        $stranger = $theirs->members()->first();

        foreach (range(1, 14) as $i) {
            ActivityLog::create(['user_id' => $member->id, 'group_id' => $mine->id, 'activity_type' => 'message_sent', 'weight' => 1]);
        }
        ActivityLog::create(['user_id' => $stranger->id, 'group_id' => $theirs->id, 'activity_type' => 'task_created', 'weight' => 2]);

        $html = $this->list();

        $this->assertSame(10, preg_match_all('/data-latest-row\b/', $html));
        $this->assertStringContainsString('posted a message', $html);
        $this->assertStringNotContainsString('created a task', $html, 'Nothing from another lecturer.');
        $this->assertStringContainsString(route('lecturer.progress.show', $mine), $html);
    }

    public function test_members_to_check_uses_the_contribution_reports_own_flags(): void
    {
        $subject = $this->subject('AAA1111');
        $flagged = $this->group($subject, 'Uneven', [10, 0]);
        $this->group($subject, 'Even', [5, 5]);

        $html = $this->list();

        $expected = app(ContributionReport::class)->for($flagged->fresh())['flagged'];
        $this->assertCount(1, $expected);

        $this->assertSame(1, substr_count($html, 'data-flagged-row'));
        $this->assertStringContainsString(e($expected->first()['user']->name), $html);
        $this->assertStringContainsString('Below half the group average', $html);
        $this->assertStringContainsString(route('lecturer.reports.show', $flagged), $html);
        $this->assertMatchesRegularExpression('/data-panel-count>\s*1\s*</', substr($html, strpos($html, 'data-flagged-panel')));
    }

    public function test_nobody_flagged_says_so_and_no_groups_hides_the_panels(): void
    {
        $this->group($this->subject('AAA1111'), 'Even', [5, 5]);
        $this->group($this->subject('BBB2222'), 'Even too', [5, 5]);

        $this->assertStringContainsString('No one flagged. Nice.', $this->list());

        Group::query()->delete();
        $html = $this->list();

        $this->assertStringContainsString('No groups to monitor', $html);
        $this->assertStringContainsString(route('lecturer.groups'), $html);
        $this->assertStringNotContainsString('data-progress-panels', $html);
        $this->assertStringContainsString('!border-solid', $html);
    }

    public function test_the_list_costs_a_fixed_number_of_queries(): void
    {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($this->lecturer)->get(route('lecturer.progress'))->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $this->group($this->subject('AAA1111'), 'First', [4, 0], done: 1, overdue: 1);
        $this->group($this->subject('AAA2222'), 'Second', [2, 2]);
        $small = $count();

        foreach (range(1, 6) as $i) {
            $this->group($this->subject('SUB'.$i.'000'), "G{$i}", [6, 0, 2], done: 2, overdue: 1);
        }

        $this->assertSame($small, $count());
    }

    /*
    | The single group
    */

    protected function show(Group $group): string
    {
        return $this->actingAs($this->lecturer)->get(route('lecturer.progress.show', $group))->assertOk()->getContent();
    }

    public function test_the_group_page_has_five_tiles_of_one_component_and_a_normal_progress_tile(): void
    {
        $subject = $this->subject('AAA1111');
        $group = $this->group($subject, 'Alpha', [3, 3], done: 1, todo: 2, overdue: 1);

        $html = $this->show($group);
        preg_match('/<section[^>]*data-tiles>(.*?)<\/section>/s', $html, $tiles);

        $this->assertSame(5, substr_count($tiles[1], 'rounded-panel p-4'));
        $this->assertSame(5, substr_count($tiles[1], 'rounded-[11px]'));
        $this->assertSame(1, substr_count($tiles[1], 'data-palette="'.SubjectPalette::for($subject->id)['name'].'"'));
        $this->assertStringContainsString('25%', $tiles[1]);
        $this->assertStringContainsString('1 of 4 tasks', $tiles[1]);
        $this->assertStringContainsString('1 overdue', $tiles[1]);
    }

    public function test_the_board_is_read_only_and_scrolls_inside_its_columns(): void
    {
        $group = $this->group($this->subject('AAA1111'), 'Alpha', [3, 3], done: 2, todo: 3);

        $html = $this->show($group);
        $board = substr($html, strpos($html, 'data-board-panel'), 12000);

        foreach (['draggable', 'x-sort', 'wire:sortable', 'wire:model', 'wire:click', 'x-on:drop', 'ondrop', '<form'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $board, $forbidden);
        }
        $this->assertStringContainsString('Read-only — students move their own cards.', $html);
        $this->assertStringContainsString('Task board', $html);
        $this->assertSame(3, substr_count($html, 'data-column-cards'));
        $this->assertSame(3, substr_count($html, 'max-h-[26rem]'));
        $this->assertSame(3, substr_count($html, 'overflow-y-auto pr-1" data-column-cards'), 'Each column scrolls itself.');
        $this->assertStringContainsString('Nothing here.', $this->show($this->group($this->subject('BBB2222'), 'Empty', [1])));
    }

    public function test_the_right_panel_has_two_tabs_with_messages_first(): void
    {
        $group = $this->group($this->subject('AAA1111'), 'Alpha', [3, 3]);
        $member = $group->members()->first();

        foreach (range(1, 40) as $i) {
            Message::create(['group_id' => $group->id, 'user_id' => $member->id, 'body' => "Message {$i}"]);
        }

        $html = $this->show($group);

        $this->assertStringContainsString("x-data=\"{ tab: 'messages' }\"", $html);
        $this->assertSame(1, substr_count($html, 'id="tab-messages"'));
        $this->assertSame(1, substr_count($html, 'id="tab-activity"'));
        $this->assertStringContainsString('Recent messages', $html);
        $this->assertStringContainsString('Recent activity', $html);
        $this->assertMatchesRegularExpression('/id="panel-activity"[^>]*x-cloak/', $html, 'Activity waits behind the messages.');
        $this->assertStringContainsString('x-transition.opacity', $html);

        // At most the latest thirty, newest first.
        $this->assertSame(30, preg_match_all('/Message \d+\s*<\/p>/', $html));
        $this->assertStringContainsString('Message 40', $html);
        $this->assertDoesNotMatchRegularExpression('/Message 10\s*<\/p>/', $html);
    }

    public function test_deleted_documents_stay_visible_to_the_lecturer_but_start_closed(): void
    {
        $group = $this->group($this->subject('AAA1111'), 'Alpha', [3, 3]);
        $member = $group->members()->first();
        $document = Document::create(['group_id' => $group->id, 'created_by' => $member->id, 'last_edited_by' => $member->id, 'title' => 'Kept for marking', 'content' => '<p>x</p>']);
        $document->forceFill(['deleted_by' => $member->id])->save();
        $document->delete();

        $html = $this->show($group);

        $this->assertStringContainsString('Deleted documents', $html);
        $this->assertStringContainsString('data-deleted-documents', $html);
        $this->assertStringContainsString("x-data=\"{ deleted: false }\"", $html);
        $this->assertStringContainsString('Kept for marking', $html);
        $this->assertStringContainsString('Deleted by '.e($member->name), $html);
        $this->assertStringContainsString(route('lecturer.progress.documents.show', [$group, $document]), $html);
    }

    public function test_the_group_page_no_longer_repeats_a_row_of_group_cards(): void
    {
        $subject = $this->subject('AAA1111');
        $a = $this->group($subject, 'Alpha');
        $this->group($subject, 'Beta');

        $html = $this->show($a);

        $this->assertStringNotContainsString('data-group-picker', $html);
        $this->assertStringNotContainsString('data-group-card', $html);
        $this->assertStringNotContainsString('Your groups', $html);
        $this->assertStringContainsString('data-group-switcher', $html);
    }

    public function test_access_rules_are_unchanged(): void
    {
        $other = User::factory()->lecturer()->create(['must_change_password' => false]);
        $theirs = $this->group($this->subject('THEIRS22', $other), 'Theirs');

        $this->actingAs($this->lecturer)->get(route('lecturer.progress.show', $theirs))->assertNotFound();

        $student = User::factory()->student()->create(['must_change_password' => false]);
        $mine = $this->group($this->subject('MINE1111'), 'Mine');
        $this->actingAs($student)->get(route('lecturer.progress.show', $mine))->assertForbidden();
        $this->actingAs($student)->get(route('lecturer.progress'))->assertForbidden();
    }

    public function test_the_group_page_costs_a_fixed_number_of_queries(): void
    {
        $subject = $this->subject('AAA1111');
        $group = $this->group($subject, 'Alpha', [3, 1], done: 1, todo: 1);

        $count = function () use ($group): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($this->lecturer)->get(route('lecturer.progress.show', $group))->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $member = $group->members()->first();
        Message::create(['group_id' => $group->id, 'user_id' => $member->id, 'body' => 'hi']);
        $small = $count();

        foreach (range(1, 12) as $i) {
            $student = User::factory()->student()->create();
            Message::create(['group_id' => $group->id, 'user_id' => $student->id, 'body' => "m{$i}"]);
            Task::create(['group_id' => $group->id, 'created_by' => $member->id, 'assigned_to' => $member->id, 'title' => 'T', 'status' => 'todo']);
            ActivityLog::create(['user_id' => $member->id, 'group_id' => $group->id, 'activity_type' => 'message_sent', 'weight' => 1]);
        }

        $this->assertSame($small, $count());
    }
}
