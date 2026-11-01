<?php

namespace Tests\Feature\Lecturer;

use App\Models\Group;
use App\Models\GroupMember;
use App\Models\MarkApproval;
use App\Models\Rubric;
use App\Models\Subject;
use App\Models\Task;
use App\Models\User;
use App\Support\SubjectPalette;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * My subjects, Groups, Marking and Monitor progress as cards (the dashboard's look), not tables.
 */
class CardPagesTest extends TestCase
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

    protected function group(Subject $subject, string $name, int $members = 2, int $done = 0, int $todo = 0, int $overdue = 0): Group
    {
        $group = Group::create(['subject_id' => $subject->id, 'name' => $name, 'group_size' => 4]);
        $students = User::factory()->student()->count($members)->create();
        $students->each(fn ($s) => GroupMember::create(['group_id' => $group->id, 'user_id' => $s->id, 'joined_at' => now()]));
        $owner = $students->first() ?? $this->lecturer;

        $make = fn (string $status, $deadline = null) => Task::create(['group_id' => $group->id, 'created_by' => $owner->id, 'assigned_to' => $owner->id, 'title' => 'T', 'status' => $status, 'deadline' => $deadline]);
        foreach (range(1, $done) as $i) { if ($done) { $make(Task::STATUS_DONE); } }
        foreach (range(1, $todo) as $i) { if ($todo) { $make('todo'); } }
        foreach (range(1, $overdue) as $i) { if ($overdue) { $make('todo', now()->subDays(3)); } }

        return $group;
    }

    protected function pageOf(string $name, array $params = []): string
    {
        // Without the sidebar, whose own group shortcuts are not what these pages are being checked for.
        return preg_replace('#<aside x-persist.*?</aside>#s', '', $this->actingAs($this->lecturer)->get(route($name, $params))->assertOk()->getContent());
    }

    /*
    | My subjects
    */

    public function test_my_subjects_renders_for_none_one_and_several_without_a_table(): void
    {
        $html = $this->pageOf('lecturer.subjects');
        $this->assertStringContainsString('No subjects yet', $html);
        $this->assertStringNotContainsString('<table', $html);
        $this->assertStringContainsString('data-new-subject-tile', $html);
        $this->assertStringNotContainsString('border-dashed', $html);

        $one = $this->subject('AAA1111');
        $this->assertSame(1, substr_count($this->pageOf('lecturer.subjects'), 'data-subject-card'));

        $this->subject('BBB2222');
        $this->subject('CCC3333');
        $html = $this->pageOf('lecturer.subjects');
        $this->assertSame(3, substr_count($html, 'data-subject-card'));
        $this->assertStringNotContainsString('<table', $html);
    }

    public function test_a_subject_card_shows_its_numbers_colour_and_every_action(): void
    {
        $subject = $this->subject('SECJ3104');
        $this->group($subject, 'G1', 3);
        $this->group($subject, 'G2', 2);
        $subject->update(['project_deadline' => null]);

        $html = $this->pageOf('lecturer.subjects');

        $colour = SubjectPalette::for($subject->id);
        $this->assertStringContainsString('data-palette="'.$colour['name'].'"', $html);

        preg_match('/<dl[^>]*data-subject-stats>(.*?)<\/dl>/s', $html, $stats);
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($stats[1])));
        $this->assertStringContainsString('Groups 2', $text);
        $this->assertStringContainsString('Students 5', $text);
        $this->assertStringContainsString('Default size 4', $text);
        $this->assertStringContainsString('Group project due No deadline', $text);

        // The Groups number goes where it always went; all six actions and the confirmed delete are on the card.
        $this->assertStringContainsString(route('lecturer.groups', ['subject' => $subject->id]), $html);
        foreach ([route('lecturer.subjects.edit', $subject), route('lecturer.formation', $subject), route('lecturer.rubrics', $subject), route('lecturer.announcements', $subject), route('lecturer.subjects.destroy', $subject)] as $url) {
            $this->assertStringContainsString($url, $html);
        }
        foreach (['Edit', 'Groups', 'Auto-assign students', 'Rubric', 'Announcements', 'Delete subject', 'aria-label="Delete SECJ3104"'] as $label) {
            $this->assertStringContainsString($label, $html, $label);
        }
        $this->assertStringContainsString('1 subject you teach.', $html);
    }

    public function test_subject_colours_follow_the_id(): void
    {
        $subjects = collect(range(1, 7))->map(fn ($i) => $this->subject('SUB'.$i.'000'));
        $html = $this->pageOf('lecturer.subjects');

        foreach ($subjects as $subject) {
            preg_match('/<article[^>]*data-subject-card data-palette="([a-z]+)"[^>]*>(?:(?!<\/article>).)*?SUB'.substr($subject->code, 3, 1).'000/s', $html, $m);
            $this->assertSame(SubjectPalette::for($subject->id)['name'], $m[1] ?? null, $subject->code);
        }
    }

    /*
    | Groups
    */

    public function test_groups_are_cards_with_members_progress_and_overdue_and_a_filter_that_switches_in_place(): void
    {
        $a = $this->subject('AAA1111');
        $b = $this->subject('BBB2222');
        $this->group($a, 'Alpha', 4, done: 2, todo: 1, overdue: 1);
        $this->group($b, 'Beta', 2);

        $html = $this->pageOf('lecturer.groups');

        $this->assertStringNotContainsString('<table', $html);
        $this->assertSame(2, substr_count($html, 'data-group-card-block'));
        $this->assertStringContainsString('4/4', $html);
        $this->assertStringContainsString('2 of 4 tasks', $html);
        $this->assertStringContainsString('1 overdue', $html);
        $this->assertMatchesRegularExpression('/>\s*Full\s*</', $html);

        // The two help tiles, side by side, with the same words.
        $this->assertSame(1, substr_count($html, 'data-group-help'));
        $this->assertStringContainsString('Creates a single empty group.', $html);

        // The filter is pills in the subjects' colours that swap the page without a reload.
        $this->assertSame(3, substr_count(substr($html, strpos($html, 'data-subject-filter'), 2500), 'wire:navigate'), 'All subjects and one pill each.');
        $this->assertStringContainsString('data-palette="'.SubjectPalette::for($a->id)['name'].'"', substr($html, strpos($html, 'data-subject-filter')));

        $filtered = $this->pageOf('lecturer.groups', ['subject' => $a->id]);
        $this->assertStringContainsString('Alpha', $filtered);
        $this->assertStringNotContainsString('Beta', $filtered);
        $this->assertStringContainsString('aria-current="page"', $filtered);
    }

    public function test_group_cards_keep_manage_and_the_confirmed_delete(): void
    {
        $group = $this->group($this->subject('AAA1111'), 'Doomed');

        $html = $this->pageOf('lecturer.groups');

        $this->assertStringContainsString(route('lecturer.groups.show', $group), $html);
        $this->assertStringContainsString(route('lecturer.groups.destroy', $group), $html);
        $this->assertStringContainsString('Delete group', $html);
        $this->assertStringContainsString('aria-label="Delete Doomed"', $html);
        $this->assertStringContainsString('Manage', $html);
    }

    public function test_groups_empty_states_are_friendly_not_dashed(): void
    {
        $this->assertStringContainsString('No subjects yet', $this->pageOf('lecturer.groups'));

        $this->subject('AAA1111');
        $html = $this->pageOf('lecturer.groups');
        $this->assertStringContainsString('No groups here yet', $html);
        $this->assertStringContainsString('!border-solid', $html, 'No dashed box: the empty state is overridden to solid.');
    }

    /*
    | Marking
    */

    public function test_marking_shows_rubric_cards_and_group_cards_with_approval_status(): void
    {
        $subject = $this->subject('SECJ3104');
        $none = $this->group($subject, 'Alpha', 3);
        $some = $this->group($subject, 'Bravo', 3);
        $all = $this->group($subject, 'Charlie', 2);

        $rubric = Rubric::create(['subject_id' => $subject->id, 'name' => 'Final rubric', 'version' => 2, 'is_current' => true, 'created_by' => $this->lecturer->id]);

        $approve = function (Group $group, int $howMany) use ($rubric) {
            $group->members->take($howMany)->each(fn ($student) => MarkApproval::create([
                'rubric_id' => $rubric->id, 'group_id' => $group->id, 'student_id' => $student->id, 'approved_by' => $this->lecturer->id,
                'approved_at' => now(), 'snapshot' => [], 'audit' => [], 'total' => 0,
            ]));
        };
        $approve($some, 1);
        $approve($all, 2);

        $html = $this->pageOf('lecturer.marking');

        $this->assertStringNotContainsString('<table', $html);

        // The rubric card: name, version, locked now (approvals exist), and the manage button.
        $this->assertStringContainsString('Final rubric', $html);
        $this->assertStringContainsString('Version 2', $html);
        $this->assertStringContainsString('Locked', $html);
        $this->assertStringContainsString('Upload or manage', $html);
        $this->assertStringContainsString('data-palette="'.SubjectPalette::for($subject->id)['name'].'"', $html);

        // The group cards: students, the approved count and the status.
        $this->assertSame(3, substr_count($html, 'data-approval'));
        $this->assertStringContainsString('0 of 3 approved', $html);
        $this->assertStringContainsString('1 of 3 approved', $html);
        $this->assertStringContainsString('2 of 2 approved', $html);
        foreach (['Not started', 'In progress', 'All approved'] as $status) {
            $this->assertSame(1, preg_match_all('/>\s*'.$status.'\s*</', $html), $status);
        }
        $this->assertStringContainsString(route('lecturer.marking.group', [$rubric, $none]), $html);
    }

    public function test_marking_without_a_rubric_offers_the_upload_and_says_so(): void
    {
        $subject = $this->subject('SECJ3104');
        $this->group($subject, 'Waiting');

        $html = $this->pageOf('lecturer.marking');

        $this->assertStringContainsString('No rubric yet.', $html);
        $this->assertStringContainsString('Upload a rubric', $html);
        $this->assertStringContainsString('Needs a rubric', $html);
        $this->assertStringNotContainsString('Locked', $html);
    }

    public function test_marking_with_nothing_set_up_is_friendly(): void
    {
        $html = $this->pageOf('lecturer.marking');

        $this->assertStringContainsString('No subjects yet', $html);
        $this->assertStringContainsString('!border-solid', $html, 'No dashed box: the empty state is overridden to solid.');
    }

    /*
    | Monitor progress
    */

    public function test_monitor_progress_orders_by_overdue_then_lowest_progress_and_says_so(): void
    {
        $subject = $this->subject('SECJ3104');
        $this->group($subject, 'Alpha Steady', 2, done: 3, todo: 1);
        $this->group($subject, 'Bravo Behind', 2, done: 1, todo: 3);
        $this->group($subject, 'Charlie Late', 2, done: 1, overdue: 1);
        $this->group($subject, 'Delta Later', 2, overdue: 3);

        $html = $this->pageOf('lecturer.progress');

        $this->assertStringNotContainsString('<table', $html);
        $this->assertStringContainsString('Groups that need attention come first.', $html);

        $positions = array_map(fn ($name) => strpos($html, '>'.$name.'<'), ['Delta Later', 'Charlie Late', 'Bravo Behind', 'Alpha Steady']);
        $this->assertNotContains(false, $positions);
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions);

        // Monitor and Report, and no management actions.
        $this->assertStringContainsString('Monitor', $html);
        $this->assertStringContainsString('Report', $html);
        $this->assertStringNotContainsString('Delete group', $html);
        $this->assertStringNotContainsString('Manage', $html);
    }

    public function test_monitor_progress_with_no_groups_is_friendly(): void
    {
        $html = $this->pageOf('lecturer.progress');

        $this->assertStringContainsString('No groups to monitor', $html);
        $this->assertStringContainsString('!border-solid', $html, 'No dashed box: the empty state is overridden to solid.');
    }

    /*
    | Access and cost
    */

    public function test_only_the_lecturers_own_subjects_and_groups_appear(): void
    {
        $mine = $this->subject('MINE1111');
        $this->group($mine, 'My Group');

        $other = User::factory()->lecturer()->create();
        $theirs = $this->subject('THEIRS22', $other);
        $this->group($theirs, 'Their Group');

        foreach (['lecturer.subjects', 'lecturer.groups', 'lecturer.marking', 'lecturer.progress'] as $route) {
            $html = $this->actingAs($this->lecturer)->get(route($route))->getContent();

            $this->assertStringNotContainsString('THEIRS22', $html, $route);
            $this->assertStringNotContainsString('Their Group', $html, $route);
        }
    }

    public function test_a_student_cannot_open_any_of_them(): void
    {
        $student = User::factory()->student()->create(['must_change_password' => false]);

        foreach (['lecturer.subjects', 'lecturer.groups', 'lecturer.marking', 'lecturer.progress'] as $route) {
            $this->actingAs($student)->get(route($route))->assertForbidden();
        }
    }

    public function test_the_number_of_queries_does_not_grow_with_what_is_listed(): void
    {
        $count = function (string $route): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($this->lecturer)->get(route($route))->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $routes = ['lecturer.subjects', 'lecturer.groups', 'lecturer.marking', 'lecturer.progress'];

        $first = $this->subject('AAA1111');
        $this->group($first, 'G1', 2, done: 1, overdue: 1);
        $this->group($first, 'G1b', 2, done: 1);
        Rubric::create(['subject_id' => $first->id, 'name' => 'R', 'version' => 1, 'is_current' => true, 'created_by' => $this->lecturer->id]);
        $small = array_map($count, $routes);

        foreach (['BBB2222', 'CCC3333', 'DDD4444'] as $code) {
            $subject = $this->subject($code);
            Rubric::create(['subject_id' => $subject->id, 'name' => 'R', 'version' => 1, 'is_current' => true, 'created_by' => $this->lecturer->id]);

            foreach (['X', 'Y', 'Z'] as $name) {
                $this->group($subject, "{$code}{$name}", 3, done: 2, overdue: 1);
            }
        }

        $this->assertSame($small, array_map($count, $routes));
    }
}
