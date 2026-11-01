<?php

namespace Tests\Feature\Student;

use App\Models\Announcement;
use App\Models\AppNotification;
use App\Models\Enrolment;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Section;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The student's dashboard and announcements follow enrolment (set by the administrator), not only group membership,
 * and an announcement aimed at one section reaches that section only.
 */
class EnrolmentDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected User $lecturer;

    protected Subject $subject;

    protected Section $a;

    protected Section $b;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lecturer = User::factory()->lecturer()->create(['must_change_password' => false]);
        $this->subject = Subject::create(['lecturer_id' => $this->lecturer->id, 'code' => 'BCS3323', 'name' => 'Software Engineering', 'group_size' => 4]);
        $this->a = Section::create(['subject_id' => $this->subject->id, 'name' => '1A']);
        $this->b = Section::create(['subject_id' => $this->subject->id, 'name' => '1B']);
    }

    protected function student(string $name, ?Section $section = null): User
    {
        $student = User::factory()->student()->create(['name' => $name, 'must_change_password' => false]);

        if ($section) {
            Enrolment::create(['user_id' => $student->id, 'subject_id' => $section->subject_id, 'section_id' => $section->id]);
        }

        return $student;
    }

    protected function announce(string $title, ?Section $section = null, ?Subject $subject = null): Announcement
    {
        return Announcement::create([
            'subject_id' => ($subject ?? $this->subject)->id, 'section_id' => $section?->id,
            'author_id' => $this->lecturer->id, 'title' => $title, 'body' => 'Body of '.$title,
        ]);
    }

    protected function dashboard(User $student): string
    {
        return $this->actingAs($student)->get(route('student.dashboard'))->assertOk()->getContent();
    }

    public function test_an_enrolled_student_with_no_group_still_sees_the_subject_its_section_and_its_announcements(): void
    {
        $student = $this->student('Ina Waiting', $this->a);
        $this->announce('Welcome to everyone');

        $html = $this->dashboard($student);

        $this->assertStringContainsString('data-waiting-subjects', $html);
        $this->assertStringContainsString('BCS3323', $html);
        $this->assertStringContainsString('Software Engineering', $html);
        $this->assertMatchesRegularExpression('/data-waiting.*?Section 1A<\/span>/s', $html);
        $this->assertStringContainsString('No group yet', $html);
        $this->assertStringContainsString('Welcome to everyone', $html);
        $this->assertStringContainsString('You are not in a group yet', $html, 'The explanation stays.');
    }

    public function test_a_student_with_nothing_at_all_gets_the_plain_empty_state(): void
    {
        $html = $this->dashboard($this->student('Nobody'));

        $this->assertStringContainsString('You are not in a group yet', $html);
        $this->assertStringNotContainsString('data-waiting-subjects', $html);
    }

    public function test_a_grouped_subject_shows_its_group_card_and_an_ungrouped_one_a_waiting_card(): void
    {
        $other = Subject::create(['lecturer_id' => $this->lecturer->id, 'code' => 'SECJ3104', 'name' => 'Application Development', 'group_size' => 4]);
        $otherSection = Section::create(['subject_id' => $other->id, 'name' => '2A']);

        $student = $this->student('Mixed Mia', $this->a);
        Enrolment::create(['user_id' => $student->id, 'subject_id' => $other->id, 'section_id' => $otherSection->id]);

        $group = Group::create(['subject_id' => $this->subject->id, 'section_id' => $this->a->id, 'name' => 'Team Alpha', 'group_size' => 4]);
        GroupMember::create(['group_id' => $group->id, 'user_id' => $student->id, 'joined_at' => now()]);

        $html = $this->dashboard($student);

        $this->assertSame(1, preg_match_all('/data-waiting\s/', $html), 'Only the subject without a group is waiting.');
        $this->assertSame(2, substr_count($html, 'data-subject-card'));
        $this->assertStringContainsString('Team Alpha', $html);
        $this->assertMatchesRegularExpression('/Open Team Alpha workspace.*?Section 1A<\/span>/s', $html);
        $this->assertMatchesRegularExpression('/data-waiting.*?Section 2A<\/span>/s', $html);
    }

    public function test_an_announcement_for_one_section_is_seen_by_that_section_only(): void
    {
        $inA = $this->student('Ana', $this->a);
        $inB = $this->student('Bob', $this->b);
        $this->announce('For everyone');
        $this->announce('Only 1A', $this->a);
        $this->announce('Only 1B', $this->b);

        $seenByA = $this->dashboard($inA);
        $this->assertStringContainsString('For everyone', $seenByA);
        $this->assertStringContainsString('Only 1A', $seenByA);
        $this->assertStringNotContainsString('Only 1B', $seenByA);

        $seenByB = $this->dashboard($inB);
        $this->assertStringContainsString('Only 1B', $seenByB);
        $this->assertStringNotContainsString('Only 1A', $seenByB);
    }

    public function test_a_student_known_only_through_a_group_still_gets_the_announcements_for_every_section(): void
    {
        $old = $this->student('Old Olu');
        $group = Group::create(['subject_id' => $this->subject->id, 'name' => 'Legacy', 'group_size' => 4]);
        GroupMember::create(['group_id' => $group->id, 'user_id' => $old->id, 'joined_at' => now()]);
        $this->announce('For everyone');
        $this->announce('Only 1A', $this->a);

        $html = $this->dashboard($old);

        $this->assertStringContainsString('For everyone', $html);
        $this->assertStringNotContainsString('Only 1A', $html);
    }

    public function test_another_subjects_announcements_are_not_shown(): void
    {
        $other = Subject::create(['lecturer_id' => $this->lecturer->id, 'code' => 'SECJ3104', 'name' => 'App Dev', 'group_size' => 4]);
        $student = $this->student('Ana', $this->a);
        $this->announce('Elsewhere', null, $other);

        $this->assertStringNotContainsString('Elsewhere', $this->dashboard($student));
    }

    public function test_marking_an_announcement_read_follows_the_same_visibility(): void
    {
        $inA = $this->student('Ana', $this->a);
        $mine = $this->announce('Only 1A', $this->a);
        $theirs = $this->announce('Only 1B', $this->b);
        $everyone = $this->announce('For everyone');

        $this->actingAs($inA)->postJson(route('student.announcements.read'), ['ids' => [$mine->id, $everyone->id]])->assertOk()->assertJson(['read' => 2]);
        $this->actingAs($inA)->postJson(route('student.announcements.read'), ['ids' => [$theirs->id]])->assertNotFound();
    }

    /*
    | Posting
    */

    public function test_posting_for_one_section_notifies_that_sections_enrolled_students_only(): void
    {
        $inA = $this->student('Ana', $this->a);
        $inB = $this->student('Bob', $this->b);
        $outsider = $this->student('Out');

        $this->actingAs($this->lecturer)->post(route('lecturer.announcements.store', $this->subject), [
            'title' => 'Lab moved', 'body' => 'Now in Lab 3.', 'section_id' => $this->b->id,
        ])->assertSessionHasNoErrors()->assertSessionHas('status', 'Announcement posted. 1 student notified.');

        $this->assertSame(1, AppNotification::where('type', Announcement::TYPE)->count());
        $this->assertTrue(AppNotification::where('user_id', $inB->id)->where('type', Announcement::TYPE)->exists());
        $this->assertFalse(AppNotification::where('user_id', $inA->id)->where('type', Announcement::TYPE)->exists());
        $this->assertSame($this->b->id, Announcement::first()->section_id);
    }

    public function test_posting_for_all_sections_notifies_every_enrolled_student_grouped_or_not(): void
    {
        $inA = $this->student('Ana', $this->a);
        $inB = $this->student('Bob', $this->b);
        $this->student('Out');

        $this->actingAs($this->lecturer)->post(route('lecturer.announcements.store', $this->subject), [
            'title' => 'Hello all', 'body' => 'Welcome.', 'section_id' => '',
        ])->assertSessionHasNoErrors();

        $told = AppNotification::where('type', Announcement::TYPE)->pluck('user_id')->all();
        $this->assertEqualsCanonicalizing([$inA->id, $inB->id], $told);
        $this->assertNull(Announcement::first()->section_id);
    }

    public function test_a_section_of_another_subject_cannot_be_aimed_at(): void
    {
        $other = Subject::create(['lecturer_id' => $this->lecturer->id, 'code' => 'SECJ3104', 'name' => 'App Dev', 'group_size' => 4]);
        $foreign = Section::create(['subject_id' => $other->id, 'name' => '9Z']);

        $this->actingAs($this->lecturer)->post(route('lecturer.announcements.store', $this->subject), [
            'title' => 'Nope', 'body' => 'x', 'section_id' => $foreign->id,
        ])->assertSessionHasErrors('section_id');

        $this->assertSame(0, Announcement::count());
    }

    public function test_the_announcement_form_offers_a_section_only_when_there_are_several(): void
    {
        $html = $this->actingAs($this->lecturer)->get(route('lecturer.announcements', $this->subject))->assertOk()->getContent();
        $this->assertStringContainsString('data-announcement-section', $html);
        $this->assertStringContainsString('All sections', $html);
        $this->assertStringContainsString('Send to section', $html);
        $this->assertStringContainsString('Section 1B</option>', $html);

        $solo = Subject::create(['lecturer_id' => $this->lecturer->id, 'code' => 'SOLO1', 'name' => 'Solo', 'group_size' => 4]);
        Section::create(['subject_id' => $solo->id, 'name' => 'Main']);

        $this->assertStringNotContainsString('data-announcement-section', $this->actingAs($this->lecturer)->get(route('lecturer.announcements', $solo))->assertOk()->getContent());
    }

    public function test_opened_by_counts_only_the_announcements_own_audience(): void
    {
        $inA = $this->student('Ana', $this->a);
        $this->student('Amy', $this->a);
        $inB = $this->student('Bob', $this->b);
        $one = $this->announce('Only 1A', $this->a);
        $this->announce('For everyone');

        \App\Models\AnnouncementRead::create(['announcement_id' => $one->id, 'user_id' => $inA->id, 'read_at' => now()]);
        \App\Models\AnnouncementRead::create(['announcement_id' => $one->id, 'user_id' => $inB->id, 'read_at' => now()]);

        $html = $this->actingAs($this->lecturer)->get(route('lecturer.announcements', $this->subject))->assertOk()->getContent();

        $this->assertStringContainsString('Opened by 1 of 2', $html, 'Bob is not in 1A, so his read does not count.');
        $this->assertStringContainsString('Opened by 0 of 3', $html);
    }

    public function test_a_student_cannot_open_another_sections_group(): void
    {
        $inA = $this->student('Ana', $this->a);
        $group = Group::create(['subject_id' => $this->subject->id, 'section_id' => $this->b->id, 'name' => 'Team B', 'group_size' => 4]);
        $bob = $this->student('Bob', $this->b);
        GroupMember::create(['group_id' => $group->id, 'user_id' => $bob->id, 'joined_at' => now()]);

        $this->actingAs($inA)->get(route('student.workspace.show', $group))->assertNotFound();
        $this->actingAs($inA)->get(route('student.board.show', $group))->assertNotFound();
    }
}
