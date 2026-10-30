<?php

namespace Tests\Feature\Student;

use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Picking which group's screen you are looking at.
 *
 * A student can sit in a group for several subjects at once, and every
 * group-scoped screen needs the same control. The task board had none, so a
 * student in two subjects could reach only the board of whichever group sorted
 * first — the other one had no link anywhere in the interface.
 *
 * These run the same checks across all four screens, so a fifth cannot be added
 * without one.
 */
class GroupSwitcherTest extends TestCase
{
    use RefreshDatabase;

    protected User $student;

    protected Group $appDev;

    protected Group $networks;

    protected function setUp(): void
    {
        parent::setUp();

        $lecturer = User::factory()->lecturer()->create();

        $this->appDev = $this->groupFor($lecturer, 'SECJ3104', 'App Dev', 'Group A');
        $this->networks = $this->groupFor($lecturer, 'SECR1013', 'Networks', 'Group B');

        $this->student = User::factory()->student()->create(['must_change_password' => false]);

        foreach ([$this->appDev, $this->networks] as $group) {
            GroupMember::create([
                'group_id' => $group->id,
                'user_id' => $this->student->id,
                'joined_at' => now(),
            ]);
        }
    }

    protected function groupFor(User $lecturer, string $code, string $name, string $groupName): Group
    {
        $subject = Subject::create([
            'lecturer_id' => $lecturer->id,
            'code' => $code,
            'name' => $name,
            'group_size' => 4,
        ]);

        return Group::create(['subject_id' => $subject->id, 'name' => $groupName, 'group_size' => 4]);
    }

    /**
     * Every group-scoped student screen, and the route its switcher links to.
     *
     * @return array<string, string>
     */
    protected function screens(): array
    {
        return [
            'student.board.show' => 'student.board.show',
            'student.workspace.show' => 'student.workspace.show',
            'student.documents.group' => 'student.documents.group',
            'student.chat.show' => 'student.chat.show',
        ];
    }

    public function test_every_group_screen_offers_the_other_subject(): void
    {
        foreach ($this->screens() as $screen => $linksTo) {
            $response = $this->actingAs($this->student)
                ->get(route($screen, $this->appDev))
                ->assertOk();

            $response->assertSee('Your groups');

            // The subject you are not on must be reachable from the one you are.
            $response->assertSee(route($linksTo, $this->networks), false);
            $response->assertSee('SECR1013');
        }
    }

    public function test_the_switcher_marks_where_you_are(): void
    {
        foreach ($this->screens() as $screen => $linksTo) {
            $this->actingAs($this->student)
                ->get(route($screen, $this->networks))
                ->assertOk()
                ->assertSee('aria-current="page"', false)
                ->assertSee('SECJ3104');
        }
    }

    public function test_it_names_the_group_not_just_the_subject(): void
    {
        // Two groups of the same subject would otherwise be two identical tabs.
        $this->actingAs($this->student)
            ->get(route('student.board.show', $this->appDev))
            ->assertOk()
            ->assertSee('Group A')
            ->assertSee('Group B');
    }

    public function test_a_student_in_one_group_gets_no_dead_switcher(): void
    {
        // A control offering a single choice asks to be clicked and does
        // nothing, so it is not rendered at all.
        $soloist = User::factory()->student()->create(['must_change_password' => false]);
        GroupMember::create([
            'group_id' => $this->appDev->id,
            'user_id' => $soloist->id,
            'joined_at' => now(),
        ]);

        foreach (array_keys($this->screens()) as $screen) {
            $this->actingAs($soloist)
                ->get(route($screen, $this->appDev))
                ->assertOk()
                ->assertDontSee('Your groups');
        }
    }

    public function test_the_switcher_never_offers_a_group_you_are_not_in(): void
    {
        $stranger = $this->groupFor(
            User::factory()->lecturer()->create(), 'SECV2113', 'HCI', 'Group Z'
        );

        foreach (array_keys($this->screens()) as $screen) {
            $this->actingAs($this->student)
                ->get(route($screen, $this->appDev))
                ->assertOk()
                ->assertDontSee('SECV2113')
                ->assertDontSee('Group Z');
        }

        // And it is still a 404 if the id is typed in by hand.
        $this->actingAs($this->student)
            ->get(route('student.board.show', $stranger))
            ->assertNotFound();
    }

    public function test_following_the_switcher_actually_changes_the_board(): void
    {
        $this->actingAs($this->student)
            ->get(route('student.board.show', $this->networks))
            ->assertOk()
            // The heading strip names the group whose board is open.
            ->assertSee('SECR1013 — Group B');
    }
}
