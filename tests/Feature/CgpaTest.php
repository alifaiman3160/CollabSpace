<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * A student's CGPA: typed in by the administrator, shown to lecturers, and
 * nobody else.
 *
 * It is personal academic data, and it is entered by hand rather than read from
 * any academic system. The tests cover both halves of that: that what is typed
 * is validated and stored faithfully, and that it never reaches a screen it was
 * not meant for.
 */
class CgpaTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->admin = User::factory()->admin()->create(['must_change_password' => false]);
    }

    protected function create(array $overrides = [])
    {
        return $this->actingAs($this->admin)->post(route('admin.users.store'), $overrides + [
            'name' => 'Nurul Izzah',
            'email' => 'nurul@student.collabspace.test',
            'role' => 'student',
            'matric_no' => 'A21EC0024',
        ]);
    }

    protected function stored(): ?string
    {
        return User::where('email', 'nurul@student.collabspace.test')->first()?->cgpa;
    }

    /*
    | Entering it
    */

    public function test_the_administrator_can_enter_a_cgpa_when_creating_a_student(): void
    {
        $this->create(['cgpa' => '3.47'])->assertSessionHasNoErrors();

        $this->assertSame('3.47', $this->stored());
    }

    public function test_a_comma_decimal_is_accepted(): void
    {
        // Typed that way in many keyboard layouts.
        $this->create(['cgpa' => '3,47'])->assertSessionHasNoErrors();

        $this->assertSame('3.47', $this->stored());
    }

    public function test_zero_is_a_cgpa_not_a_blank(): void
    {
        $this->create(['cgpa' => '0'])->assertSessionHasNoErrors();

        $this->assertSame('0.00', $this->stored());
    }

    public function test_a_blank_is_none_not_zero(): void
    {
        // Stored as 0.00 it would read as the lowest possible ability, which is
        // exactly wrong for "not entered".
        $this->create(['cgpa' => ''])->assertSessionHasNoErrors();

        $this->assertNull($this->stored());
    }

    public function test_leaving_it_out_entirely_is_fine(): void
    {
        $this->create()->assertSessionHasNoErrors();

        $this->assertNull($this->stored());
    }

    public function test_a_value_above_four_is_refused(): void
    {
        $this->create(['cgpa' => '4.50'])->assertSessionHasErrors('cgpa');

        $this->assertNull(User::where('email', 'nurul@student.collabspace.test')->first());
    }

    public function test_a_negative_value_is_refused(): void
    {
        $this->create(['cgpa' => '-1'])->assertSessionHasErrors('cgpa');
    }

    public function test_more_than_two_decimal_places_is_refused(): void
    {
        $this->create(['cgpa' => '3.456'])->assertSessionHasErrors('cgpa');
    }

    public function test_something_that_is_not_a_number_is_refused(): void
    {
        $this->create(['cgpa' => 'first class'])->assertSessionHasErrors('cgpa');
    }

    public function test_the_boundaries_themselves_are_allowed(): void
    {
        $this->create(['cgpa' => '4.00'])->assertSessionHasNoErrors();
        $this->assertSame('4.00', $this->stored());
    }

    public function test_only_students_carry_a_cgpa(): void
    {
        $this->create(['role' => 'lecturer', 'matric_no' => '', 'cgpa' => '3.90', 'email' => 'dr@collabspace.test'])
            ->assertSessionHasNoErrors();

        $this->assertNull(User::where('email', 'dr@collabspace.test')->first()->cgpa);
    }

    /*
    | Editing it
    */

    public function test_it_can_be_corrected_later(): void
    {
        $student = User::factory()->student()->create(['cgpa' => 2.50]);

        $this->actingAs($this->admin)->put(route('admin.users.update', $student), [
            'name' => $student->name, 'email' => $student->email, 'role' => 'student',
            'matric_no' => $student->matric_no, 'cgpa' => '3.10',
        ])->assertSessionHasNoErrors();

        $this->assertSame('3.10', $student->fresh()->cgpa);
    }

    public function test_it_can_be_cleared(): void
    {
        $student = User::factory()->student()->create(['cgpa' => 2.50]);

        $this->actingAs($this->admin)->put(route('admin.users.update', $student), [
            'name' => $student->name, 'email' => $student->email, 'role' => 'student',
            'matric_no' => $student->matric_no, 'cgpa' => '',
        ]);

        $this->assertNull($student->fresh()->cgpa);
    }

    public function test_promoting_a_student_drops_their_cgpa(): void
    {
        $student = User::factory()->student()->create(['cgpa' => 3.20]);

        $this->actingAs($this->admin)->put(route('admin.users.update', $student), [
            'name' => $student->name, 'email' => $student->email, 'role' => 'lecturer',
            'matric_no' => '', 'cgpa' => '3.20',
        ]);

        $this->assertNull($student->fresh()->cgpa);
    }

    public function test_the_forms_carry_the_field_and_say_it_is_entered_by_hand(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.users.create'))
            ->assertOk()
            ->assertSee('CGPA (optional)')
            ->assertSee('Entered by hand')
            ->assertSee('does not read this from any academic system')
            ->assertSee('students')   // "students never do"
            ->assertSee('never do');

        $student = User::factory()->student()->create(['cgpa' => 3.47]);

        $this->actingAs($this->admin)
            ->get(route('admin.users.edit', $student))
            ->assertOk()
            ->assertSee('value="3.47"', false);
    }

    /*
    | What a lecturer sees
    */

    protected function lecturerWithStudents(): array
    {
        $lecturer = User::factory()->lecturer()->create(['must_change_password' => false]);

        $subject = Subject::create(['lecturer_id' => $lecturer->id, 'code' => 'SECJ3104', 'name' => 'App Dev', 'group_size' => 4]);
        $group = Group::create(['subject_id' => $subject->id, 'name' => 'Group A', 'group_size' => 4]);

        $with = User::factory()->student()->create(['name' => 'Has Cgpa', 'cgpa' => 3.73, 'must_change_password' => false]);
        $without = User::factory()->student()->create(['name' => 'No Cgpa', 'cgpa' => null, 'must_change_password' => false]);

        foreach ([$with, $without] as $student) {
            GroupMember::create(['group_id' => $group->id, 'user_id' => $student->id, 'joined_at' => now()]);
        }

        return [$lecturer, $with, $without];
    }

    public function test_the_lecturer_sees_cgpa_on_the_students_screen(): void
    {
        [$lecturer] = $this->lecturerWithStudents();

        $this->actingAs($lecturer)
            ->get(route('lecturer.students'))
            ->assertOk()
            ->assertSee('CGPA')
            ->assertSee('3.73');
    }

    public function test_a_missing_cgpa_reads_as_a_dash_never_as_zero(): void
    {
        [$lecturer] = $this->lecturerWithStudents();

        $this->actingAs($lecturer)
            ->get(route('lecturer.students'))
            ->assertOk()
            ->assertSee('aria-label="No CGPA recorded"', false)
            ->assertDontSee('Not recorded')
            ->assertDontSee('0.00');
    }

    public function test_the_students_screen_says_where_the_figure_comes_from(): void
    {
        [$lecturer] = $this->lecturerWithStudents();

        $this->actingAs($lecturer)
            ->get(route('lecturer.students'))
            ->assertOk()
            ->assertSee('entered by hand by the administrator')
            ->assertSee('not connected to any academic');
    }

    /*
    | Where it must not appear
    */

    public function test_a_student_never_sees_anyones_cgpa(): void
    {
        [, $with, $without] = $this->lecturerWithStudents();

        $group = Group::first();

        foreach ([
            route('student.dashboard'),
            route('student.workspace.show', $group),
            route('student.board.show', $group),
            route('student.chat.show', $group),
            route('student.documents.group', $group),
        ] as $url) {
            // Both as the student it belongs to and as the one who shares a group.
            $this->actingAs($with)->get($url)->assertOk()->assertDontSee('3.73')->assertDontSee('CGPA');
            $this->actingAs($without)->get($url)->assertOk()->assertDontSee('3.73')->assertDontSee('CGPA');
        }
    }

    public function test_it_is_left_out_of_serialisation(): void
    {
        $student = User::factory()->student()->create(['cgpa' => 3.73]);

        $this->assertArrayNotHasKey('cgpa', $student->toArray());
        $this->assertStringNotContainsString('3.73', $student->toJson());

        // Still readable on purpose, by the code that is allowed to.
        $this->assertSame('3.73', $student->cgpa);
    }

    public function test_it_is_not_on_the_accounts_own_page(): void
    {
        $student = User::factory()->student()->create(['cgpa' => 3.73, 'must_change_password' => false]);

        $this->actingAs($student)->get(route('account'))->assertOk()->assertDontSee('3.73');
    }

    public function test_a_lecturer_cannot_see_the_cgpa_of_someone_outside_their_subjects(): void
    {
        [$lecturer] = $this->lecturerWithStudents();

        $outsider = User::factory()->student()->create(['name' => 'Elsewhere', 'cgpa' => 3.99]);

        $this->actingAs($lecturer)
            ->get(route('lecturer.students'))
            ->assertOk()
            ->assertDontSee('Elsewhere')
            ->assertDontSee('3.99');
    }
}
