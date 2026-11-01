<?php

namespace Tests\Feature\Lecturer;

use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Subject;
use App\Support\Sections\Enrolments;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubjectAndGroupTest extends TestCase
{
    use RefreshDatabase;

    protected User $lecturer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lecturer = User::factory()->lecturer()->create(['must_change_password' => false]);
    }

    /** Enrols a student the way the administrator does: in the subject, with a section. */
    protected function enrol(User $student, Subject $subject, string $section = '1A'): void
    {
        Enrolments::sync($student, $student->enrolments()->get()->map(fn ($e) => ['subject_id' => $e->subject_id, 'section' => $e->section->name])
            ->push(['subject_id' => $subject->id, 'section' => $section])->all());
    }

    protected function subject(array $overrides = []): Subject
    {
        return Subject::create([
            'lecturer_id' => $this->lecturer->id,
            'code' => 'SECJ3104',
            'name' => 'Application Development',
            'group_size' => 4,
            ...$overrides,
        ]);
    }

    public function test_a_group_can_be_deleted_and_cascades(): void
    {
        $subject = $this->subject();
        $group = Group::create(['subject_id' => $subject->id, 'name' => 'Group A', 'group_size' => 4]);
        $student = User::factory()->student()->create();
        GroupMember::create(['group_id' => $group->id, 'user_id' => $student->id, 'joined_at' => now()]);
        $task = Task::create([
            'group_id' => $group->id, 'created_by' => $this->lecturer->id,
            'title' => 'Doomed', 'status' => Task::STATUS_TODO,
        ]);

        $this->actingAs($this->lecturer)
            ->delete(route('lecturer.groups.destroy', $group))
            ->assertRedirect(route('lecturer.groups', ['subject' => $subject->id]));

        $this->assertDatabaseMissing('groups', ['id' => $group->id]);
        $this->assertDatabaseMissing('tasks', ['id' => $task->id]);
        $this->assertDatabaseMissing('group_members', ['group_id' => $group->id]);
        // The subject and the student survive.
        $this->assertDatabaseHas('subjects', ['id' => $subject->id]);
        $this->assertDatabaseHas('users', ['id' => $student->id]);
    }

    public function test_the_group_list_warns_about_the_cascade(): void
    {
        $subject = $this->subject();
        $group = Group::create(['subject_id' => $subject->id, 'name' => 'Group A', 'group_size' => 4]);
        Task::create([
            'group_id' => $group->id, 'created_by' => $this->lecturer->id,
            'title' => 'Doomed', 'status' => Task::STATUS_TODO,
        ]);

        $this->actingAs($this->lecturer)
            ->get(route('lecturer.groups'))
            ->assertOk()
            ->assertSee('Delete this group?', escape: false)
            ->assertSee('consequences', escape: false);
    }

    /*
    | Subjects
    */

    public function test_a_lecturer_can_create_a_subject(): void
    {
        $this->actingAs($this->lecturer)
            ->post(route('lecturer.subjects.store'), [
                'code' => 'secv2113',
                'name' => 'Human Computer Interaction',
                'group_size' => 3,
            ])
            ->assertRedirect(route('lecturer.subjects'));

        $this->assertDatabaseHas('subjects', [
            'lecturer_id' => $this->lecturer->id,
            'code' => 'SECV2113',   // upper-cased on the way in
            'group_size' => 3,
        ]);
    }

    public function test_a_lecturer_cannot_reuse_their_own_subject_code(): void
    {
        $this->subject();

        $this->actingAs($this->lecturer)
            ->post(route('lecturer.subjects.store'), [
                'code' => 'SECJ3104', 'name' => 'Duplicate', 'group_size' => 4,
            ])
            ->assertSessionHasErrors('code');
    }

    public function test_two_lecturers_may_each_use_the_same_code(): void
    {
        $this->subject();

        $other = User::factory()->lecturer()->create(['must_change_password' => false]);

        $this->actingAs($other)
            ->post(route('lecturer.subjects.store'), [
                'code' => 'SECJ3104', 'name' => 'Their section', 'group_size' => 4,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, Subject::where('code', 'SECJ3104')->count());
    }

    public function test_group_size_is_bounded(): void
    {
        foreach ([1, 11] as $size) {
            $this->actingAs($this->lecturer)
                ->post(route('lecturer.subjects.store'), [
                    'code' => 'X'.$size, 'name' => 'Bad size', 'group_size' => $size,
                ])
                ->assertSessionHasErrors('group_size');
        }
    }

    public function test_deleting_a_subject_cascades_to_its_groups(): void
    {
        $subject = $this->subject();
        $group = Group::create(['subject_id' => $subject->id, 'name' => 'G1', 'group_size' => 4]);

        $this->actingAs($this->lecturer)
            ->delete(route('lecturer.subjects.destroy', $subject))
            ->assertRedirect(route('lecturer.subjects'));

        $this->assertDatabaseMissing('subjects', ['id' => $subject->id]);
        $this->assertDatabaseMissing('groups', ['id' => $group->id]);
    }

    public function test_the_subject_list_warns_about_the_cascade(): void
    {
        $subject = $this->subject();
        Group::create(['subject_id' => $subject->id, 'name' => 'G1', 'group_size' => 4]);

        $this->actingAs($this->lecturer)
            ->get(route('lecturer.subjects'))
            ->assertOk()
            // The confirm payload carries the cascade detail.
            ->assertSee('consequences', escape: false)
            ->assertSee('Delete this subject?', escape: false);
    }

    /*
    | Groups
    */

    public function test_a_group_is_created_with_the_subject_default_size(): void
    {
        $subject = $this->subject(['group_size' => 5]);

        $this->actingAs($this->lecturer)->post(route('lecturer.groups.store'), [
            'subject_id' => $subject->id,
            'name' => 'Group A',
            'group_size' => 5,
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('groups', ['name' => 'Group A', 'group_size' => 5]);
    }

    public function test_group_names_are_unique_within_a_subject(): void
    {
        $subject = $this->subject();
        Group::create(['subject_id' => $subject->id, 'name' => 'Group A', 'group_size' => 4]);

        $this->actingAs($this->lecturer)
            ->post(route('lecturer.groups.store'), [
                'subject_id' => $subject->id, 'name' => 'Group A', 'group_size' => 4,
            ])
            ->assertSessionHasErrors('name');
    }

    /*
    | Assigning students
    */

    public function test_a_student_can_be_assigned_to_a_group(): void
    {
        $subject = $this->subject();
        $group = Group::create(['subject_id' => $subject->id, 'name' => 'G1', 'group_size' => 4]);
        $student = User::factory()->student()->create();
        $this->enrol($student, $subject);

        $this->actingAs($this->lecturer)
            ->post(route('lecturer.groups.members.store', $group), ['user_id' => $student->id])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('group_members', ['group_id' => $group->id, 'user_id' => $student->id]);
    }

    public function test_the_same_student_cannot_be_assigned_twice(): void
    {
        $subject = $this->subject();
        $group = Group::create(['subject_id' => $subject->id, 'name' => 'G1', 'group_size' => 4]);
        $student = User::factory()->student()->create();
        GroupMember::create(['group_id' => $group->id, 'user_id' => $student->id, 'joined_at' => now()]);

        $this->actingAs($this->lecturer)
            ->post(route('lecturer.groups.members.store', $group), ['user_id' => $student->id])
            ->assertSessionHasErrors('user_id');

        $this->assertSame(1, GroupMember::where('group_id', $group->id)->count());
    }

    public function test_a_student_cannot_be_in_two_groups_of_the_same_subject(): void
    {
        $subject = $this->subject();
        $one = Group::create(['subject_id' => $subject->id, 'name' => 'G1', 'group_size' => 4]);
        $two = Group::create(['subject_id' => $subject->id, 'name' => 'G2', 'group_size' => 4]);
        $student = User::factory()->student()->create();
        GroupMember::create(['group_id' => $one->id, 'user_id' => $student->id, 'joined_at' => now()]);

        $this->actingAs($this->lecturer)
            ->post(route('lecturer.groups.members.store', $two), ['user_id' => $student->id])
            ->assertSessionHasErrors('user_id');

        $this->assertDatabaseMissing('group_members', ['group_id' => $two->id, 'user_id' => $student->id]);
    }

    public function test_a_student_may_be_in_groups_of_different_subjects(): void
    {
        $first = $this->subject();
        $second = $this->subject(['code' => 'SECV2113', 'name' => 'HCI']);
        $groupOne = Group::create(['subject_id' => $first->id, 'name' => 'G1', 'group_size' => 4]);
        $groupTwo = Group::create(['subject_id' => $second->id, 'name' => 'G1', 'group_size' => 4]);
        $student = User::factory()->student()->create();
        $this->enrol($student, $first);
        $this->enrol($student, $second);
        GroupMember::create(['group_id' => $groupOne->id, 'user_id' => $student->id, 'joined_at' => now()]);

        $this->actingAs($this->lecturer)
            ->post(route('lecturer.groups.members.store', $groupTwo), ['user_id' => $student->id])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('group_members', ['group_id' => $groupTwo->id, 'user_id' => $student->id]);
    }

    public function test_the_group_size_limit_is_enforced(): void
    {
        $subject = $this->subject();
        $group = Group::create(['subject_id' => $subject->id, 'name' => 'G1', 'group_size' => 2]);

        foreach (User::factory()->count(2)->student()->create() as $filler) {
            GroupMember::create(['group_id' => $group->id, 'user_id' => $filler->id, 'joined_at' => now()]);
        }

        $overflow = User::factory()->student()->create();
        $this->enrol($overflow, $subject);

        $this->actingAs($this->lecturer)
            ->post(route('lecturer.groups.members.store', $group), ['user_id' => $overflow->id])
            ->assertSessionHasErrors('user_id');

        $this->assertSame(2, GroupMember::where('group_id', $group->id)->count());
    }

    public function test_a_lecturer_cannot_be_assigned_as_a_group_member(): void
    {
        $subject = $this->subject();
        $group = Group::create(['subject_id' => $subject->id, 'name' => 'G1', 'group_size' => 4]);
        $notAStudent = User::factory()->lecturer()->create();

        $this->actingAs($this->lecturer)
            ->post(route('lecturer.groups.members.store', $group), ['user_id' => $notAStudent->id])
            ->assertSessionHasErrors('user_id');
    }

    public function test_a_deactivated_student_cannot_be_assigned(): void
    {
        $subject = $this->subject();
        $group = Group::create(['subject_id' => $subject->id, 'name' => 'G1', 'group_size' => 4]);
        $inactive = User::factory()->student()->deactivated()->create();

        $this->actingAs($this->lecturer)
            ->post(route('lecturer.groups.members.store', $group), ['user_id' => $inactive->id])
            ->assertSessionHasErrors('user_id');
    }

    public function test_the_dropdown_excludes_students_already_grouped_in_that_subject(): void
    {
        $subject = $this->subject();
        $one = Group::create(['subject_id' => $subject->id, 'name' => 'G1', 'group_size' => 4]);
        $two = Group::create(['subject_id' => $subject->id, 'name' => 'G2', 'group_size' => 4]);

        $taken = User::factory()->student()->create(['name' => 'Already Grouped']);
        $free = User::factory()->student()->create(['name' => 'Still Free']);
        $this->enrol($taken, $subject);
        $this->enrol($free, $subject);
        GroupMember::create(['group_id' => $one->id, 'user_id' => $taken->id, 'joined_at' => now()]);

        $this->actingAs($this->lecturer)
            ->get(route('lecturer.groups.show', $two))
            ->assertOk()
            ->assertSee('Still Free')
            ->assertDontSee('Already Grouped');
    }

    public function test_a_student_can_be_removed(): void
    {
        $subject = $this->subject();
        $group = Group::create(['subject_id' => $subject->id, 'name' => 'G1', 'group_size' => 4]);
        $student = User::factory()->student()->create();
        GroupMember::create(['group_id' => $group->id, 'user_id' => $student->id, 'joined_at' => now()]);

        $this->actingAs($this->lecturer)
            ->delete(route('lecturer.groups.members.destroy', [$group, $student]))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('group_members', ['group_id' => $group->id, 'user_id' => $student->id]);
    }

    public function test_removing_a_non_member_is_a_404(): void
    {
        $subject = $this->subject();
        $group = Group::create(['subject_id' => $subject->id, 'name' => 'G1', 'group_size' => 4]);
        $stranger = User::factory()->student()->create();

        $this->actingAs($this->lecturer)
            ->delete(route('lecturer.groups.members.destroy', [$group, $stranger]))
            ->assertNotFound();
    }

    /*
    | Dashboard figures
    */

    public function test_the_dashboard_totals_reflect_the_lecturers_own_data(): void
    {
        $subject = $this->subject();
        $group = Group::create(['subject_id' => $subject->id, 'name' => 'G1', 'group_size' => 4]);
        $student = User::factory()->student()->create();
        GroupMember::create(['group_id' => $group->id, 'user_id' => $student->id, 'joined_at' => now()]);

        Task::create([
            'group_id' => $group->id, 'created_by' => $this->lecturer->id, 'assigned_to' => $student->id,
            'title' => 'Done one', 'status' => Task::STATUS_DONE, 'completed_at' => now()->subDay(),
        ]);
        Task::create([
            'group_id' => $group->id, 'created_by' => $this->lecturer->id, 'assigned_to' => $student->id,
            'title' => 'Late one', 'status' => Task::STATUS_TODO, 'deadline' => now()->subWeek()->toDateString(),
        ]);

        $this->actingAs($this->lecturer)
            ->get(route('lecturer.dashboard'))
            ->assertOk()
            ->assertSee('Needs attention')
            ->assertSee('Awaiting marking')
            ->assertSee('Groups')
            ->assertSee('Students')
            ->assertSee('Groups needing attention')
            ->assertSee('Recent activity')
            ->assertSee('G1');
    }
}
