<?php

namespace Tests\Feature\Admin;

use App\Models\Enrolment;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Section;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** The administrator enrols a student: "Subjects and sections" on the New user and Edit forms. */
class EnrolmentFormTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Subject $one;

    protected Subject $two;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->admin = User::factory()->admin()->create(['must_change_password' => false]);

        $this->one = $this->subject('Dr. Aminah', 'BCS3323', 'Software Engineering');
        $this->two = $this->subject('Dr. Zul', 'SECJ3104', 'Application Development');
    }

    protected function subject(string $lecturerName, string $code, string $name): Subject
    {
        $lecturer = User::factory()->lecturer()->create(['name' => $lecturerName]);

        return Subject::create(['lecturer_id' => $lecturer->id, 'code' => $code, 'name' => $name, 'group_size' => 4]);
    }

    protected function create(array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin)->post(route('admin.users.store'), [
            'name' => 'Aina Student', 'email' => 'aina@student.test', 'role' => 'student', 'matric_no' => 'A21EC0001',
            ...$extra,
        ]);
    }

    protected function save(User $student, array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin)->put(route('admin.users.update', $student), [
            'name' => $student->name, 'email' => $student->email, 'role' => 'student', 'matric_no' => $student->matric_no,
            'enrolments_form' => 1,
            ...$extra,
        ]);
    }

    protected function student(): User
    {
        return User::factory()->student()->create(['matric_no' => 'A21EC0099']);
    }

    protected function rows(User $student): array
    {
        return $student->enrolments()->with(['subject', 'section'])->get()
            ->mapWithKeys(fn ($e) => [$e->subject->code => $e->section->name])->sortKeys()->all();
    }

    public function test_the_forms_carry_the_block_with_subjects_labelled_by_lecturer(): void
    {
        $twin = $this->subject('Dr. Other', 'BCS3323', 'Software Engineering');

        foreach ([route('admin.users.create'), route('admin.users.edit', $this->student())] as $url) {
            $html = $this->actingAs($this->admin)->get($url)->assertOk()->getContent();

            $this->assertStringContainsString('data-enrolments', $html);
            $this->assertStringContainsString('Subjects and sections', $html);
            $this->assertStringContainsString('name="enrolments_form"', $html);
            $this->assertStringContainsString('BCS3323 - Software Engineering (Dr. Aminah)', $html);
            $this->assertStringContainsString('BCS3323 - Software Engineering (Dr. Other)', $html, 'Two lecturers, one code: told apart.');
            $this->assertStringContainsString('SECJ3104 - Application Development (Dr. Zul)', $html);
            $this->assertStringContainsString('value="'.$twin->id.'"', $html);
        }
    }

    public function test_a_student_is_created_with_two_subjects_in_different_sections(): void
    {
        $this->create(['enrolments_form' => 1, 'enrolments' => [
            ['subject_id' => $this->one->id, 'section' => '1A'],
            ['subject_id' => $this->two->id, 'section' => '  1B '],
        ]])->assertRedirect(route('admin.users'))->assertSessionHasNoErrors();

        $student = User::where('email', 'aina@student.test')->sole();

        $this->assertSame(['BCS3323' => '1A', 'SECJ3104' => '1B'], $this->rows($student));
        $this->assertSame(1, Section::where('subject_id', $this->one->id)->count());
    }

    public function test_a_new_section_name_creates_the_section_and_an_existing_one_is_reused_whatever_its_capitals(): void
    {
        Section::create(['subject_id' => $this->one->id, 'name' => '1A']);

        $this->create(['enrolments_form' => 1, 'enrolments' => [['subject_id' => $this->one->id, 'section' => '1a']]]);
        $student = User::where('email', 'aina@student.test')->sole();

        $this->assertSame(['BCS3323' => '1A'], $this->rows($student));
        $this->assertSame(1, Section::where('subject_id', $this->one->id)->count(), 'No second "1a".');
    }

    public function test_a_student_can_be_created_with_no_enrolment_at_all(): void
    {
        $this->create()->assertSessionHasNoErrors();

        $this->assertSame(0, Enrolment::count());
    }

    public function test_editing_syncs_adds_changes_and_removes(): void
    {
        $student = $this->student();
        $three = $this->subject('Dr. Third', 'XYZ9999', 'Third');
        $this->save($student, ['enrolments' => [
            ['subject_id' => $this->one->id, 'section' => '1A'],
            ['subject_id' => $this->two->id, 'section' => '1A'],
        ]])->assertSessionHasNoErrors();
        $this->assertSame(['BCS3323' => '1A', 'SECJ3104' => '1A'], $this->rows($student));

        // change one section, drop one subject, add another
        $this->save($student, ['enrolments' => [
            ['subject_id' => $this->one->id, 'section' => '1B'],
            ['subject_id' => $three->id, 'section' => '2A'],
        ]])->assertSessionHasNoErrors();

        $this->assertSame(['BCS3323' => '1B', 'XYZ9999' => '2A'], $this->rows($student));
        $this->assertSame(2, $student->enrolments()->count());
    }

    public function test_saving_the_form_with_every_row_removed_unenrols_the_student(): void
    {
        $student = $this->student();
        $this->save($student, ['enrolments' => [['subject_id' => $this->one->id, 'section' => '1A']]]);

        $this->save($student)->assertSessionHasNoErrors();

        $this->assertSame(0, $student->enrolments()->count());
    }

    public function test_a_save_without_the_enrolment_block_leaves_enrolments_alone(): void
    {
        $student = $this->student();
        $this->save($student, ['enrolments' => [['subject_id' => $this->one->id, 'section' => '1A']]]);

        $this->actingAs($this->admin)->put(route('admin.users.update', $student), [
            'name' => 'Renamed', 'email' => $student->email, 'role' => 'student', 'matric_no' => $student->matric_no,
        ])->assertSessionHasNoErrors();

        $this->assertSame('Renamed', $student->fresh()->name);
        $this->assertSame(['BCS3323' => '1A'], $this->rows($student));
    }

    public function test_validation(): void
    {
        $dup = $this->create(['enrolments_form' => 1, 'enrolments' => [
            ['subject_id' => $this->one->id, 'section' => '1A'],
            ['subject_id' => $this->one->id, 'section' => '1B'],
        ]]);
        $dup->assertSessionHasErrors('enrolments.1.subject_id');

        $this->create(['enrolments_form' => 1, 'enrolments' => [['subject_id' => 999999, 'section' => '1A']]])
            ->assertSessionHasErrors('enrolments.0.subject_id');

        $this->create(['enrolments_form' => 1, 'enrolments' => [['subject_id' => $this->one->id, 'section' => str_repeat('x', 21)]]])
            ->assertSessionHasErrors('enrolments.0.section');

        $this->create(['enrolments_form' => 1, 'enrolments' => [['subject_id' => $this->one->id, 'section' => '   ']]])
            ->assertSessionHasErrors();

        $this->create(['enrolments_form' => 1, 'enrolments' => [['subject_id' => '', 'section' => '1A']]])
            ->assertSessionHasErrors('enrolments.0.subject_id');

        $this->assertSame(0, User::where('email', 'aina@student.test')->count());
        $this->assertSame(0, Enrolment::count());
    }

    public function test_a_completely_empty_row_is_ignored(): void
    {
        $this->create(['enrolments_form' => 1, 'enrolments' => [['subject_id' => '', 'section' => '']]])->assertSessionHasNoErrors();

        $this->assertSame(0, Enrolment::count());
    }

    public function test_lecturers_and_administrators_get_no_enrolments(): void
    {
        foreach (['lecturer', 'admin'] as $i => $role) {
            $this->actingAs($this->admin)->post(route('admin.users.store'), [
                'name' => "Staff {$i}", 'email' => "staff{$i}@x.test", 'role' => $role,
                'enrolments_form' => 1, 'enrolments' => [['subject_id' => $this->one->id, 'section' => '1A']],
            ])->assertSessionHasNoErrors();
        }

        $this->assertSame(0, Enrolment::count());
    }

    public function test_removing_a_student_from_a_subject_is_blocked_while_they_are_in_a_group_of_it(): void
    {
        $student = $this->student();
        $this->save($student, ['enrolments' => [['subject_id' => $this->one->id, 'section' => '1A']]]);
        $group = Group::create(['subject_id' => $this->one->id, 'section_id' => Section::first()->id, 'name' => 'Team Alpha', 'group_size' => 4]);
        GroupMember::create(['group_id' => $group->id, 'user_id' => $student->id, 'joined_at' => now()]);

        $response = $this->save($student, ['name' => 'Changed Name', 'enrolments' => []]);

        $response->assertSessionHasErrors('enrolments');
        $this->assertStringContainsString('Remove them from that group', session('errors')->first('enrolments'));
        $this->assertSame(['BCS3323' => '1A'], $this->rows($student), 'Nothing was removed.');
        $this->assertNotSame('Changed Name', $student->fresh()->name, 'Nothing else was saved either.');
    }

    public function test_changing_the_section_is_blocked_while_they_are_in_a_group_of_that_subject(): void
    {
        $student = $this->student();
        $this->save($student, ['enrolments' => [['subject_id' => $this->one->id, 'section' => '1A']]]);
        $group = Group::create(['subject_id' => $this->one->id, 'section_id' => Section::first()->id, 'name' => 'Team Alpha', 'group_size' => 4]);
        GroupMember::create(['group_id' => $group->id, 'user_id' => $student->id, 'joined_at' => now()]);

        $this->save($student, ['enrolments' => [['subject_id' => $this->one->id, 'section' => '1B']]])
            ->assertSessionHasErrors('enrolments');

        $this->assertSame(['BCS3323' => '1A'], $this->rows($student));
        $this->assertSame(1, Section::where('subject_id', $this->one->id)->count(), 'The rolled-back save created no section.');

        // The same section, or another subject, is fine.
        $this->save($student, ['enrolments' => [
            ['subject_id' => $this->one->id, 'section' => '1a'],
            ['subject_id' => $this->two->id, 'section' => '2B'],
        ]])->assertSessionHasNoErrors();
        $this->assertSame(['BCS3323' => '1A', 'SECJ3104' => '2B'], $this->rows($student));
    }

    public function test_the_users_list_shows_enrolments_as_chips_and_filters_by_subject(): void
    {
        $in = User::factory()->student()->create(['name' => 'Enrolled Erin', 'matric_no' => 'A21EC0010']);
        $out = User::factory()->student()->create(['name' => 'Outside Omar', 'matric_no' => 'A21EC0011']);
        $this->save($in, ['enrolments' => [['subject_id' => $this->one->id, 'section' => '1A'], ['subject_id' => $this->two->id, 'section' => '1B']]]);

        $html = $this->actingAs($this->admin)->get(route('admin.users'))->assertOk()->getContent();
        $this->assertStringContainsString('BCS3323 · Section 1A', $html);
        $this->assertStringContainsString('SECJ3104 · Section 1B', $html);
        $this->assertStringContainsString('Search name, email or matric no.', $html);

        $filtered = $this->actingAs($this->admin)->get(route('admin.users', ['subject' => $this->two->id]))->assertOk();
        $filtered->assertSee('Enrolled Erin')->assertDontSee('Outside Omar');
    }

    public function test_only_administrators_can_enrol(): void
    {
        $lecturer = User::factory()->lecturer()->create(['must_change_password' => false]);
        $student = $this->student();

        $this->actingAs($lecturer)->put(route('admin.users.update', $student), [
            'name' => $student->name, 'email' => $student->email, 'role' => 'student', 'matric_no' => $student->matric_no,
            'enrolments_form' => 1, 'enrolments' => [['subject_id' => $this->one->id, 'section' => '1A']],
        ])->assertForbidden();

        $this->assertSame(0, Enrolment::count());
    }
}
