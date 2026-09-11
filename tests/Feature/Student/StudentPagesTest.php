<?php

namespace Tests\Feature\Student;

use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Message;
use App\Models\Subject;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentPagesTest extends TestCase
{
    use RefreshDatabase;

    protected User $me;

    protected Group $group;

    protected Group $otherGroup;

    protected function setUp(): void
    {
        parent::setUp();

        $lecturer = User::factory()->lecturer()->create();
        $subject = Subject::create([
            'lecturer_id' => $lecturer->id, 'code' => 'SECJ3104', 'name' => 'App Dev', 'group_size' => 4,
        ]);

        $this->group = Group::create(['subject_id' => $subject->id, 'name' => 'My Group', 'group_size' => 4]);
        $this->otherGroup = Group::create(['subject_id' => $subject->id, 'name' => 'Other Group', 'group_size' => 4]);

        $this->me = User::factory()->student()->create(['must_change_password' => false]);
        GroupMember::create(['group_id' => $this->group->id, 'user_id' => $this->me->id, 'joined_at' => now()]);
    }

    /*
    | The no-group case — a student exists before a lecturer assigns them.
    */

    public function test_a_student_with_no_group_gets_an_empty_state_not_a_broken_page(): void
    {
        $orphan = User::factory()->student()->create(['must_change_password' => false]);

        $this->actingAs($orphan)
            ->get(route('student.dashboard'))
            ->assertOk()
            ->assertSee('You are not in a group yet')
            ->assertDontSee('My pending tasks');
    }

    public function test_the_workspace_handles_a_student_with_no_group(): void
    {
        $orphan = User::factory()->student()->create(['must_change_password' => false]);

        $this->actingAs($orphan)
            ->get(route('student.workspace'))
            ->assertOk()
            ->assertSee('You are not in a group yet');
    }

    public function test_the_board_handles_a_student_with_no_group(): void
    {
        $orphan = User::factory()->student()->create(['must_change_password' => false]);

        $this->actingAs($orphan)
            ->get(route('student.board'))
            ->assertOk()
            ->assertSee('You are not in a group yet');
    }

    /*
    | Dashboard
    */

    public function test_the_dashboard_shows_my_group_and_my_pending_work(): void
    {
        Task::create([
            'group_id' => $this->group->id, 'created_by' => $this->me->id, 'assigned_to' => $this->me->id,
            'title' => 'Mine and pending', 'status' => Task::STATUS_TODO,
            'deadline' => now()->subWeek()->toDateString(),
        ]);
        Task::create([
            'group_id' => $this->group->id, 'created_by' => $this->me->id, 'assigned_to' => null,
            'title' => 'Nobody task', 'status' => Task::STATUS_TODO,
        ]);

        Message::create([
            'group_id' => $this->group->id, 'user_id' => $this->me->id, 'body' => 'Hello group',
        ]);

        $this->actingAs($this->me)
            ->get(route('student.dashboard'))
            ->assertOk()
            ->assertSee('My Group')
            ->assertSee('SECJ3104')
            ->assertSee('App Dev')
            ->assertSee('Mine and pending')
            ->assertSee('Overdue')
            ->assertSee('Hello group')
            // Only work assigned to me appears in my pending list.
            ->assertDontSee('Nobody task');
    }

    public function test_completed_work_is_counted_separately(): void
    {
        Task::create([
            'group_id' => $this->group->id, 'created_by' => $this->me->id, 'assigned_to' => $this->me->id,
            'title' => 'Finished', 'status' => Task::STATUS_DONE, 'completed_at' => now(),
        ]);

        $this->actingAs($this->me)
            ->get(route('student.dashboard'))
            ->assertOk()
            ->assertSee('Completed by me');
    }

    /*
    | Workspace
    */

    public function test_the_workspace_shows_members_progress_and_activity(): void
    {
        $mate = User::factory()->student()->create(['name' => 'Group Mate']);
        GroupMember::create(['group_id' => $this->group->id, 'user_id' => $mate->id, 'joined_at' => now()]);

        $this->actingAs($this->me)
            ->get(route('student.workspace.show', $this->group))
            ->assertOk()
            ->assertSee('Group members')
            ->assertSee('Group Mate')
            ->assertSee('Recent activity')
            ->assertSee('Task progress');
    }

    public function test_the_bare_workspace_url_redirects_to_my_group(): void
    {
        $this->actingAs($this->me)
            ->get(route('student.workspace'))
            ->assertRedirect(route('student.workspace.show', $this->group));
    }

    public function test_the_bare_board_url_redirects_to_my_group(): void
    {
        $this->actingAs($this->me)
            ->get(route('student.board'))
            ->assertRedirect(route('student.board.show', $this->group));
    }

    /*
    | Scoping
    */

    public function test_another_groups_workspace_is_a_404(): void
    {
        $this->actingAs($this->me)
            ->get(route('student.workspace.show', $this->otherGroup))
            ->assertNotFound();
    }

    public function test_another_groups_board_is_a_404(): void
    {
        $this->actingAs($this->me)
            ->get(route('student.board.show', $this->otherGroup))
            ->assertNotFound();
    }

    public function test_the_student_area_is_closed_to_lecturers_and_admins(): void
    {
        $lecturer = User::factory()->lecturer()->create(['must_change_password' => false]);
        $admin = User::factory()->admin()->create(['must_change_password' => false]);

        foreach ([$lecturer, $admin] as $user) {
            $this->actingAs($user)->get(route('student.dashboard'))->assertForbidden();
            $this->actingAs($user)->get(route('student.workspace'))->assertForbidden();
        }
    }

    public function test_a_student_lands_on_the_student_dashboard(): void
    {
        $this->actingAs($this->me)
            ->get('/dashboard')
            ->assertRedirect(route('student.dashboard'));
    }
}
