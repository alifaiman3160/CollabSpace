<?php

namespace Tests\Feature;

use App\Models\Enrolment;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Section;
use App\Models\Subject;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The semester start date and the "Week 5" chip: display only. */
class SemesterWeekTest extends TestCase
{
    use RefreshDatabase;

    protected User $lecturer;

    protected User $student;

    protected Subject $subject;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-11-03 10:00', 'Asia/Kuala_Lumpur'));

        $this->lecturer = User::factory()->lecturer()->create(['must_change_password' => false]);
        $this->subject = Subject::create(['lecturer_id' => $this->lecturer->id, 'code' => 'SECJ3104', 'name' => 'Application Development', 'group_size' => 4]);
        $section = Section::create(['subject_id' => $this->subject->id, 'name' => '1A']);
        $this->student = User::factory()->student()->create(['must_change_password' => false]);
        Enrolment::create(['user_id' => $this->student->id, 'subject_id' => $this->subject->id, 'section_id' => $section->id]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function start(?string $date): void
    {
        $this->subject->update(['semester_start_date' => $date]);
    }

    protected function save(array $extra = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->lecturer)->put(route('lecturer.subjects.update', $this->subject), $extra + ['group_size' => 4]);
    }

    /*
    | The lecturer's form
    */

    public function test_the_form_has_the_field_and_its_helper_text(): void
    {
        $html = $this->actingAs($this->lecturer)->get(route('lecturer.subjects.edit', $this->subject))->assertOk()->getContent();

        $this->assertStringContainsString('Semester start date', $html);
        $this->assertStringContainsString('name="semester_start_date"', $html);
        $this->assertStringContainsString('The first day of Week 1. Students and you will see the current week on each subject. Leave blank to hide it.', $html);
    }

    public function test_the_lecturer_saves_clears_and_validates_the_start_date(): void
    {
        $this->save(['semester_start_date' => '2026-10-06'])->assertSessionHasNoErrors();
        $this->assertSame('2026-10-06', $this->subject->fresh()->semester_start_date->toDateString());

        $html = $this->actingAs($this->lecturer)->get(route('lecturer.subjects.edit', $this->subject))->getContent();
        $this->assertStringContainsString('value="2026-10-06"', $html);

        $this->save(['semester_start_date' => 'next term sometime'])->assertSessionHasErrors('semester_start_date');
        $this->assertSame('2026-10-06', $this->subject->fresh()->semester_start_date->toDateString());

        $this->save(['semester_start_date' => ''])->assertSessionHasNoErrors();
        $this->assertNull($this->subject->fresh()->semester_start_date);
    }

    public function test_another_lecturer_and_a_student_cannot_set_it(): void
    {
        $this->start('2026-10-06');
        $stranger = User::factory()->lecturer()->create(['must_change_password' => false]);

        $this->save(['semester_start_date' => '2026-01-01'], $stranger)->assertNotFound();
        $this->save(['semester_start_date' => '2026-01-01'], $this->student)->assertForbidden();

        $this->assertSame('2026-10-06', $this->subject->fresh()->semester_start_date->toDateString());
    }

    /*
    | The chip
    */

    public function test_the_student_card_shows_the_week_only_when_there_is_a_start_date(): void
    {
        $html = $this->actingAs($this->student)->get(route('student.dashboard'))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-week-chip', $html);

        $this->start('2026-10-06');   // 28 days before the 3rd of November: Week 5

        $html = $this->actingAs($this->student)->get(route('student.dashboard'))->assertOk()->getContent();
        $this->assertSame(1, substr_count($html, 'data-week-chip'));
        $this->assertStringContainsString('>Week 5</span>', $html);
        $this->assertStringContainsString('data-tooltip="Week 5 of the semester, counted from 6 Oct 2026"', $html);
    }

    public function test_the_chip_is_on_the_header_line_of_a_card_with_a_group_too(): void
    {
        $this->start('2026-10-06');
        $group = Group::create(['subject_id' => $this->subject->id, 'name' => 'Team Alpha', 'group_size' => 4]);
        GroupMember::create(['group_id' => $group->id, 'user_id' => $this->student->id, 'joined_at' => now()]);

        $html = $this->actingAs($this->student)->get(route('student.dashboard'))->getContent();

        $this->assertSame(1, substr_count($html, 'data-week-chip'));
        // Before the title line of the card, so it adds no row of its own.
        $this->assertLessThan(strpos($html, 'data-deadline-chip') ?: PHP_INT_MAX, strpos($html, 'data-week-chip'));
        $this->assertLessThan(strpos($html, ' done'), strpos($html, 'data-week-chip'));
    }

