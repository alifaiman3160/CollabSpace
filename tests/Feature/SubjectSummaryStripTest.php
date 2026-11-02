<?php

namespace Tests\Feature;

use App\Models\Enrolment;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Section;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The summary strip at the top of the lecturer's Edit subject page. */
class SubjectSummaryStripTest extends TestCase
{
    use RefreshDatabase;

    protected User $lecturer;

    protected Subject $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lecturer = User::factory()->lecturer()->create(['name' => 'Dr Strip', 'must_change_password' => false]);
        $this->subject = Subject::create(['lecturer_id' => $this->lecturer->id, 'code' => 'SECJ3104', 'name' => 'App Dev', 'group_size' => 4]);
    }

    protected function enrol(Section $section, bool $inGroup, ?Group $group = null): User
    {
        $student = User::factory()->student()->create(['must_change_password' => false]);
        Enrolment::create(['user_id' => $student->id, 'subject_id' => $this->subject->id, 'section_id' => $section->id]);

        if ($inGroup) {
            GroupMember::create(['group_id' => $group->id, 'user_id' => $student->id, 'joined_at' => now()]);
        }

        return $student;
    }

    protected function strip(): string
    {
        $html = $this->actingAs($this->lecturer)->get(route('lecturer.subjects.edit', $this->subject))->assertOk()->getContent();
        $start = strpos($html, 'data-subject-summary');
        $this->assertNotFalse($start);

        return substr($html, $start, strpos($html, '</section>', $start) - $start);
    }

    public function test_the_strip_shows_the_subject_the_four_counts_and_the_quick_links(): void
    {
        $a = Section::create(['subject_id' => $this->subject->id, 'name' => '1A']);
        $b = Section::create(['subject_id' => $this->subject->id, 'name' => '1B']);
        $g1 = Group::create(['subject_id' => $this->subject->id, 'section_id' => $a->id, 'name' => 'G1', 'group_size' => 4]);
        $this->enrol($a, true, $g1);
        $this->enrol($a, true, $g1);
        $this->enrol($b, false);

        $strip = $this->strip();

        $this->assertStringContainsString('SECJ3104', $strip);
        $this->assertStringContainsString('App Dev', $strip);
        $this->assertStringContainsString('Dr Strip', $strip);
        $this->assertStringContainsString('Section 1A', $strip);
        $this->assertStringContainsString('Section 1B', $strip);
        $this->assertStringContainsString('Managed by the administrator.', $strip);

        $count = fn (string $key) => (int) (preg_match('/data-stat="'.$key.'">.*?<span class="text-h2[^>]*>(\d+)</s', $strip, $m) ? $m[1] : -1);
        $this->assertSame(1, $count('groups'));
        $this->assertSame(3, $count('students'));
        $this->assertSame(1, $count('ungrouped'));
        $this->assertSame(0, $count('assessments'));
        $this->assertStringContainsString('border-warning-bg', substr($strip, strpos($strip, 'data-stat="ungrouped"') - 300, 400));

        $this->assertStringContainsString('Section 1A: 2 students, 1 group · Section 1B: 1 student, 0 groups', preg_replace('/\s+/', ' ', $strip));

        foreach ([
            route('lecturer.groups', ['subject' => $this->subject->id]),
            route('lecturer.classlist', $this->subject),
            route('lecturer.marking', ['subject' => $this->subject->id]),
            route('lecturer.reports', ['subject' => $this->subject->id]),
        ] as $url) {
            $this->assertStringContainsString('href="'.$url.'"', $strip);
        }
    }

    public function test_a_single_section_subject_has_no_per_section_line_and_no_warning_when_everyone_is_grouped(): void
    {
        $a = Section::create(['subject_id' => $this->subject->id, 'name' => '1A']);
        $g1 = Group::create(['subject_id' => $this->subject->id, 'section_id' => $a->id, 'name' => 'G1', 'group_size' => 4]);
        $this->enrol($a, true, $g1);

        $strip = $this->strip();

        $this->assertStringNotContainsString('data-section-summary', $strip);
        $this->assertStringNotContainsString('border-warning-bg', $strip);
    }

    public function test_the_removed_cards_are_gone_and_another_lecturer_gets_404(): void
    {
        $html = $this->actingAs($this->lecturer)->get(route('lecturer.subjects.edit', $this->subject))->getContent();
        $this->assertStringNotContainsString('Manage its groups', $html);
        $this->assertStringNotContainsString('This subject has', $html);
        $this->assertSame(1, substr_count($html, 'data-subject-readonly'));

        $other = User::factory()->lecturer()->create(['must_change_password' => false]);
        $this->actingAs($other)->get(route('lecturer.subjects.edit', $this->subject))->assertNotFound();
    }
}
