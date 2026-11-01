<?php

namespace Tests\Feature\Lecturer;

use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Subject;
use App\Models\User;
use App\Support\SubjectPalette;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** The Students page: suggestions from the first letter, and subject chips in the shared palette. */
class StudentSearchTest extends TestCase
{
    use RefreshDatabase;

    protected User $lecturer;

    protected Subject $one;

    protected Subject $two;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lecturer = User::factory()->lecturer()->create(['must_change_password' => false]);
        $this->one = Subject::create(['lecturer_id' => $this->lecturer->id, 'code' => 'BCS3323', 'name' => 'Software Engineering', 'group_size' => 4]);
        $this->two = Subject::create(['lecturer_id' => $this->lecturer->id, 'code' => 'SECJ3104', 'name' => 'Application Development', 'group_size' => 4]);
    }

    protected function member(Subject $subject, string $groupName, string $name, ?string $matric = null): User
    {
        $group = Group::firstOrCreate(['subject_id' => $subject->id, 'name' => $groupName], ['group_size' => 4]);
        $student = User::factory()->student()->create(['name' => $name, 'matric_no' => $matric, 'must_change_password' => false]);
        GroupMember::create(['group_id' => $group->id, 'user_id' => $student->id, 'joined_at' => now()]);

        return $student;
    }

    protected function suggest(string $q, ?User $as = null): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($as ?? $this->lecturer)->getJson(route('lecturer.students.suggestions', ['q' => $q]));
    }

    protected function results(string $q): array
    {
        return $this->suggest($q)->assertOk()->json('results');
    }

    public function test_one_character_is_enough_and_names_that_start_with_it_come_first(): void
    {
        $this->member($this->one, 'Group A', 'Nur Aina');
        $this->member($this->one, 'Group A', 'Aiman Hakim');
        $this->member($this->one, 'Group A', 'Farah Aqilah');

        $names = array_column(array_filter($this->results('a'), fn ($r) => $r['type'] === 'student'), 'name');

        $this->assertSame(['Aiman Hakim', 'Farah Aqilah', 'Nur Aina'], array_slice($names, 0, 3), 'Starts with "a" first, then the rest by name.');
        $this->assertSame([], $this->results(''));
        $this->assertSame([], $this->results('   '));
    }

    public function test_it_matches_name_matric_group_and_subject(): void
    {
        $this->member($this->one, 'Alpha Team', 'Siti Rahayu', 'A21EC0001');
        $this->member($this->two, 'Beta Team', 'Other Person');

        $this->assertSame(['Siti Rahayu'], array_column($this->results('rahay'), 'name'));
        $this->assertSame(['Siti Rahayu'], array_column($this->results('a21ec'), 'name'));

        $group = collect($this->results('alpha'))->firstWhere('type', 'group');
        $this->assertSame('Alpha Team', $group['label']);
        $this->assertSame($this->one->id, $group['subject_id']);

        foreach (['secj', 'application'] as $text) {
            $subject = collect($this->results($text))->firstWhere('type', 'subject');
            $this->assertSame($this->two->id, $subject['subject_id'], $text);
        }

        $this->assertSame('subject', $this->results('bcs')[0]['type'], 'Labelled rows come first.');
    }

    public function test_a_student_in_two_groups_is_two_suggestions_and_each_carries_its_subject_colour(): void
    {
        $student = $this->member($this->one, 'Group A', 'Aiman Hakim');
        GroupMember::create(['group_id' => Group::create(['subject_id' => $this->two->id, 'name' => 'Group B', 'group_size' => 4])->id, 'user_id' => $student->id, 'joined_at' => now()]);

        $rows = array_values(array_filter($this->results('aiman'), fn ($r) => $r['type'] === 'student'));

        $this->assertCount(2, $rows);
        $this->assertEqualsCanonicalizing(['Group A', 'Group B'], array_column($rows, 'group'));

        $byCode = array_column($rows, null, 'subject_code');
        $this->assertSame(SubjectPalette::for($this->one->id)['accent'], $byCode['BCS3323']['accent']);
        $this->assertSame(SubjectPalette::for($this->two->id)['accent'], $byCode['SECJ3104']['accent']);
        $this->assertNotSame($byCode['BCS3323']['palette'], $byCode['SECJ3104']['palette'], 'Two subjects, two colours.');
    }

    public function test_at_most_eight_come_back(): void
    {
        foreach (range(1, 12) as $i) {
            $this->member($this->one, 'Group A', "Sam Student {$i}");
        }

        $this->assertCount(8, $this->results('sam'));
    }

    public function test_wildcards_in_the_text_are_literal(): void
    {
        $this->member($this->one, 'Group A', 'Aiman Hakim');
        $this->member($this->one, 'Group A', '100% Real');
        $this->member($this->one, 'Group A', 'Under_Score');

        $this->assertSame([], $this->results('%'.'zz'), 'A percent sign is not "anything".');
        $this->assertSame(['100% Real'], array_column($this->results('0%'), 'name'));
        $this->assertSame(['Under_Score'], array_column($this->results('r_s'), 'name'), '"_" is not "any one character".');
        $this->assertSame([], $this->results('_'.'iman'));
    }

    public function test_only_the_lecturers_own_students_are_offered(): void
    {
        $this->member($this->one, 'Group A', 'Mine Student');

        $other = User::factory()->lecturer()->create(['must_change_password' => false]);
        $theirs = Subject::create(['lecturer_id' => $other->id, 'code' => 'OTH1', 'name' => 'Other', 'group_size' => 4]);
        $this->member($theirs, 'Their Group', 'Other Student');

        $this->assertSame(['Mine Student'], array_column(array_filter($this->results('student'), fn ($r) => $r['type'] === 'student'), 'name'));
        $this->assertSame([], $this->results('their'), 'Not even their group or subject.');
        $this->assertSame([], $this->results('oth'));
    }

    public function test_a_student_cannot_use_it_and_a_guest_is_sent_to_login(): void
    {
        $student = $this->member($this->one, 'Group A', 'Aiman Hakim');

        $this->suggest('a', $student)->assertForbidden();
        $this->getJson(route('lecturer.students.suggestions', ['q' => 'a']))->assertForbidden();
    }

    public function test_it_is_throttled(): void
    {
        $this->member($this->one, 'Group A', 'Aiman Hakim');

        foreach (range(1, 60) as $i) {
            $this->suggest('a')->assertOk();
        }

        $this->suggest('a')->assertStatus(429);
    }

    public function test_the_query_count_is_fixed_whatever_the_number_of_students(): void
    {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->suggest('s')->assertOk();

            return count(DB::getQueryLog());
        };

        $this->member($this->one, 'Group A', 'Sam One');
        $few = $count();

        foreach (range(2, 20) as $i) {
            $this->member($this->two, "Group {$i}", "Sam {$i}");
        }

        $this->assertSame($few, $count());
        $this->assertLessThanOrEqual(6, $few);
    }

    public function test_the_page_renders_the_combobox_and_filters_by_group_or_subject(): void
    {
        $a = $this->member($this->one, 'Group A', 'Aiman Hakim');
        $this->member($this->two, 'Group B', 'Bella Binti');
        $group = Group::where('name', 'Group A')->first();

        $html = $this->actingAs($this->lecturer)->get(route('lecturer.students'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<input[^>]*role="combobox"[^>]*aria-controls="student-suggestions"/s', $html);
        $this->assertStringContainsString('aria-expanded="false"', $html);
        $this->assertStringContainsString('x-bind:aria-activedescendant', $html);
        $this->assertStringContainsString('role="listbox"', $html);
        $this->assertStringContainsString('role="option"', $html);
        $this->assertStringContainsString('data-url="'.route('lecturer.students.suggestions').'"', $html);
        $this->assertStringContainsString('name="group"', $html);
        $this->assertStringContainsString('name="subject"', $html);

        $byGroup = $this->actingAs($this->lecturer)->get(route('lecturer.students', ['group' => $group->id]))->assertOk()->getContent();
        $this->assertStringContainsString('Aiman Hakim', $byGroup);
        $this->assertStringNotContainsString('Bella Binti', $byGroup);
        $this->assertStringContainsString('data-active-filter', $byGroup);

        $bySubject = $this->actingAs($this->lecturer)->get(route('lecturer.students', ['subject' => $this->two->id]))->assertOk()->getContent();
        $this->assertStringContainsString('Bella Binti', $bySubject);
        $this->assertStringNotContainsString('Aiman Hakim', $bySubject);

        // A group of somebody else's is simply not there.
        $other = User::factory()->lecturer()->create(['must_change_password' => false]);
        $theirs = Group::create(['subject_id' => Subject::create(['lecturer_id' => $other->id, 'code' => 'OTH1', 'name' => 'Other', 'group_size' => 4])->id, 'name' => 'Theirs', 'group_size' => 4]);
        $this->actingAs($this->lecturer)->get(route('lecturer.students', ['group' => $theirs->id]))->assertOk()->assertDontSee('Theirs');
    }

    public function test_subject_chips_in_the_table_use_the_subjects_own_palette_colour(): void
    {
        $this->member($this->one, 'Group A', 'Aiman Hakim');
        $this->member($this->two, 'Group B', 'Bella Binti');

        $html = $this->actingAs($this->lecturer)->get(route('lecturer.students'))->assertOk()->getContent();

        preg_match_all('/<span[^>]*style="background-color: (#[0-9a-f]{6});"[^>]*data-subject-chip data-palette="([a-z]+)"[^>]*>([A-Z0-9]+)<\/span>/', $html, $chips, PREG_SET_ORDER);
        $byCode = [];

        foreach ($chips as $chip) {
            $byCode[$chip[3]] = [$chip[1], $chip[2]];
        }

        $this->assertSame([SubjectPalette::for($this->one->id)['accent'], SubjectPalette::for($this->one->id)['name']], $byCode['BCS3323']);
        $this->assertSame([SubjectPalette::for($this->two->id)['accent'], SubjectPalette::for($this->two->id)['name']], $byCode['SECJ3104']);
        $this->assertNotSame($byCode['BCS3323'], $byCode['SECJ3104']);
        $this->assertStringNotContainsString('bg-periwinkle-soft text-periwinkle-link', substr($html, strpos($html, '<table')), 'No fixed blue chip left in the table.');
    }
}
