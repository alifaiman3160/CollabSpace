<?php

namespace Tests\Feature\Student;

use App\Models\Announcement;
use App\Models\Enrolment;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Section;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The student dashboard's subject cards, and the project brief that moved to the group pages. */
class DashboardCleanupTest extends TestCase
{
    use RefreshDatabase;

    protected User $lecturer;

    protected User $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lecturer = User::factory()->lecturer()->create(['must_change_password' => false]);
        $this->student = User::factory()->student()->create(['must_change_password' => false]);
    }

    protected function subject(string $code, array $extra = []): Subject
    {
        return Subject::create(['lecturer_id' => $this->lecturer->id, 'code' => $code, 'name' => "Name of {$code}", 'group_size' => 4] + $extra);
    }

    /** The student is enrolled, and grouped when asked. */
    protected function join(Subject $subject, bool $grouped): ?Group
    {
        $section = Section::firstOrCreate(['subject_id' => $subject->id, 'name' => '1A']);
        Enrolment::create(['user_id' => $this->student->id, 'subject_id' => $subject->id, 'section_id' => $section->id]);

        if (! $grouped) {
            return null;
        }

        $group = Group::create(['subject_id' => $subject->id, 'section_id' => $section->id, 'name' => "Group of {$subject->code}", 'group_size' => 4]);
        GroupMember::create(['group_id' => $group->id, 'user_id' => $this->student->id, 'joined_at' => now()]);

        return $group;
    }

    protected function dashboard(): string
    {
        return $this->actingAs($this->student)->get(route('student.dashboard'))->assertOk()->getContent();
    }

    protected function announce(Subject $subject, string $title): void
    {
        Announcement::create(['subject_id' => $subject->id, 'author_id' => $this->lecturer->id, 'title' => $title, 'body' => 'Body']);
    }

    public function test_every_card_keeps_its_strip_quiet_when_empty_and_showing_the_latest_when_not(): void
    {
        $with = $this->subject('AAA1111');
        $without = $this->subject('BBB2222');
        $this->join($with, true);
        $this->join($without, true);
        $this->announce($with, 'First');
        $this->announce($with, 'Second');
        $this->announce($with, 'Third');

        $html = $this->dashboard();

        $this->assertSame(1, substr_count($html, 'data-announcement-strip'), 'The subject that has some.');
        $this->assertSame(1, substr_count($html, 'data-announcements-empty'), 'The one that has none.');
        $this->assertStringContainsString('No announcements yet', $html);
        $this->assertStringContainsString('Third', $html, 'The latest title.');
        $this->assertStringContainsString('3 announcements', $html);
    }

    public function test_a_no_group_card_also_has_the_quiet_strip(): void
    {
        $this->join($this->subject('AAA1111'), false);

        $html = $this->dashboard();

        $this->assertStringContainsString('No group yet', $html);
        $this->assertSame(1, substr_count($html, 'data-announcements-empty'));
        $this->assertStringContainsString('No announcements yet', $html);
    }

    public function test_the_deadline_chip_sits_in_the_header_row_beside_the_arrow_not_on_a_row_of_its_own(): void
    {
        $group = $this->join($this->subject('AAA1111', ['project_deadline' => now()->addDays(12)->toDateString()]), true);
        $this->join($this->subject('BBB2222', ['project_deadline' => now()->addDays(5)->toDateString()]), false);

        $html = $this->dashboard();

        // Before the arrow on a card with a group; in the header row of a no-group card (before its title line).
        $this->assertMatchesRegularExpression('/data-deadline-chip.*?aria-hidden="true">›/s', $html);
        $this->assertLessThan(strpos($html, '>12 days left') === false ? PHP_INT_MAX : strpos($html, ' done'), strpos($html, 'data-deadline-chip'), 'Before the N done line.');
        $waiting = substr($html, strpos($html, 'data-waiting '));
        $this->assertLessThan(strpos($waiting, 'No group yet'), strpos($waiting, 'data-deadline-chip'));
        $grid = substr($html, strpos($html, 'data-subject-grid'), strpos($html, 'aria-label="My tasks"') - strpos($html, 'data-subject-grid'));
        $this->assertSame(2, substr_count($grid, 'flex-1 flex-col justify-between'), 'Both kinds of card fill their height so the strips line up.');
    }

    public function test_the_lecturer_still_gets_the_post_one_pill_when_there_is_nothing(): void
    {
        $this->subject('AAA1111');

        $this->actingAs($this->lecturer)->get(route('lecturer.dashboard'))->assertOk()->assertSee('No announcements yet');
    }

    public function test_subjects_with_a_group_come_before_subjects_without(): void
    {
        $waiting = $this->subject('AAA0001');
        $grouped = $this->subject('ZZZ9999');
        $this->join($waiting, false);
        $this->join($grouped, true);

        $html = $this->dashboard();

        $this->assertLessThan(strpos($html, 'No group yet'), strpos($html, 'ZZZ9999'), 'The grouped subject first, though its code sorts last.');
        $this->assertStringContainsString('Your lecturer will place you in a group of your section.', $html);
        $this->assertStringContainsString('Section 1A', $html);
    }

    public function test_the_deadline_chip_shows_only_when_there_is_a_deadline_and_changes_colour(): void
    {
        $none = $this->subject('AAA1111');
        $far = $this->subject('BBB2222', ['project_deadline' => now()->addDays(12)->toDateString()]);
        $soon = $this->subject('CCC3333', ['project_deadline' => now()->addDays(2)->toDateString()]);
        $late = $this->subject('DDD4444', ['project_deadline' => now()->subDays(3)->toDateString()]);

        foreach ([$none, $far, $soon, $late] as $subject) {
            $this->join($subject, true);
        }

        $html = $this->dashboard();

        $this->assertSame(3, substr_count($html, 'data-deadline-chip'), 'No chip for the subject without a deadline.');
        $this->assertStringContainsString('Due '.now()->addDays(12)->format('j M').' · 12 days left', $html);

        preg_match_all('/<span[^>]*data-deadline-chip>.*?<\/span>/s', $html, $chips);
        $byText = collect($chips[0])->mapWithKeys(fn ($c) => [trim(strip_tags($c)) => $c]);

        $this->assertStringContainsString('bg-white/70', $byText->first(fn ($c, $text) => str_contains($text, '· 12 days left')));
        $this->assertStringContainsString('bg-warning-bg', $byText->first(fn ($c, $text) => str_contains($text, '· 2 days left')));
        $this->assertStringContainsString('bg-danger-bg', $byText->first(fn ($c, $text) => str_contains($text, 'Overdue by 3 days')));
    }

    public function test_the_deadline_chip_is_also_on_a_card_without_a_group(): void
    {
        $this->join($this->subject('AAA1111', ['project_deadline' => now()->addDays(5)->toDateString()]), false);

        $this->assertStringContainsString('5 days left', $this->dashboard());
    }

    public function test_the_grid_is_two_columns_and_three_with_more_than_four_subjects(): void
    {
        foreach (range(1, 4) as $i) {
            $this->join($this->subject("SUB{$i}"), $i % 2 === 0);
        }

        $four = $this->dashboard();
        $this->assertMatchesRegularExpression('/<section class="grid grid-cols-1 [^"]*md:grid-cols-2[^"]*" aria-label="My groups"/', $four);
        $this->assertStringNotContainsString('xl:grid-cols-3', $four);

        $this->join($this->subject('SUB5'), false);

        $this->assertStringContainsString('xl:grid-cols-3', $this->dashboard());
    }

    public function test_the_project_panel_is_gone_from_the_dashboard(): void
    {
        $subject = $this->subject('AAA1111', ['brief' => 'Build a working system.', 'project_deadline' => now()->addDays(12)->toDateString()]);
        $this->join($subject, true);

        $html = $this->dashboard();

        $this->assertStringNotContainsString('Build a working system.', $html);
        $this->assertStringNotContainsString('data-project-brief', $html);
        $this->assertStringNotContainsString('Group project due', $html);
        $this->assertStringContainsString('12 days left', $html, 'Only the small chip on the card.');
    }

    public function test_each_group_page_shows_a_collapsed_project_brief_for_its_own_subject_only(): void
    {
        $one = $this->subject('AAA1111', ['brief' => 'Brief of the first subject.', 'project_deadline' => now()->addDays(9)->toDateString()]);
        $two = $this->subject('BBB2222', ['brief' => 'Brief of the second subject.']);
        $three = $this->subject('CCC3333');
        $groupOne = $this->join($one, true);
        $groupTwo = $this->join($two, true);
        $groupThree = $this->join($three, true);

        foreach (['student.workspace.show', 'student.board.show'] as $route) {
            $html = $this->actingAs($this->student)->get(route($route, $groupOne))->assertOk()->getContent();

            $this->assertSame(1, substr_count($html, 'data-project-brief>'), $route);
            $this->assertStringContainsString('Project brief', $html);
            $this->assertStringContainsString('Brief of the first subject.', $html);
            $this->assertStringNotContainsString('Brief of the second subject.', $html);
            $this->assertStringContainsString('9 days left', $html);

            // Closed until opened: the trigger says so, and the body sits in the shared collapse.
            $this->assertStringContainsString('x-data="{ open: false, instant: false }"', $html);
            $this->assertMatchesRegularExpression('/<button[^>]*x-bind:aria-expanded="open"[^>]*aria-controls="project-brief-'.$one->id.'"/s', $html);
            $this->assertStringContainsString('cs-collapse', $html);

            $other = $this->actingAs($this->student)->get(route($route, $groupTwo))->assertOk()->getContent();
            $this->assertStringContainsString('Brief of the second subject.', $other);
            $this->assertStringNotContainsString('Brief of the first subject.', $other);

            // A subject with neither brief nor deadline shows no card.
            $this->assertStringNotContainsString('data-project-brief', $this->actingAs($this->student)->get(route($route, $groupThree))->assertOk()->getContent());
        }
    }

    public function test_the_word_assignment_no_longer_names_the_project_brief(): void
    {
        $subject = $this->subject('AAA1111', ['brief' => 'A brief']);
        $group = $this->join($subject, true);

        $this->assertStringNotContainsString('Assignment', $this->actingAs($this->student)->get(route('student.workspace.show', $group))->getContent());
        $this->actingAs($this->lecturer)->get(route('lecturer.subjects.edit', $subject))->assertOk()
            ->assertSee('Project brief')->assertDontSee('Assignment brief');
    }
}
