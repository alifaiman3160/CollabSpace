<?php

namespace Tests\Feature\Admin;

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

/** The administrator owns subjects: creates them, gives each to a lecturer, and keeps its sections. */
class SubjectManagementTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $lecturer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create(['must_change_password' => false]);
        $this->lecturer = User::factory()->lecturer()->create(['name' => 'Dr. Aminah', 'email' => 'aminah@uni.test', 'must_change_password' => false]);
    }

    protected function payload(array $extra = []): array
    {
        return [
            'code' => 'secj3104', 'name' => 'Application Development', 'lecturer_id' => $this->lecturer->id,
            'sections' => [['id' => '', 'name' => '1A'], ['id' => '', 'name' => '1B']],
            ...$extra,
        ];
    }

    protected function create(array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin)->post(route('admin.subjects.store'), $this->payload($extra));
    }

    /** A subject with two sections, a student in 1A and a group in 1A. */
    protected function busy(): array
    {
        $this->create()->assertSessionHasNoErrors();
        $subject = Subject::where('code', 'SECJ3104')->sole();
        $a = $subject->sections()->where('name', '1A')->sole();
        $b = $subject->sections()->where('name', '1B')->sole();

        $student = User::factory()->student()->create();
        Enrolment::create(['user_id' => $student->id, 'subject_id' => $subject->id, 'section_id' => $a->id]);
        $group = Group::create(['subject_id' => $subject->id, 'section_id' => $a->id, 'name' => 'Team Alpha', 'group_size' => 4]);
        GroupMember::create(['group_id' => $group->id, 'user_id' => $student->id, 'joined_at' => now()]);

        return [$subject, $a, $b, $student, $group];
    }

    protected function update(Subject $subject, array $extra = []): \Illuminate\Testing\TestResponse
    {
        $current = [
            'code' => $subject->code, 'name' => $subject->name, 'lecturer_id' => $subject->lecturer_id,
            'sections' => $subject->sections()->orderBy('name')->get()->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])->all(),
        ];

        return $this->actingAs($this->admin)->put(route('admin.subjects.update', $subject), $extra + $current);
    }

    /*
    | Access
    */

    public function test_only_administrators_reach_the_subjects_screens(): void
    {
        $subject = Subject::create(['lecturer_id' => $this->lecturer->id, 'code' => 'X1', 'name' => 'X', 'group_size' => 4]);
        $student = User::factory()->student()->create(['must_change_password' => false]);

        foreach ([$this->lecturer, $student] as $user) {
            $this->actingAs($user)->get(route('admin.subjects'))->assertForbidden();
            $this->actingAs($user)->get(route('admin.subjects.create'))->assertForbidden();
            $this->actingAs($user)->post(route('admin.subjects.store'), $this->payload())->assertForbidden();
            $this->actingAs($user)->get(route('admin.subjects.edit', $subject))->assertForbidden();
            $this->actingAs($user)->put(route('admin.subjects.update', $subject), $this->payload())->assertForbidden();
            $this->actingAs($user)->delete(route('admin.subjects.destroy', $subject))->assertForbidden();
        }

        auth()->logout();
        $this->get(route('admin.subjects'))->assertRedirect(route('login'));
        $this->assertSame(1, Subject::count());
    }

    /*
    | Creating
    */

    public function test_an_administrator_creates_a_subject_for_a_lecturer_with_sections(): void
    {
        $this->create()->assertSessionHasNoErrors()->assertRedirect(route('admin.subjects'));

        $subject = Subject::sole();
        $this->assertSame('SECJ3104', $subject->code, 'Upper-cased.');
        $this->assertSame($this->lecturer->id, $subject->lecturer_id);
        $this->assertSame(['1A', '1B'], $subject->sections()->orderBy('name')->pluck('name')->all());
    }

    public function test_a_new_subject_can_have_no_sections_yet(): void
    {
        $this->create(['sections' => []])->assertSessionHasNoErrors();

        $this->assertSame(0, Section::count());
    }

    public function test_the_assigned_lecturer_is_told(): void
    {
        $this->create();

        $note = AppNotification::where('user_id', $this->lecturer->id)->where('type', 'subject_assigned')->sole();
        $this->assertSame('You have been assigned SECJ3104 Application Development', $note->message);
        $this->assertSame(route('lecturer.subjects', absolute: false), $note->link);
        $this->assertSame(0, AppNotification::where('user_id', $this->admin->id)->where('type', 'subject_assigned')->count());
    }

    public function test_code_and_name_are_required_and_the_lecturer_must_be_a_lecturer(): void
    {
        $this->create(['code' => '', 'name' => ''])->assertSessionHasErrors(['code', 'name']);

        $student = User::factory()->student()->create();
        $this->create(['lecturer_id' => $student->id])->assertSessionHasErrors('lecturer_id');
        $this->create(['lecturer_id' => $this->admin->id])->assertSessionHasErrors('lecturer_id');
        $this->create(['lecturer_id' => ''])->assertSessionHasErrors('lecturer_id');
        $this->create(['lecturer_id' => 99999])->assertSessionHasErrors('lecturer_id');

        $this->assertSame(0, Subject::count());
    }

    public function test_a_lecturer_cannot_have_two_subjects_with_one_code_but_two_lecturers_can(): void
    {
        $this->create()->assertSessionHasNoErrors();

        $this->create(['name' => 'Again'])->assertSessionHasErrors('lecturer_id');
        $this->assertStringContainsString('This lecturer already has a subject with this code.', session('errors')->first('lecturer_id'));
        $this->assertSame(1, Subject::count());

        $other = User::factory()->lecturer()->create();
        $this->create(['lecturer_id' => $other->id])->assertSessionHasNoErrors();

        $this->assertSame(2, Subject::where('code', 'SECJ3104')->count());
    }

    public function test_section_names_are_trimmed_short_and_different_within_the_subject(): void
    {
        $this->create(['sections' => [['id' => '', 'name' => '  1A  ']]])->assertSessionHasNoErrors();
        $this->assertSame('1A', Section::sole()->name);

        $this->create(['code' => 'DUP1', 'sections' => [['id' => '', 'name' => '1A'], ['id' => '', 'name' => '1a']]])
            ->assertSessionHasErrors('sections.1.name');
        $this->create(['code' => 'LONG1', 'sections' => [['id' => '', 'name' => str_repeat('x', 21)]]])
            ->assertSessionHasErrors('sections.0.name');

        $this->assertSame(0, Subject::whereIn('code', ['DUP1', 'LONG1'])->count());
    }

    /*
    | Sections
    */

    public function test_renaming_a_section_keeps_its_students_and_groups(): void
    {
        [$subject, $a, $b, $student, $group] = $this->busy();

        $this->update($subject, ['sections' => [['id' => $a->id, 'name' => '2A'], ['id' => $b->id, 'name' => '1B']]])
            ->assertSessionHasNoErrors();

        $this->assertSame('2A', $a->fresh()->name);
        $this->assertSame($a->id, Enrolment::where('user_id', $student->id)->value('section_id'));
        $this->assertSame($a->id, $group->fresh()->section_id);
    }

    public function test_two_sections_can_swap_names(): void
    {
        [$subject, $a, $b] = $this->busy();

        $this->update($subject, ['sections' => [['id' => $a->id, 'name' => '1B'], ['id' => $b->id, 'name' => '1A']]])
            ->assertSessionHasNoErrors();

        $this->assertSame('1B', $a->fresh()->name);
        $this->assertSame('1A', $b->fresh()->name);
    }

    public function test_the_default_main_section_can_be_renamed(): void
    {
        $subject = Subject::create(['lecturer_id' => $this->lecturer->id, 'code' => 'OLD1', 'name' => 'Old', 'group_size' => 4]);
        $main = Section::create(['subject_id' => $subject->id, 'name' => 'Main']);

        $this->update($subject, ['sections' => [['id' => $main->id, 'name' => '1A'], ['id' => '', 'name' => '1B']]])->assertSessionHasNoErrors();

        $this->assertSame(['1A', '1B'], $subject->sections()->orderBy('name')->pluck('name')->all());
        $this->assertSame($main->id, $subject->sections()->where('name', '1A')->value('id'));
    }

    public function test_a_section_with_students_or_groups_cannot_be_deleted_and_nothing_else_is_saved(): void
    {
        [$subject, $a, $b] = $this->busy();

        $this->update($subject, ['name' => 'Renamed anyway', 'sections' => [['id' => $b->id, 'name' => '1B']]])
            ->assertSessionHasErrors('sections');

        $this->assertStringContainsString('Section 1A cannot be removed: 1 student enrolled and 1 group in it', session('errors')->first('sections'));
        $this->assertNotNull(Section::find($a->id));
        $this->assertSame('Application Development', $subject->fresh()->name, 'The save was rolled back as a whole.');
    }

    public function test_an_unused_section_can_be_deleted_and_a_new_one_added(): void
    {
        [$subject, $a, $b] = $this->busy();

        $this->update($subject, ['sections' => [['id' => $a->id, 'name' => '1A'], ['id' => '', 'name' => '2A']]])->assertSessionHasNoErrors();

        $this->assertNull(Section::find($b->id), '1B was unused, so it went.');
        $this->assertSame(['1A', '2A'], $subject->sections()->orderBy('name')->pluck('name')->all());
    }

    public function test_a_section_id_from_another_subject_is_ignored(): void
    {
        [$subject] = $this->busy();
        $other = Subject::create(['lecturer_id' => $this->lecturer->id, 'code' => 'OTH1', 'name' => 'Other', 'group_size' => 4]);
        $theirs = Section::create(['subject_id' => $other->id, 'name' => 'Theirs']);

        $this->update($subject, ['sections' => [['id' => $theirs->id, 'name' => 'Hijack']]])->assertSessionHas('errors');

        $this->assertSame('Theirs', $theirs->fresh()->name);
    }

    /*
    | Changing the lecturer
    */

    public function test_changing_the_lecturer_of_a_used_subject_needs_confirmation(): void
    {
        [$subject] = $this->busy();
        $new = User::factory()->lecturer()->create(['must_change_password' => false]);

        $this->update($subject, ['lecturer_id' => $new->id])->assertSessionHasErrors('confirm_lecturer');
        $this->assertSame($this->lecturer->id, $subject->fresh()->lecturer_id);

        $html = $this->actingAs($this->admin)->get(route('admin.subjects.edit', $subject))->assertOk()->getContent();
        $this->assertStringContainsString('Groups, marks and announcements stay with the subject and move to the new lecturer', $html);
        $this->assertStringContainsString('data-lecturer-confirm', $html);
    }

    public function test_after_a_confirmed_change_the_old_lecturer_loses_access_and_the_new_one_gains_it(): void
    {
        [$subject, , , , $group] = $this->busy();
        $announcement = Announcement::create(['subject_id' => $subject->id, 'author_id' => $this->lecturer->id, 'title' => 'Hello', 'body' => 'x']);
        $new = User::factory()->lecturer()->create(['must_change_password' => false]);

        $this->update($subject, ['lecturer_id' => $new->id, 'confirm_lecturer' => 1])->assertSessionHasNoErrors();

        // Groups, announcements and the rest stay with the subject.
        $this->assertSame($subject->id, $group->fresh()->subject_id);
        $this->assertSame(1, Announcement::where('subject_id', $subject->id)->count());

        // The previous lecturer is shut out (404, as for any foreign subject)...
        $this->actingAs($this->lecturer)->get(route('lecturer.subjects.edit', $subject))->assertNotFound();
        $this->actingAs($this->lecturer)->get(route('lecturer.groups.show', $group))->assertNotFound();
        $this->actingAs($this->lecturer)->get(route('lecturer.classlist', $subject))->assertNotFound();
        $this->actingAs($this->lecturer)->get(route('lecturer.announcements', $subject))->assertNotFound();
        $this->assertStringNotContainsString('Team Alpha', $this->actingAs($this->lecturer)->get(route('lecturer.groups'))->getContent());

        // ...and the new one is let in.
        $this->actingAs($new)->get(route('lecturer.subjects.edit', $subject))->assertOk();
        $this->actingAs($new)->get(route('lecturer.groups.show', $group))->assertOk();
        $this->actingAs($new)->get(route('lecturer.classlist', $subject))->assertOk();
        $this->assertStringContainsString('Team Alpha', $this->actingAs($new)->get(route('lecturer.groups'))->getContent());

        $this->assertTrue(AppNotification::where('user_id', $new->id)->where('type', 'subject_assigned')->exists());
    }

    public function test_an_empty_subject_changes_lecturer_without_the_extra_step(): void
    {
        $subject = Subject::create(['lecturer_id' => $this->lecturer->id, 'code' => 'EMPTY1', 'name' => 'Empty', 'group_size' => 4]);
        $new = User::factory()->lecturer()->create();

        $this->update($subject, ['lecturer_id' => $new->id])->assertSessionHasNoErrors();

        $this->assertSame($new->id, $subject->fresh()->lecturer_id);
    }

    public function test_saving_without_changing_the_lecturer_does_not_notify_again(): void
    {
        [$subject] = $this->busy();
        AppNotification::query()->delete();

        $this->update($subject, ['name' => 'A better name'])->assertSessionHasNoErrors();

        $this->assertSame(0, AppNotification::where('type', 'subject_assigned')->count());
    }

    /*
    | Deleting
    */

    public function test_a_subject_with_groups_or_enrolments_cannot_be_deleted(): void
    {
        [$subject, , , $student, $group] = $this->busy();

        $this->actingAs($this->admin)->delete(route('admin.subjects.destroy', $subject))->assertSessionHasErrors('status');
        $this->assertDatabaseHas('subjects', ['id' => $subject->id]);

        $group->delete();
        $this->actingAs($this->admin)->delete(route('admin.subjects.destroy', $subject))->assertSessionHasErrors('status');
        $this->assertStringContainsString('still has groups or enrolled students', session('errors')->first('status'));

        Enrolment::where('user_id', $student->id)->delete();
        $this->actingAs($this->admin)->delete(route('admin.subjects.destroy', $subject))->assertRedirect(route('admin.subjects'));

        $this->assertDatabaseMissing('subjects', ['id' => $subject->id]);
        $this->assertSame(0, Section::count(), 'Its sections go with it.');
    }

    /*
    | The list
    */

    public function test_the_list_shows_cards_with_lecturer_sections_and_counts(): void
    {
        $this->busy();
        $other = User::factory()->lecturer()->create(['name' => 'Dr. Zul', 'must_change_password' => false]);
        Subject::create(['lecturer_id' => $other->id, 'code' => 'BCS1111', 'name' => 'Networks', 'group_size' => 4]);

        $html = $this->actingAs($this->admin)->get(route('admin.subjects'))->assertOk()->getContent();

        $this->assertSame(2, substr_count($html, 'data-subject-card'));
        $this->assertStringContainsString('Dr. Aminah', $html);
        $this->assertStringContainsString('Dr. Zul', $html);
        $this->assertStringContainsString('Section 1A', $html);
        $this->assertStringContainsString('Section 1B', $html);
        $this->assertStringContainsString('No sections yet', $html);
        $this->assertMatchesRegularExpression('/Students enrolled.*?>\s*1\s*</s', $html);
        $this->assertStringContainsString('Groups', $html);
    }

    public function test_the_list_searches_by_code_name_or_lecturer_and_filters_by_lecturer(): void
    {
        $this->busy();
        $other = User::factory()->lecturer()->create(['name' => 'Dr. Zul', 'must_change_password' => false]);
        Subject::create(['lecturer_id' => $other->id, 'code' => 'BCS1111', 'name' => 'Networks', 'group_size' => 4]);
        $get = fn (array $q) => $this->actingAs($this->admin)->get(route('admin.subjects', $q))->assertOk()->getContent();

        foreach (['secj' => 'Application Development', 'networks' => 'BCS1111', 'zul' => 'BCS1111'] as $search => $expected) {
            $this->assertStringContainsString($expected, $get(['search' => $search]), $search);
        }

        $this->assertStringNotContainsString('BCS1111', $get(['search' => 'secj']));
        $this->assertStringNotContainsString('SECJ3104', $get(['lecturer' => $other->id]));
        $this->assertStringContainsString('No subjects match that', $get(['search' => 'nothing-like-this']));
    }

    public function test_the_admin_sidebar_has_a_subjects_item_next_to_user_accounts(): void
    {
        $html = $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString(route('admin.subjects'), $html);
        $this->assertLessThan(strpos($html, route('admin.subjects')), strpos($html, route('admin.users')));
    }

    public function test_the_forms_carry_the_lecturer_dropdown_and_the_sections_block(): void
    {
        $student = User::factory()->student()->create(['name' => 'Not A Lecturer']);

        foreach ([route('admin.subjects.create')] as $url) {
            $html = $this->actingAs($this->admin)->get($url)->assertOk()->getContent();

            $this->assertStringContainsString('Dr. Aminah — aminah@uni.test', $html);
            $this->assertStringNotContainsString('Not A Lecturer', $html, 'Lecturer accounts only.');
            $this->assertStringContainsString('data-subject-sections-block', $html);
        }
    }
}
