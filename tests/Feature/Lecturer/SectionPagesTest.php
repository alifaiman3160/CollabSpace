<?php

namespace Tests\Feature\Lecturer;

use App\Models\Enrolment;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Section;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Sections on the lecturer's pages: the filter, the labels, the groups summary, and the read-only class list. */
class SectionPagesTest extends TestCase
{
    use RefreshDatabase;

    protected User $lecturer;

    protected Subject $subject;

    protected Section $a;

    protected Section $b;

    protected Group $alpha;

    protected Group $beta;

    protected Group $gamma;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lecturer = User::factory()->lecturer()->create(['must_change_password' => false]);
        $this->subject = Subject::create(['lecturer_id' => $this->lecturer->id, 'code' => 'BCS3323', 'name' => 'Software Engineering', 'group_size' => 4]);
        $this->a = Section::create(['subject_id' => $this->subject->id, 'name' => '1A']);
        $this->b = Section::create(['subject_id' => $this->subject->id, 'name' => '1B']);

        $this->alpha = $this->group('Alpha', $this->a, 'Aina Alpha');
        $this->beta = $this->group('Beta', $this->a, 'Bella Beta');
        $this->gamma = $this->group('Gamma', $this->b, 'Gina Gamma');
    }

    protected function group(string $name, Section $section, string $student): Group
    {
        $group = Group::create(['subject_id' => $section->subject_id, 'section_id' => $section->id, 'name' => $name, 'group_size' => 4]);
        $user = User::factory()->student()->create(['name' => $student, 'must_change_password' => false]);
        Enrolment::create(['user_id' => $user->id, 'subject_id' => $section->subject_id, 'section_id' => $section->id]);
        GroupMember::create(['group_id' => $group->id, 'user_id' => $user->id, 'joined_at' => now()]);

        return $group;
    }

    protected function page(string $route, array $query = []): string
    {
        return $this->actingAs($this->lecturer)->get(route($route, $query))->assertOk()->getContent();
    }

    /*
    | Groups
    */

    public function test_the_groups_page_filters_by_section_and_labels_each_card(): void
    {
        $all = $this->page('lecturer.groups', ['subject' => $this->subject->id]);

        $this->assertStringContainsString('data-section-filter', $all);
        $this->assertStringContainsString('All sections', $all);
        $this->assertStringContainsString('data-section-option="'.$this->a->id.'"', $all);
        $this->assertSame(3, substr_count($all, 'data-group-card-block'));
        $this->assertSame(3, preg_match_all('/Section 1[AB]<\/span>/', $all), 'Every card carries a "Section 1A" chip.');

        $only = $this->page('lecturer.groups', ['subject' => $this->subject->id, 'section' => $this->b->id]);
        $this->assertSame(['Gamma'], $this->namesOn($only), 'The sidebar lists every group, so look at the cards.');
        $this->assertStringContainsString('aria-current="page"', $only);
    }

    public function test_the_groups_page_summarises_groups_per_section(): void
    {
        $html = $this->page('lecturer.groups', ['subject' => $this->subject->id]);

        $this->assertMatchesRegularExpression('/data-section-summary>\s*Section 1A: 2 groups · Section 1B: 1 group\s*</', $html);
    }

    public function test_without_a_chosen_subject_there_is_no_section_row_at_all(): void
    {
        foreach (['lecturer.groups', 'lecturer.progress', 'lecturer.reports', 'lecturer.marking', 'lecturer.students'] as $route) {
            $html = $this->page($route);

            $this->assertStringContainsString('data-subject-filter', $html, $route);
            $this->assertStringNotContainsString('data-section-filter', $html, $route);
            $this->assertStringNotContainsString('data-section-summary', $html, $route);
            $this->assertStringNotContainsString('BCS3323 · Section', $html, $route.' has no combined chips.');
        }
    }

    public function test_the_section_row_appears_for_a_subject_with_two_sections_and_not_for_a_single_one(): void
    {
        $solo = Subject::create(['lecturer_id' => $this->lecturer->id, 'code' => 'SOLO1', 'name' => 'Solo', 'group_size' => 4]);
        $this->group('Solo group', Section::create(['subject_id' => $solo->id, 'name' => 'Main']), 'Sol Student');

        $two = $this->page('lecturer.progress', ['subject' => $this->subject->id]);
        $this->assertStringContainsString('data-section-filter', $two);
        $this->assertLessThan(strpos($two, 'data-tiles'), strpos($two, 'data-section-filter'), 'Above the tiles.');

        $one = $this->page('lecturer.progress', ['subject' => $solo->id]);
        $this->assertStringNotContainsString('data-section-filter', $one);
    }

    public function test_changing_the_subject_drops_the_section_and_a_section_keeps_the_subject(): void
    {
        $other = Subject::create(['lecturer_id' => $this->lecturer->id, 'code' => 'AAA0001', 'name' => 'Another', 'group_size' => 4]);

        $html = $this->page('lecturer.groups', ['subject' => $this->subject->id, 'section' => $this->b->id]);

        // Subject links carry no section; section links carry the subject.
        preg_match('/data-subject-filter.*?<\/div>/s', $html, $row);
        $this->assertStringNotContainsString('section=', $row[0]);
        preg_match('/data-section-filter.*?<\/div>/s', $html, $sections);
        $this->assertStringContainsString('subject='.$this->subject->id, $sections[0]);
        $this->assertStringContainsString('subject='.$other->id, $row[0]);
    }

    public function test_a_stale_or_foreign_section_is_ignored_not_an_error(): void
    {
        $other = User::factory()->lecturer()->create();
        $theirs = Subject::create(['lecturer_id' => $other->id, 'code' => 'OTH1', 'name' => 'Other', 'group_size' => 4]);
        $foreign = Section::create(['subject_id' => $theirs->id, 'name' => '9Z']);
        $mine2 = Subject::create(['lecturer_id' => $this->lecturer->id, 'code' => 'MINE2', 'name' => 'Mine two', 'group_size' => 4]);
        $stale = Section::create(['subject_id' => $mine2->id, 'name' => '5Q']);

        foreach ([$foreign->id, $stale->id] as $section) {
            $html = $this->page('lecturer.reports', ['subject' => $this->subject->id, 'section' => $section]);

            $this->assertSame(['Alpha', 'Beta', 'Gamma'], $this->namesOn($html), 'The whole subject shows, as if no section was asked for.');
            $this->assertStringNotContainsString('9Z', $html);
            $this->assertStringNotContainsString('5Q', $html);
        }
    }

    public function test_more_than_six_subjects_become_a_dropdown(): void
    {
        foreach (range(1, 6) as $i) {
            Subject::create(['lecturer_id' => $this->lecturer->id, 'code' => 'EXTRA'.$i, 'name' => 'Extra '.$i, 'group_size' => 4]);
        }

        $html = $this->page('lecturer.groups', ['subject' => $this->subject->id]);

        $this->assertStringContainsString('data-subject-select', $html);
        $this->assertStringContainsString('data-section-filter', $html, 'The section row is unchanged.');
    }

    public function test_the_counts_follow_both_filters(): void
    {
        $html = $this->page('lecturer.groups', ['subject' => $this->subject->id, 'section' => $this->a->id]);

        $this->assertSame(2, substr_count($html, 'data-group-card-block'));
        $this->assertMatchesRegularExpression('/2 groups in BCS3323/', $html);

        $progress = $this->page('lecturer.progress', ['subject' => $this->subject->id, 'section' => $this->b->id]);
        $this->assertSame(1, substr_count($progress, 'data-pg="'));
    }

    public function test_the_filter_rows_are_labelled_and_the_pills_say_section(): void
    {
        $html = $this->page('lecturer.groups', ['subject' => $this->subject->id]);

        $subjectRow = substr($html, strpos($html, 'data-subject-filter'), 400);
        $this->assertMatchesRegularExpression('/>\s*Subject\s*<\/span>/', $subjectRow);

        $sectionRow = substr($html, strpos($html, 'data-section-filter'), 1500);
        $this->assertMatchesRegularExpression('/>\s*Section\s*<\/span>/', $sectionRow);
        $this->assertStringContainsString('All sections', $sectionRow);
        $this->assertMatchesRegularExpression('/>\s*Section 1A\s*<\/a>/', $sectionRow);
        $this->assertMatchesRegularExpression('/>\s*Section 1B\s*<\/a>/', $sectionRow);
    }

    public function test_the_shared_chip_says_section_has_an_icon_a_tooltip_and_no_subject_colour(): void
    {
        $html = $this->page('lecturer.groups', ['subject' => $this->subject->id]);

        preg_match('/<span[^>]*data-section-chip[^>]*>.*?<\/span>/s', $html, $chip);

        $this->assertStringContainsString('Section 1A', $chip[0]);
        $this->assertStringContainsString('<svg', $chip[0]);
        $this->assertStringContainsString('data-tooltip="Section 1A of BCS3323"', $chip[0]);
        $this->assertStringNotContainsString('background-color', $chip[0], 'Not in the subject palette.');

        $compact = $this->actingAs($this->lecturer)->get(route('lecturer.progress.show', $this->alpha))->assertOk()->getContent();
        $this->assertStringContainsString('Sec 1A', $compact);
    }

    public function test_a_subject_with_one_section_has_nothing_to_filter(): void
    {
        $solo = Subject::create(['lecturer_id' => $this->lecturer->id, 'code' => 'SOLO1', 'name' => 'Solo', 'group_size' => 4]);
        $main = Section::create(['subject_id' => $solo->id, 'name' => 'Main']);
        $this->group('Solo group', $main, 'Sol Student');

        $html = $this->page('lecturer.groups', ['subject' => $solo->id]);

        $this->assertStringNotContainsString('data-section-filter', $html);
    }

    public function test_a_section_id_that_is_not_the_lecturers_is_ignored(): void
    {
        $other = User::factory()->lecturer()->create();
        $theirs = Subject::create(['lecturer_id' => $other->id, 'code' => 'OTH1', 'name' => 'Other', 'group_size' => 4]);
        $foreign = Section::create(['subject_id' => $theirs->id, 'name' => '9Z']);
        Group::create(['subject_id' => $theirs->id, 'section_id' => $foreign->id, 'name' => 'Foreign group', 'group_size' => 4]);

        foreach (['lecturer.groups', 'lecturer.progress', 'lecturer.reports', 'lecturer.marking', 'lecturer.students'] as $route) {
            $html = $this->page($route, ['section' => $foreign->id]);

            $this->assertStringNotContainsString('Foreign group', $html, $route);
            $this->assertStringNotContainsString('9Z', $html, $route);
            $this->assertStringContainsString($route === 'lecturer.students' ? 'Aina Alpha' : 'Alpha', $html, $route.' still shows the lecturer\'s own groups.');
        }
    }

    /*
    | The other lists
    */

    public function test_monitor_progress_filters_by_section(): void
    {
        $this->assertSame(['Alpha', 'Beta', 'Gamma'], $this->namesOn($this->page('lecturer.progress')));
        $this->assertSame(['Gamma'], $this->namesOn($this->page('lecturer.progress', ['subject' => $this->subject->id, 'section' => $this->b->id])));
        $this->assertSame(['Alpha', 'Beta'], $this->namesOn($this->page('lecturer.progress', ['subject' => $this->subject->id, 'section' => $this->a->id])));
    }

    public function test_the_contribution_list_filters_by_section(): void
    {
        $this->assertSame(['Alpha', 'Beta', 'Gamma'], $this->namesOn($this->page('lecturer.reports')));
        $this->assertSame(['Gamma'], $this->namesOn($this->page('lecturer.reports', ['subject' => $this->subject->id, 'section' => $this->b->id])));
        $this->assertStringContainsString('data-section-chip', $this->page('lecturer.reports'));
    }

    public function test_the_marking_list_filters_by_section(): void
    {
        $this->assertSame(['Alpha', 'Beta', 'Gamma'], $this->namesOn($this->page('lecturer.marking')));
        $this->assertSame(['Alpha', 'Beta'], $this->namesOn($this->page('lecturer.marking', ['subject' => $this->subject->id, 'section' => $this->a->id])));
        $this->assertStringContainsString('data-section-chip', $this->page('lecturer.marking'));
    }

    protected function namesOn(string $html): array
    {
        preg_match_all('/data-group-card-block.*?(?:title|data-tooltip)="([^"]+)"/s', $html, $m);

        return collect($m[1])->sort()->values()->all();
    }

    public function test_the_students_page_shows_the_section_searches_it_and_filters_by_it(): void
    {
        $html = $this->page('lecturer.students');
        $this->assertSame(3, substr_count($html, 'data-section-chip'));

        $found = $this->page('lecturer.students', ['search' => '1B']);
        $this->assertStringContainsString('Gina Gamma', $found);
        $this->assertStringNotContainsString('Aina Alpha', $found);

        $filtered = $this->page('lecturer.students', ['subject' => $this->subject->id, 'section' => $this->a->id]);
        $this->assertStringContainsString('Aina Alpha', $filtered);
        $this->assertStringContainsString('Bella Beta', $filtered);
        $this->assertStringNotContainsString('Gina Gamma', $filtered);
        $this->assertStringContainsString('data-section-filter', $filtered);

        // The search box keeps the choice when it searches.
        $this->assertStringContainsString('name="section" value="'.$this->a->id.'"', $filtered);
    }

    /*
    | Headers
    */

    public function test_group_headers_name_the_section(): void
    {
        foreach ([
            route('lecturer.progress.show', $this->gamma),
            route('lecturer.reports.show', $this->gamma),
            route('lecturer.groups.show', $this->gamma),
            route('lecturer.progress.documents', $this->gamma),
        ] as $url) {
            $html = $this->actingAs($this->lecturer)->get($url)->assertOk()->getContent();

            $this->assertStringContainsString('Section 1B', $html, $url);
        }
    }

    public function test_the_group_switcher_labels_each_option_with_its_section(): void
    {
        $html = $this->actingAs($this->lecturer)->get(route('lecturer.progress.show', $this->alpha))->assertOk()->getContent();

        $this->assertSame(3, preg_match_all('/data-switch-option.*?Sec 1[AB]<\/span>/s', $html));
    }

    /*
    | The class list
    */

    public function test_the_class_list_groups_the_enrolled_by_section_and_marks_who_has_no_group(): void
    {
        $free = User::factory()->student()->create(['name' => 'Freya Free', 'matric_no' => 'A21EC7777']);
        Enrolment::create(['user_id' => $free->id, 'subject_id' => $this->subject->id, 'section_id' => $this->b->id]);

        $html = $this->actingAs($this->lecturer)->get(route('lecturer.classlist', $this->subject))->assertOk()->getContent();

        $this->assertStringContainsString('data-class-section="1A"', $html);
        $this->assertStringContainsString('data-class-section="1B"', $html);
        $this->assertStringContainsString('data-read-only-note', $html);
        $this->assertStringContainsString('4 students enrolled · 1 without a group', $html);
        $this->assertSame(1, substr_count($html, 'No group yet'));
        $this->assertStringContainsString('data-no-group', $html);

        $sectionB = substr($html, strpos($html, 'data-class-section="1B"'));
        $this->assertStringContainsString('Gina Gamma', $sectionB);
        $this->assertStringContainsString('Freya Free', $sectionB);
        $this->assertStringContainsString('A21EC7777', $sectionB);

        $sectionA = substr($html, strpos($html, 'data-class-section="1A"'), strpos($html, 'data-class-section="1B"') - strpos($html, 'data-class-section="1A"'));
        $this->assertStringContainsString('Aina Alpha', $sectionA);
        $this->assertStringNotContainsString('Gina Gamma', $sectionA);

        // Read-only: no form, no edit control.
        $this->assertStringNotContainsString('<form method="POST"', substr($html, strpos($html, 'data-read-only-note')));
    }

    public function test_the_class_list_is_linked_from_the_groups_page_and_scoped(): void
    {
        $this->assertStringContainsString(route('lecturer.classlist', $this->subject), $this->page('lecturer.groups', ['subject' => $this->subject->id]));

        $other = User::factory()->lecturer()->create(['must_change_password' => false]);
        $this->actingAs($other)->get(route('lecturer.classlist', $this->subject))->assertNotFound();

        $student = User::factory()->student()->create(['must_change_password' => false]);
        $this->actingAs($student)->get(route('lecturer.classlist', $this->subject))->assertForbidden();
        auth()->logout();
        $this->get(route('lecturer.classlist', $this->subject))->assertRedirect(route('login'));
    }

    public function test_an_empty_class_list_says_so(): void
    {
        $empty = Subject::create(['lecturer_id' => $this->lecturer->id, 'code' => 'EMPTY1', 'name' => 'Empty', 'group_size' => 4]);

        $this->actingAs($this->lecturer)->get(route('lecturer.classlist', $empty))->assertOk()->assertSee('Nobody is enrolled yet');
    }

    public function test_another_lecturers_groups_stay_out_of_every_list(): void
    {
        $other = User::factory()->lecturer()->create(['must_change_password' => false]);
        $theirs = Subject::create(['lecturer_id' => $other->id, 'code' => 'OTH1', 'name' => 'Other', 'group_size' => 4]);
        $sec = Section::create(['subject_id' => $theirs->id, 'name' => '1A']);
        $group = $this->group('Hidden', $sec, 'Hidden Student');

        $this->actingAs($this->lecturer)->get(route('lecturer.progress.show', $group))->assertNotFound();
        $this->actingAs($this->lecturer)->get(route('lecturer.reports.show', $group))->assertNotFound();
        $this->actingAs($this->lecturer)->get(route('lecturer.groups.show', $group))->assertNotFound();

        foreach (['lecturer.groups', 'lecturer.progress', 'lecturer.reports', 'lecturer.marking'] as $route) {
            $this->assertStringNotContainsString('Hidden', $this->page($route), $route);
        }
    }

    public function test_the_list_pages_cost_a_fixed_number_of_queries(): void
    {
        foreach (['lecturer.groups', 'lecturer.progress', 'lecturer.reports', 'lecturer.marking'] as $route) {
            $count = function () use ($route): int {
                DB::flushQueryLog();
                DB::enableQueryLog();
                $this->actingAs($this->lecturer)->get(route($route, ['section' => $this->a->id]))->assertOk();
                $n = count(DB::getQueryLog());
                DB::disableQueryLog();

                return $n;
            };

            $few = $count();

            foreach (range(1, 6) as $i) {
                $this->group("Extra {$i} {$route}", $this->a, "Extra Student {$i}");
            }

            $this->assertSame($few, $count(), $route);
        }
    }
}
