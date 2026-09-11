<?php

namespace Tests\Feature\Lecturer;

use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A lecturer must not be able to reach another lecturer's subject, group or
 * student by editing an id in the URL. Every route in the lecturer area is
 * probed here with a foreign id.
 */
class OwnershipScopingTest extends TestCase
{
    use RefreshDatabase;

    protected User $mine;

    protected User $theirs;

    protected Subject $mySubject;

    protected Subject $theirSubject;

    protected Group $myGroup;

    protected Group $theirGroup;

    protected User $theirStudent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mine = User::factory()->lecturer()->create(['must_change_password' => false]);
        $this->theirs = User::factory()->lecturer()->create(['must_change_password' => false]);

        $this->mySubject = Subject::create([
            'lecturer_id' => $this->mine->id, 'code' => 'MINE101', 'name' => 'Mine', 'group_size' => 4,
        ]);
        $this->theirSubject = Subject::create([
            'lecturer_id' => $this->theirs->id, 'code' => 'THEIRS101', 'name' => 'Theirs', 'group_size' => 4,
        ]);

        $this->myGroup = Group::create(['subject_id' => $this->mySubject->id, 'name' => 'My Group', 'group_size' => 4]);
        $this->theirGroup = Group::create(['subject_id' => $this->theirSubject->id, 'name' => 'Their Group', 'group_size' => 4]);

        $this->theirStudent = User::factory()->student()->create();
        GroupMember::create([
            'group_id' => $this->theirGroup->id,
            'user_id' => $this->theirStudent->id,
            'joined_at' => now(),
        ]);
    }

    /*
    | Subjects
    */

    public function test_another_lecturers_subject_cannot_be_opened(): void
    {
        $this->actingAs($this->mine)
            ->get(route('lecturer.subjects.edit', $this->theirSubject))
            ->assertNotFound();
    }

    public function test_another_lecturers_subject_cannot_be_updated(): void
    {
        $this->actingAs($this->mine)
            ->put(route('lecturer.subjects.update', $this->theirSubject), [
                'code' => 'HIJACK', 'name' => 'Hijacked', 'group_size' => 4,
            ])
            ->assertNotFound();

        $this->assertSame('THEIRS101', $this->theirSubject->fresh()->code);
    }

    public function test_another_lecturers_subject_cannot_be_deleted(): void
    {
        $this->actingAs($this->mine)
            ->delete(route('lecturer.subjects.destroy', $this->theirSubject))
            ->assertNotFound();

        $this->assertDatabaseHas('subjects', ['id' => $this->theirSubject->id]);
    }

    public function test_the_subject_list_shows_only_my_own(): void
    {
        $this->actingAs($this->mine)
            ->get(route('lecturer.subjects'))
            ->assertOk()
            ->assertSee('MINE101')
            ->assertDontSee('THEIRS101');
    }

    /*
    | Groups
    */

    public function test_another_lecturers_group_cannot_be_opened(): void
    {
        $this->actingAs($this->mine)
            ->get(route('lecturer.groups.show', $this->theirGroup))
            ->assertNotFound();
    }

    public function test_the_group_list_shows_only_my_own(): void
    {
        $this->actingAs($this->mine)
            ->get(route('lecturer.groups'))
            ->assertOk()
            ->assertSee('My Group')
            ->assertDontSee('Their Group');
    }

    public function test_filtering_by_another_lecturers_subject_is_refused(): void
    {
        $this->actingAs($this->mine)
            ->get(route('lecturer.groups', ['subject' => $this->theirSubject->id]))
            ->assertNotFound();
    }

    public function test_a_group_cannot_be_created_under_another_lecturers_subject(): void
    {
        $this->actingAs($this->mine)
            ->post(route('lecturer.groups.store'), [
                'subject_id' => $this->theirSubject->id,
                'name' => 'Sneaky Group',
                'group_size' => 4,
            ])
            ->assertSessionHasErrors('subject_id');

        $this->assertDatabaseMissing('groups', ['name' => 'Sneaky Group']);
    }

    public function test_the_group_create_screen_refuses_another_lecturers_subject(): void
    {
        $this->actingAs($this->mine)
            ->get(route('lecturer.groups.create', ['subject' => $this->theirSubject->id]))
            ->assertNotFound();
    }

    /*
    | Members
    */

    public function test_a_student_cannot_be_added_to_another_lecturers_group(): void
    {
        $student = User::factory()->student()->create();

        $this->actingAs($this->mine)
            ->post(route('lecturer.groups.members.store', $this->theirGroup), ['user_id' => $student->id])
            ->assertNotFound();

        $this->assertDatabaseMissing('group_members', [
            'group_id' => $this->theirGroup->id,
            'user_id' => $student->id,
        ]);
    }

    public function test_a_student_cannot_be_removed_from_another_lecturers_group(): void
    {
        $this->actingAs($this->mine)
            ->delete(route('lecturer.groups.members.destroy', [$this->theirGroup, $this->theirStudent]))
            ->assertNotFound();

        $this->assertDatabaseHas('group_members', [
            'group_id' => $this->theirGroup->id,
            'user_id' => $this->theirStudent->id,
        ]);
    }

    /*
    | Dashboard
    */

    public function test_the_dashboard_counts_only_my_own_cohort(): void
    {
        $this->actingAs($this->mine)
            ->get(route('lecturer.dashboard'))
            ->assertOk()
            ->assertSee('My Group')
            ->assertDontSee('Their Group')
            ->assertDontSee('THEIRS101');
    }

    /*
    | Role boundary
    */

    public function test_the_lecturer_area_is_closed_to_students_and_admins(): void
    {
        $student = User::factory()->create(['must_change_password' => false]);
        $admin = User::factory()->admin()->create(['must_change_password' => false]);

        foreach ([$student, $admin] as $user) {
            $this->actingAs($user)->get(route('lecturer.dashboard'))->assertForbidden();
            $this->actingAs($user)->get(route('lecturer.subjects'))->assertForbidden();
            $this->actingAs($user)->get(route('lecturer.groups'))->assertForbidden();
        }
    }

    public function test_a_lecturer_lands_on_the_lecturer_dashboard(): void
    {
        $this->actingAs($this->mine)
            ->get('/dashboard')
            ->assertRedirect(route('lecturer.dashboard'));
    }
}
