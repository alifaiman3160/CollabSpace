<?php

namespace Tests\Feature\Lecturer;

use App\Models\Group;
use App\Models\Subject;
use App\Models\User;
use App\Support\SubjectPalette;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** The group switcher in the header of a lecturer's single-group pages (replaces the row of every group). */
class GroupSwitcherTest extends TestCase
{
    use RefreshDatabase;

    protected User $lecturer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lecturer = User::factory()->lecturer()->create(['must_change_password' => false]);
    }

    protected function group(string $code, string $name, ?User $owner = null): Group
    {
        $subject = Subject::firstOrCreate(
            ['lecturer_id' => ($owner ?? $this->lecturer)->id, 'code' => $code],
            ['name' => "Name of {$code}", 'group_size' => 4],
        );

        return Group::create(['subject_id' => $subject->id, 'name' => $name, 'group_size' => 4]);
    }

    protected function page(Group $group, string $route = 'lecturer.progress.show'): string
    {
        return $this->actingAs($this->lecturer)->get(route($route, $group))->assertOk()->getContent();
    }

    /** The options as [group name, ...] in page order. */
    protected function optionNames(string $html): array
    {
        preg_match_all('/<a [^>]*data-switch-option[^>]*>\s*<span[^>]*>(.*?)<\/span>/s', $html, $m);

        return array_map('html_entity_decode', $m[1]);
    }

    public function test_the_header_has_the_switcher_with_the_current_group_and_no_picker_row(): void
    {
        $a = $this->group('AAA1111', 'Alpha');
        $this->group('AAA1111', 'Beta');

        $html = $this->page($a);

        $this->assertStringNotContainsString('data-group-picker', $html);
        $this->assertStringNotContainsString('Your groups', $html);
        $this->assertMatchesRegularExpression('/<button[^>]*aria-haspopup="listbox"[^>]*aria-expanded="false"[^>]*data-switch-button.*?data-switch-dot.*?>Alpha<\/span>/s', $html);
        $this->assertStringContainsString('background-color: '.SubjectPalette::for($a->subject_id)['accent'].';" aria-hidden="true" data-switch-dot', $html);

        // The header actions stay, and the back link too.
        $this->assertStringContainsString('Contribution report', $html);
        $this->assertStringContainsString('Manage members', $html);
        $this->assertStringContainsString('All groups', $html);

        // The switcher sits before the action buttons.
        $this->assertLessThan(strpos($html, 'Contribution report'), strpos($html, 'data-group-switcher'));
    }

    public function test_it_lists_only_own_groups_grouped_by_subject_in_order(): void
    {
        $this->group('SECJ3104', 'Zeta');
        $this->group('BCS3323', 'Group 10');
        $this->group('BCS3323', 'Group 2');
        $current = $this->group('SECJ3104', 'Alpha');

        $other = User::factory()->lecturer()->create(['must_change_password' => false]);
        $this->group('BCS3323', 'Theirs', $other);

        $html = $this->page($current);

        $this->assertSame(['Group 2', 'Group 10', 'Alpha', 'Zeta'], $this->optionNames($html), 'Subject code, then group name (numbers as numbers).');
        $this->assertStringNotContainsString('Theirs', $html);
        $this->assertSame(2, substr_count($html, 'data-switch-section'));
        $this->assertLessThan(strpos($html, 'SECJ3104</span>'), strpos($html, 'BCS3323</span>'));
        $this->assertStringContainsString('role="listbox"', $html);
        $this->assertStringContainsString('role="option"', $html);
    }

    public function test_the_current_group_is_marked(): void
    {
        $a = $this->group('AAA1111', 'Alpha');
        $this->group('AAA1111', 'Beta');

        $html = $this->page($a);

        $this->assertSame(1, substr_count($html, 'aria-selected="true"'));
        $this->assertSame(1, preg_match_all('/data-switch-option\s+data-current/', $html));
        $this->assertMatchesRegularExpression('/aria-selected="true"[^>]*aria-current="page"[^>]*>\s*<span[^>]*>Alpha<\/span>\s*<svg/s', $html);
    }

    public function test_the_search_box_appears_only_with_more_than_six_groups(): void
    {
        $groups = collect(range(1, 6))->map(fn ($i) => $this->group('AAA1111', "G{$i}"));

        $html = $this->page($groups[0]);
        $this->assertStringNotContainsString('data-switch-search', $html);

        $seventh = $this->group('AAA1111', 'G7');
        $html = $this->page($seventh);

        $this->assertStringContainsString('data-switch-search', $html);
        $this->assertStringContainsString('<label for="group-switcher-search" class="sr-only">Search groups</label>', $html);
        $this->assertStringContainsString('No groups match', $html);
    }

    public function test_previous_and_next_point_at_the_neighbours_and_stop_at_the_ends(): void
    {
        $a = $this->group('AAA1111', 'Alpha');
        $b = $this->group('AAA1111', 'Beta');
        $c = $this->group('BBB2222', 'Alpha');

        $first = $this->page($a);
        $this->assertMatchesRegularExpression('/<span[^>]*aria-disabled="true"[^>]*data-switch-previous/', $first);
        $this->assertMatchesRegularExpression('/<a href="'.preg_quote(route('lecturer.progress.show', $b), '/').'"[^>]*data-switch-next/', $first);

        $middle = $this->page($b);
        $this->assertMatchesRegularExpression('/<a href="'.preg_quote(route('lecturer.progress.show', $a), '/').'"[^>]*data-switch-previous/', $middle);
        $this->assertMatchesRegularExpression('/<a href="'.preg_quote(route('lecturer.progress.show', $c), '/').'"[^>]*data-switch-next/', $middle);

        $last = $this->page($c);
        $this->assertMatchesRegularExpression('/<a href="'.preg_quote(route('lecturer.progress.show', $b), '/').'"[^>]*data-switch-previous/', $last);
        $this->assertMatchesRegularExpression('/<span[^>]*aria-disabled="true"[^>]*data-switch-next/', $last);
        $this->assertStringContainsString('data-tooltip="Previous group"', $middle);
        $this->assertStringContainsString('data-tooltip="Next group"', $middle);
    }

    public function test_one_group_has_no_switcher(): void
    {
        $this->assertStringNotContainsString('data-group-switcher', $this->page($this->group('AAA1111', 'Alone')));
    }

    public function test_the_contribution_report_page_uses_it_too(): void
    {
        $a = $this->group('AAA1111', 'Alpha');
        $b = $this->group('AAA1111', 'Beta');

        $html = $this->page($a, 'lecturer.reports.show');

        $this->assertStringContainsString('data-group-switcher', $html);
        $this->assertStringContainsString(route('lecturer.reports.show', $b), $html);
        $this->assertStringNotContainsString('bg-surface-muted p-[3px]', $html);
    }

    public function test_access_rules_are_unchanged(): void
    {
        $other = User::factory()->lecturer()->create(['must_change_password' => false]);
        $theirs = $this->group('OTH1111', 'Theirs', $other);
        $this->group('AAA1111', 'Mine');

        $this->actingAs($this->lecturer)->get(route('lecturer.progress.show', $theirs))->assertNotFound();
        $this->actingAs($this->lecturer)->get(route('lecturer.reports.show', $theirs))->assertNotFound();

        $student = User::factory()->student()->create(['must_change_password' => false]);
        $this->actingAs($student)->get(route('lecturer.progress.show', Group::where('name', 'Mine')->first()))->assertForbidden();
    }

    public function test_student_pages_keep_their_group_picker(): void
    {
        $student = User::factory()->student()->create(['must_change_password' => false]);
        $one = $this->group('AAA1111', 'Alpha');
        $two = $this->group('AAA1111', 'Beta');

        foreach ([$one, $two] as $group) {
            $group->groupMembers()->create(['user_id' => $student->id, 'joined_at' => now()]);
        }

        $html = $this->actingAs($student)->get(route('student.board.show', $one))->assertOk()->getContent();

        $this->assertStringContainsString('data-group-picker', $html);
        $this->assertStringNotContainsString('data-group-switcher', $html);
    }

    public function test_the_query_count_does_not_grow_with_the_number_of_groups(): void
    {
        $first = $this->group('AAA1111', 'Alpha');
        $this->group('BBB2222', 'Beta');

        $count = function () use ($first): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->page($first);
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $few = $count();

        foreach (range(1, 12) as $i) {
            $this->group('CCC'.($i % 4), "Extra {$i}");
        }

        $this->assertSame($few, $count());
    }
}