    public function test_nothing_shows_before_the_semester_starts_or_after_week_twenty(): void
    {
        $this->start('2026-12-01');
        $this->assertStringNotContainsString('data-week-chip', $this->actingAs($this->student)->get(route('student.dashboard'))->getContent());

        $this->start('2026-01-01');   // well over twenty weeks ago
        $this->assertStringNotContainsString('data-week-chip', $this->actingAs($this->student)->get(route('student.dashboard'))->getContent());
        $this->assertStringNotContainsString('data-week-chip', $this->actingAs($this->lecturer)->get(route('lecturer.subjects'))->getContent());
    }

    public function test_the_lecturer_dashboard_and_my_subjects_show_it(): void
    {
        $this->start('2026-10-06');

        foreach (['lecturer.dashboard', 'lecturer.subjects'] as $route) {
            $html = $this->actingAs($this->lecturer)->get(route($route))->assertOk()->getContent();

            $this->assertSame(1, substr_count($html, 'data-week-chip'), $route);
            $this->assertStringContainsString('>Week 5</span>', $html, $route);
        }

        $this->start(null);

        foreach (['lecturer.dashboard', 'lecturer.subjects'] as $route) {
            $this->assertStringNotContainsString('data-week-chip', $this->actingAs($this->lecturer)->get(route($route))->getContent(), $route);
        }
    }

    public function test_the_deadline_chip_keeps_its_text(): void
    {
        $this->start('2026-10-06');
        $this->subject->update(['project_deadline' => '2026-11-14']);

        $html = $this->actingAs($this->student)->get(route('student.dashboard'))->getContent();

        $this->assertStringContainsString('Due 14 Nov · 11 days left', $html);
        preg_match('/<span[^>]*data-deadline-chip>.*?<\/span>/s', $html, $chip);
        $this->assertStringNotContainsString('Week', $chip[0]);
    }

    /*
    | The group page
    */

    public function test_the_brief_card_gives_the_due_date_as_a_week(): void
    {
        $this->start('2026-10-06');
        $this->subject->update(['brief' => 'Build it.', 'project_deadline' => '2026-12-22']);   // 77 days after the start: Week 12
        $group = Group::create(['subject_id' => $this->subject->id, 'name' => 'Team Alpha', 'group_size' => 4]);
        GroupMember::create(['group_id' => $group->id, 'user_id' => $this->student->id, 'joined_at' => now()]);

        foreach (['student.workspace.show', 'student.board.show'] as $route) {
            $html = $this->actingAs($this->student)->get(route($route, $group))->assertOk()->getContent();

            $this->assertStringContainsString('Group project due 22 December 2026 · Week 12', preg_replace('/\s+/', ' ', $html), $route);
        }
    }

    public function test_the_due_date_week_is_left_out_without_a_start_date(): void
    {
        $this->subject->update(['brief' => 'Build it.', 'project_deadline' => '2026-12-22']);
        $group = Group::create(['subject_id' => $this->subject->id, 'name' => 'Team Alpha', 'group_size' => 4]);
        GroupMember::create(['group_id' => $group->id, 'user_id' => $this->student->id, 'joined_at' => now()]);

        $html = preg_replace('/\s+/', ' ', $this->actingAs($this->student)->get(route('student.workspace.show', $group))->getContent());

        $this->assertStringContainsString('Group project due 22 December 2026', $html);
        $this->assertStringNotContainsString('· Week', $html);
    }

    public function test_it_changes_nothing_but_the_display(): void
    {
        $this->subject->update(['brief' => 'Build it.', 'project_deadline' => '2026-12-22']);
        $before = $this->subject->fresh()->only(['project_deadline', 'group_size', 'brief']);

        $this->start('2026-10-06');

        $this->assertEquals($before, $this->subject->fresh()->only(['project_deadline', 'group_size', 'brief']));
    }
}
