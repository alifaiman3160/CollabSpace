<?php

namespace Tests\Feature\Lecturer;

use App\Models\ActivityLog;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Subject;
use App\Models\User;
use App\Support\SubjectPalette;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The "Select group" cards on Contribution reports: the subject's colour always, a small red pill when members are
 * flagged, and a bar showing how the points are shared.
 */
class ReportCardsTest extends TestCase
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

    /** @param  list<int>  $points  one entry per member: the points that member has */
    protected function group(Subject $subject, string $name, array $points = []): Group
    {
        $group = Group::create(['subject_id' => $subject->id, 'name' => $name, 'group_size' => 4]);

        foreach ($points as $score) {
            $student = User::factory()->student()->create();
            GroupMember::create(['group_id' => $group->id, 'user_id' => $student->id, 'joined_at' => now()]);

            if ($score > 0) {
                ActivityLog::create(['user_id' => $student->id, 'group_id' => $group->id, 'activity_type' => 'task_completed', 'weight' => $score]);
            }
        }

        return $group;
    }

    protected function page(): string
    {
        return $this->actingAs($this->lecturer)->get(route('lecturer.reports'))->assertOk()->getContent();
    }

    protected function card(string $html, string $name): string
    {
        $start = strrpos(substr($html, 0, strpos($html, '>'.$name.'<')), '<article');

        return substr($html, $start, strpos($html, '</article>', $start) - $start);
    }

    public function test_it_renders_for_none_one_and_several_groups(): void
    {
        $html = $this->page();
        $this->assertStringContainsString('No groups to report on', $html);
        $this->assertStringContainsString(route('lecturer.groups'), $html);
        $this->assertStringContainsString('!border-solid', $html);

        $subject = $this->subject('AAA1111');
        $this->group($subject, 'One', [5, 5]);
        $this->assertSame(1, substr_count($this->page(), 'data-group-card-block'));

        foreach (['Two', 'Three'] as $name) {
            $this->group($subject, $name, [3, 3]);
        }
        $html = $this->page();
        $this->assertSame(3, substr_count($html, 'data-group-card-block'));
        $this->assertStringContainsString('Select group', $html);
    }

    public function test_only_the_lecturers_own_groups_appear(): void
    {
        $this->group($this->subject('MINE1111'), 'Mine', [1, 1]);
        $this->group($this->subject('THEIRS22', User::factory()->lecturer()->create()), 'Theirs', [1, 1]);

        $html = $this->page();

        $this->assertStringContainsString('>Mine<', $html);
        $this->assertStringNotContainsString('Theirs', $html);
        $this->assertStringNotContainsString('THEIRS22', $html);
    }

    public function test_a_flagged_card_keeps_the_subject_colour_and_shows_a_small_red_pill(): void
    {
        $subject = $this->subject('AAA1111');
        $flagged = $this->group($subject, 'Flagged Group', [10, 0]);   // one member far below the average
        $fine = $this->group($subject, 'Fine Group', [5, 5]);

        $html = $this->page();
        $colour = SubjectPalette::for($subject->id);

        $card = $this->card($html, 'Flagged Group');
        $this->assertStringContainsString('data-palette="'.$colour['name'].'"', $card);
        $this->assertStringContainsString('background-color: '.$colour['bg'], $card);
        $this->assertStringContainsString('data-flag-pill', $card);
        $this->assertStringContainsString('1 flagged', $card);
        $this->assertStringNotContainsString('bg-danger-bg hover', $card, 'No full red tint.');
        $this->assertStringNotContainsString('border-danger', $card);

        $other = $this->card($html, 'Fine Group');
        $this->assertStringNotContainsString('data-flag-pill', $other);
        $this->assertStringContainsString('data-palette="'.$colour['name'].'"', $other);
    }

    public function test_groups_with_flags_come_first_most_flags_first_then_by_subject_and_name(): void
    {
        $a = $this->subject('AAA1111');
        $b = $this->subject('BBB2222');
        $this->group($a, 'Zulu Fine', [5, 5]);
        $this->group($a, 'Alpha Fine', [5, 5]);
        $this->group($b, 'Bravo One Flag', [10, 0]);
        $this->group($b, 'Charlie Two Flags', [20, 0, 0]);

        $html = $this->page();
        $order = ['Charlie Two Flags', 'Bravo One Flag', 'Alpha Fine', 'Zulu Fine'];
        $positions = array_map(fn ($name) => strpos($html, '>'.$name.'<'), $order);

        $this->assertNotContains(false, $positions);
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions);
        $this->assertStringContainsString('Groups with flagged members come first.', $html);
    }

    public function test_the_order_note_is_only_there_when_something_is_flagged(): void
    {
        $this->group($this->subject('AAA1111'), 'Calm', [5, 5]);

        $this->assertStringNotContainsString('come first', $this->page());
    }

    public function test_view_report_and_the_whole_card_go_to_the_same_report(): void
    {
        $group = $this->group($this->subject('AAA1111'), 'Linked', [4, 4]);

        $html = $this->page();
        $card = $this->card($html, 'Linked');
        $route = route('lecturer.reports.show', $group);

        $this->assertSame(2, substr_count($card, 'href="'.$route.'"'), 'The name (stretched over the card) and View report.');
        $this->assertStringContainsString('View report', $card);
        $this->assertStringContainsString('after:absolute after:inset-0', $card, 'The whole card is the link.');
        $this->assertStringContainsString('focus-within:ring-2', $card, 'A visible focus ring.');
    }

    public function test_the_distribution_bar_segments_add_up_to_the_groups_points_and_are_left_out_at_zero(): void
    {
        $subject = $this->subject('AAA1111');
        $this->group($subject, 'Shared', [6, 3, 1, 0]);
        $this->group($subject, 'Silent', [0, 0]);

        $html = $this->page();

        $shared = $this->card($html, 'Shared');
        preg_match_all('/data-points="(\d+)"/', $shared, $points);
        $this->assertSame([6, 3, 1], array_map('intval', $points[1]), 'One segment for each member with points; nobody with none.');
        $this->assertStringContainsString('10 points', $shared, 'The segments add up to the total.');
        $this->assertSame(10, array_sum(array_map('intval', $points[1])));
        $this->assertMatchesRegularExpression('/title="[^"]+: 6 points"/', $shared, 'A tooltip with the name and points.');

        $silent = $this->card($html, 'Silent');
        $this->assertStringNotContainsString('data-segment', $silent);
        $this->assertStringContainsString('data-distribution', $silent, 'An empty, neutral bar.');
        $this->assertStringContainsString('0 points', $silent);
    }

    public function test_more_than_four_groups_sit_under_their_subjects(): void
    {
        $a = $this->subject('AAA1111');
        $b = $this->subject('BBB2222');
        foreach (['One', 'Two', 'Three'] as $name) {
            $this->group($a, $name, [1, 1]);
        }
        $this->group($b, 'Four', [1, 1]);

        $this->assertStringContainsString('Select group', $this->page());
        $this->assertSame(1, substr_count($this->page(), 'data-report-grid'));

        $this->group($b, 'Five', [1, 1]);
        $html = $this->page();

        $this->assertSame(2, substr_count($html, 'data-report-grid'), 'One grid for each subject.');
        $this->assertStringContainsString('AAA1111', $html);
        $this->assertStringContainsString('Name of BBB2222', $html);
    }

    public function test_the_query_count_does_not_grow_with_the_groups(): void
    {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($this->lecturer)->get(route('lecturer.reports'))->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $subject = $this->subject('AAA1111');
        $this->group($subject, 'First', [3, 1]);
        $small = $count();

        foreach (range(1, 8) as $i) {
            $this->group($this->subject('SUB'.$i.'0000'), "G{$i}", [5, 2, 0]);
        }

        $this->assertSame($small, $count());
    }

    public function test_a_student_cannot_open_the_page(): void
    {
        $student = User::factory()->student()->create(['must_change_password' => false]);

        $this->actingAs($student)->get(route('lecturer.reports'))->assertForbidden();
    }

    public function test_the_report_itself_is_unchanged(): void
    {
        $group = $this->group($this->subject('AAA1111'), 'Same', [10, 0]);

        $this->actingAs($this->lecturer)->get(route('lecturer.reports.show', $group))->assertOk()->assertSee('Same');
    }
}
