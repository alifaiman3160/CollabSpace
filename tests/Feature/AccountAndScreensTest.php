<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Message;
use App\Models\Subject;
use App\Models\Task;
use App\Models\User;
use App\Support\ActivityRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AccountAndScreensTest extends TestCase
{
    use RefreshDatabase;

    protected User $lecturer;

    protected Subject $subject;

    protected Group $group;

    protected User $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lecturer = User::factory()->lecturer()->create(['must_change_password' => false]);
        $this->subject = Subject::create([
            'lecturer_id' => $this->lecturer->id, 'code' => 'SECJ3104', 'name' => 'App Dev', 'group_size' => 4,
        ]);
        $this->group = Group::create(['subject_id' => $this->subject->id, 'name' => 'Group A', 'group_size' => 4]);

        $this->student = User::factory()->student()->create([
            'name' => 'Alif Aiman', 'matric_no' => 'A21EC0011', 'must_change_password' => false,
        ]);
        GroupMember::create(['group_id' => $this->group->id, 'user_id' => $this->student->id, 'joined_at' => now()]);
    }

    /*
    | My account — replaces Breeze's profile
    */

    public function test_the_breeze_profile_routes_are_gone(): void
    {
        $this->assertFalse(Route::has('profile.edit'));
        $this->assertFalse(Route::has('profile.update'));
        $this->assertFalse(Route::has('profile.destroy'));

        $this->actingAs($this->student)->get('/profile')->assertNotFound();
    }

    public function test_every_role_can_open_their_account(): void
    {
        $admin = User::factory()->admin()->create(['must_change_password' => false]);

        foreach ([$admin, $this->lecturer, $this->student] as $user) {
            $this->actingAs($user)
                ->get(route('account'))
                ->assertOk()
                ->assertSee('My account')
                ->assertSee($user->email)
                ->assertSee('Change password');
        }
    }

    public function test_a_student_sees_their_own_details(): void
    {
        $this->actingAs($this->student)
            ->get(route('account'))
            ->assertOk()
            ->assertSee('Alif Aiman')
            ->assertSee('A21EC0011')
            ->assertSee('Student');
    }

    public function test_details_can_be_updated(): void
    {
        $this->actingAs($this->student)
            ->patch(route('account.update'), ['name' => 'Alif A. Jaafar', 'email' => $this->student->email])
            ->assertSessionHasNoErrors();

        $this->assertSame('Alif A. Jaafar', $this->student->fresh()->name);
    }

    public function test_changing_the_email_clears_verification(): void
    {
        $this->actingAs($this->student)->patch(route('account.update'), [
            'name' => $this->student->name, 'email' => 'new.address@student.collabspace.test',
        ]);

        $this->assertNull($this->student->fresh()->email_verified_at);
    }

    public function test_an_email_already_in_use_is_rejected(): void
    {
        $other = User::factory()->student()->create();

        $this->actingAs($this->student)
            ->patch(route('account.update'), ['name' => 'X', 'email' => $other->email])
            ->assertSessionHasErrors('email');
    }

    public function test_role_cannot_be_escalated_through_the_account_form(): void
    {
        $this->actingAs($this->student)->patch(route('account.update'), [
            'name' => $this->student->name,
            'email' => $this->student->email,
            'role' => 'admin',
            'matric_no' => 'HACKED',
        ]);

        $fresh = $this->student->fresh();

        $this->assertSame('student', $fresh->role);
        $this->assertSame('A21EC0011', $fresh->matric_no);
    }

    public function test_the_password_can_be_changed(): void
    {
        $user = User::factory()->student()->create([
            'password' => 'old-password', 'must_change_password' => false,
        ]);

        $this->actingAs($user)->put(route('account.password'), [
            'current_password' => 'old-password',
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('a-brand-new-password', $user->fresh()->password));
    }

    public function test_the_wrong_current_password_is_rejected(): void
    {
        $user = User::factory()->student()->create([
            'password' => 'old-password', 'must_change_password' => false,
        ]);

        $this->actingAs($user)->put(route('account.password'), [
            'current_password' => 'not-it',
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])->assertSessionHasErrors('current_password');

        $this->assertTrue(Hash::check('old-password', $user->fresh()->password));
    }

    /*
    | Sign out — the sidebar posts, it does not link
    */

    public function test_signing_out_works_from_the_shell(): void
    {
        $html = $this->actingAs($this->student)->get(route('student.dashboard'))->getContent();

        // A GET link to the POST-only logout route would 405 on every page.
        $this->assertStringNotContainsString('href="'.url('/logout').'"', $html);
        $this->assertStringContainsString('action="'.route('logout').'"', $html);

        $this->post(route('logout'))->assertRedirect('/');
        $this->assertGuest();
    }

    /*
    | Lecturer: Students
    */

    public function test_students_lists_the_lecturers_own_students_with_standing(): void
    {
        app(ActivityRecorder::class)->record($this->student, $this->group, 'task_completed', null);

        $this->actingAs($this->lecturer)
            ->get(route('lecturer.students'))
            ->assertOk()
            ->assertSee('Alif Aiman')
            ->assertSee('A21EC0011')
            ->assertSee('SECJ3104')
            ->assertSee('Group A')
            ->assertSee('5 pts');
    }

    public function test_students_search_narrows_the_list(): void
    {
        $other = User::factory()->student()->create(['name' => 'Someone Else']);
        GroupMember::create(['group_id' => $this->group->id, 'user_id' => $other->id, 'joined_at' => now()]);

        $this->actingAs($this->lecturer)
            ->get(route('lecturer.students', ['search' => 'Alif']))
            ->assertOk()
            ->assertSee('Alif Aiman')
            ->assertDontSee('Someone Else');
    }

    public function test_students_excludes_another_lecturers_cohort(): void
    {
        $other = User::factory()->lecturer()->create(['must_change_password' => false]);
        $theirSubject = Subject::create([
            'lecturer_id' => $other->id, 'code' => 'X100', 'name' => 'Theirs', 'group_size' => 4,
        ]);
        $theirGroup = Group::create(['subject_id' => $theirSubject->id, 'name' => 'Their Group', 'group_size' => 4]);
        $theirStudent = User::factory()->student()->create(['name' => 'Not Mine']);
        GroupMember::create(['group_id' => $theirGroup->id, 'user_id' => $theirStudent->id, 'joined_at' => now()]);

        $this->actingAs($this->lecturer)
            ->get(route('lecturer.students'))
            ->assertOk()
            ->assertSee('Alif Aiman')
            ->assertDontSee('Not Mine');
    }

    /*
    | Lecturer: Monitor progress
    */

    public function test_monitor_progress_shows_the_board_members_and_messages(): void
    {
        Task::create([
            'group_id' => $this->group->id, 'created_by' => $this->student->id,
            'assigned_to' => $this->student->id, 'title' => 'Queued task', 'status' => Task::STATUS_TODO,
        ]);
        Task::create([
            'group_id' => $this->group->id, 'created_by' => $this->student->id,
            'title' => 'Finished task', 'status' => Task::STATUS_DONE, 'completed_at' => now(),
        ]);
        Message::create(['group_id' => $this->group->id, 'user_id' => $this->student->id, 'body' => 'Hello team']);

        $this->actingAs($this->lecturer)
            ->get(route('lecturer.progress.show', $this->group))
            ->assertOk()
            ->assertSee('Task board')
            ->assertSee('Read-only')
            ->assertSee('Queued task')
            ->assertSee('Finished task')
            ->assertSee('Member activity')
            ->assertSee('Recent messages')
            ->assertSee('Hello team');
    }

    public function test_the_monitor_view_has_no_editing_controls(): void
    {
        $html = $this->actingAs($this->lecturer)
            ->get(route('lecturer.progress.show', $this->group))
            ->getContent();

        // No status selects, no drag handles, no delete buttons.
        $this->assertStringNotContainsString('wire:change="moveTask', $html);
        $this->assertStringNotContainsString('draggable="true"', $html);
        $this->assertStringNotContainsString('confirmDelete', $html);
    }

    public function test_the_dashboard_monitor_button_points_at_the_progress_view(): void
    {
        $this->actingAs($this->lecturer)
            ->get(route('lecturer.dashboard'))
            ->assertOk()
            ->assertSee(route('lecturer.progress.show', $this->group), escape: false);
    }

    public function test_another_lecturers_group_cannot_be_monitored(): void
    {
        $other = User::factory()->lecturer()->create(['must_change_password' => false]);
        $theirSubject = Subject::create([
            'lecturer_id' => $other->id, 'code' => 'X100', 'name' => 'Theirs', 'group_size' => 4,
        ]);
        $theirGroup = Group::create(['subject_id' => $theirSubject->id, 'name' => 'Their Group', 'group_size' => 4]);

        $this->actingAs($this->lecturer)
            ->get(route('lecturer.progress.show', $theirGroup))
            ->assertNotFound();
    }

    public function test_students_and_progress_are_closed_to_other_roles(): void
    {
        $admin = User::factory()->admin()->create(['must_change_password' => false]);

        foreach ([$this->student, $admin] as $user) {
            $this->actingAs($user)->get(route('lecturer.students'))->assertForbidden();
            $this->actingAs($user)->get(route('lecturer.progress'))->assertForbidden();
        }
    }

    /*
    | The nav has no dead links left
    */

    public function test_no_nav_item_falls_back_to_a_hash(): void
    {
        $composer = new \App\View\Composers\NavigationComposer();
        $method = new \ReflectionMethod($composer, 'navFor');
        $method->setAccessible(true);

        foreach (['admin', 'lecturer', 'student'] as $role) {
            foreach ($method->invoke($composer, $role) as $item) {
                $this->assertTrue(
                    Route::has($item['route']),
                    "Nav item '{$item['label']}' for {$role} points at '{$item['route']}', which does not exist."
                );
            }
        }
    }

    public function test_the_rendered_sidebar_contains_no_dead_links(): void
    {
        $admin = User::factory()->admin()->create(['must_change_password' => false]);

        $pages = [
            [$admin, route('admin.dashboard')],
            [$this->lecturer, route('lecturer.dashboard')],
            [$this->student, route('student.dashboard')],
        ];

        foreach ($pages as [$user, $url]) {
            $html = $this->actingAs($user)->get($url)->assertOk()->getContent();

            // x-nav-item renders href="#" when a route is missing.
            $this->assertStringNotContainsString('href="#"', $html,
                "The sidebar for {$user->role} still has a dead link.");
        }
    }

    /**
     * A Blade directive glued to the end of a word never compiles — Blade
     * requires a non-word character before the @. It then renders as literal
     * text, which on the account page read as an email address. It fails
     * silently, so it is worth checking every view rather than one page.
     */
    public function test_no_view_glues_a_blade_directive_to_a_word(): void
    {
        $offenders = [];

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('views')),
        );

        foreach ($files as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $source = file_get_contents($file->getPathname());

            if (preg_match('/[A-Za-z0-9_]@(?:if|unless|else|elseif|endif|foreach|endforeach|isset|php|endphp|js|class)\b/', $source, $m)) {
                $offenders[] = $file->getPathname().' → '.$m[0];
            }
        }

        $this->assertSame([], $offenders,
            "A Blade directive is stuck to a word and will render as text:\n".implode("\n", $offenders));
    }

    /**
     * The shell used to carry a search box on every screen that did nothing
     * when typed into. Search now lives only where a list exists to filter.
     */
    public function test_the_shell_carries_no_decorative_search_box(): void
    {
        $pages = [
            [$this->student, route('account')],
            [$this->student, route('student.dashboard')],
            [$this->lecturer, route('lecturer.dashboard')],
        ];

        foreach ($pages as [$user, $url]) {
            $html = $this->actingAs($user)->get($url)->assertOk()->getContent();

            $this->assertStringNotContainsString('placeholder="Search…"', $html,
                "A dead search box is back on {$url}.");
        }
    }

    public function test_the_screens_with_a_list_keep_their_own_search(): void
    {
        $admin = User::factory()->admin()->create(['must_change_password' => false]);

        $this->actingAs($admin)->get(route('admin.users'))->assertOk()
            ->assertSee('name="search"', escape: false);

        $this->actingAs($this->lecturer)->get(route('lecturer.students'))->assertOk()
            ->assertSee('name="search"', escape: false);
    }
}