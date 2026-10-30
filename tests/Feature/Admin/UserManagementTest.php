<?php

namespace Tests\Feature\Admin;

use App\Mail\AccountCreated;
use App\Mail\TemporaryPasswordIssued;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function admin(): User
    {
        return User::factory()->admin()->create(['must_change_password' => false]);
    }

    /*
    | Access
    */

    public function test_the_admin_area_is_closed_to_students(): void
    {
        $student = User::factory()->create(['must_change_password' => false]);

        $this->actingAs($student)->get(route('admin.dashboard'))->assertForbidden();
        $this->actingAs($student)->get(route('admin.users'))->assertForbidden();
    }

    public function test_the_admin_area_is_closed_to_lecturers(): void
    {
        $lecturer = User::factory()->lecturer()->create(['must_change_password' => false]);

        $this->actingAs($lecturer)->get(route('admin.users'))->assertForbidden();
    }

    public function test_the_admin_area_is_closed_to_guests(): void
    {
        $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
    }

    public function test_an_admin_on_a_temporary_password_is_held_at_the_change_screen(): void
    {
        $admin = User::factory()->admin()->create(['must_change_password' => true]);

        $this->actingAs($admin)->get(route('admin.users'))->assertRedirect(route('password.change'));
    }

    /*
    | Dashboard
    */

    public function test_the_dashboard_shows_the_account_totals(): void
    {
        $admin = $this->admin();
        User::factory()->count(3)->student()->create();
        User::factory()->count(2)->lecturer()->create();
        User::factory()->deactivated()->create();

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Total users')
            ->assertSee('Students')
            ->assertSee('Lecturers')
            ->assertSee('Active')
            ->assertSee('Inactive')
            ->assertSee('Recently added');
    }

    public function test_the_dashboard_redirect_sends_an_admin_to_the_admin_dashboard(): void
    {
        $this->actingAs($this->admin())
            ->get('/dashboard')
            ->assertRedirect(route('admin.dashboard'));
    }

    /*
    | Listing, search and filter
    */

    public function test_search_matches_name_email_and_matric_number(): void
    {
        $admin = $this->admin();
        User::factory()->create(['name' => 'Farah Aqilah', 'email' => 'farah@x.test', 'matric_no' => 'A21EC0042']);
        User::factory()->create(['name' => 'Lee Wei Jie', 'email' => 'weijie@x.test', 'matric_no' => 'A21EC0037']);

        $this->actingAs($admin)->get(route('admin.users', ['search' => 'Farah']))
            ->assertSee('Farah Aqilah')->assertDontSee('Lee Wei Jie');

        $this->actingAs($admin)->get(route('admin.users', ['search' => 'weijie@x.test']))
            ->assertSee('Lee Wei Jie')->assertDontSee('Farah Aqilah');

        $this->actingAs($admin)->get(route('admin.users', ['search' => 'A21EC0042']))
            ->assertSee('Farah Aqilah')->assertDontSee('Lee Wei Jie');
    }

    public function test_the_role_filter_narrows_the_list(): void
    {
        $admin = $this->admin();
        User::factory()->create(['name' => 'Student Person']);
        User::factory()->lecturer()->create(['name' => 'Lecturer Person']);

        $this->actingAs($admin)->get(route('admin.users', ['role' => 'lecturer']))
            ->assertSee('Lecturer Person')
            ->assertDontSee('Student Person');
    }

    /*
    | Create
    */

    public function test_creating_a_student_issues_a_temporary_password(): void
    {
        Mail::fake();

        $response = $this->actingAs($this->admin())->post(route('admin.users.store'), [
            'name' => 'Nurul Izzah',
            'email' => 'nurul@student.collabspace.test',
            'role' => 'student',
            'matric_no' => 'A21EC0024',
        ]);

        $response->assertRedirect(route('admin.users'))
            // The password goes to its owner by email, not onto the
            // administrator's screen — see AccountEmailTest.
            ->assertSessionMissing('temporary_password')
            ->assertSessionHas('status');

        $user = User::where('email', 'nurul@student.collabspace.test')->firstOrFail();

        $this->assertSame('student', $user->role);
        $this->assertSame('A21EC0024', $user->matric_no);
        $this->assertTrue($user->must_change_password);
        $this->assertTrue($user->is_active);

        // The password stored is the hash of the one that was emailed.
        Mail::assertSent(AccountCreated::class, fn (AccountCreated $mail) => Hash::check($mail->temporaryPassword, $user->password));
    }

    public function test_a_student_must_have_a_matric_number(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.users.store'), [
                'name' => 'No Matric',
                'email' => 'nomatric@student.collabspace.test',
                'role' => 'student',
                'matric_no' => '',
            ])
            ->assertSessionHasErrors('matric_no');

        $this->assertDatabaseMissing('users', ['email' => 'nomatric@student.collabspace.test']);
    }

    public function test_a_lecturer_is_stored_without_a_matric_number(): void
    {
        $this->actingAs($this->admin())->post(route('admin.users.store'), [
            'name' => 'Dr Tan',
            'email' => 'tan@collabspace.test',
            'role' => 'lecturer',
            'matric_no' => 'SHOULD-BE-DROPPED',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', ['email' => 'tan@collabspace.test', 'matric_no' => null]);
    }

    public function test_a_duplicate_matric_number_is_rejected(): void
    {
        User::factory()->create(['matric_no' => 'A21EC0011']);

        $this->actingAs($this->admin())
            ->post(route('admin.users.store'), [
                'name' => 'Clash',
                'email' => 'clash@student.collabspace.test',
                'role' => 'student',
                'matric_no' => 'A21EC0011',
            ])
            ->assertSessionHasErrors('matric_no');
    }

    /*
    | Edit
    */

    public function test_an_admin_can_edit_an_account(): void
    {
        $user = User::factory()->create(['name' => 'Old Name', 'matric_no' => 'A21EC0099']);

        $this->actingAs($this->admin())
            ->put(route('admin.users.update', $user), [
                'name' => 'New Name',
                'email' => $user->email,
                'role' => 'student',
                'matric_no' => 'A21EC0099',
            ])
            ->assertRedirect(route('admin.users'));

        $this->assertSame('New Name', $user->fresh()->name);
    }

    public function test_promoting_a_student_to_lecturer_clears_the_matric_number(): void
    {
        $user = User::factory()->create(['matric_no' => 'A21EC0088']);

        $this->actingAs($this->admin())->put(route('admin.users.update', $user), [
            'name' => $user->name,
            'email' => $user->email,
            'role' => 'lecturer',
            'matric_no' => 'A21EC0088',
        ])->assertSessionHasNoErrors();

        $this->assertNull($user->fresh()->matric_no);
        $this->assertSame('lecturer', $user->fresh()->role);
    }

    public function test_an_admin_cannot_change_their_own_role(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->put(route('admin.users.update', $admin), [
                'name' => $admin->name,
                'email' => $admin->email,
                'role' => 'student',
                'matric_no' => 'A21EC0001',
            ])
            ->assertSessionHasErrors('role');

        $this->assertSame('admin', $admin->fresh()->role);
    }

    public function test_demoting_yourself_to_lecturer_is_refused_too(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->put(route('admin.users.update', $admin), [
            'name' => $admin->name,
            'email' => $admin->email,
            'role' => 'lecturer',
            'matric_no' => null,
        ])->assertSessionHasErrors('role');

        $this->assertSame('admin', $admin->fresh()->role);
    }

    public function test_an_admin_can_still_edit_their_own_name_and_email(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->put(route('admin.users.update', $admin), [
            'name' => 'Renamed Admin',
            'email' => 'renamed@collabspace.test',
            'role' => 'admin',
            'matric_no' => null,
        ])->assertSessionHasNoErrors()->assertRedirect(route('admin.users'));

        $admin->refresh();

        $this->assertSame('Renamed Admin', $admin->name);
        $this->assertSame('renamed@collabspace.test', $admin->email);
        $this->assertSame('admin', $admin->role);
    }

    public function test_another_admin_can_change_that_role(): void
    {
        $other = User::factory()->admin()->create();

        $this->actingAs($this->admin())->put(route('admin.users.update', $other), [
            'name' => $other->name,
            'email' => $other->email,
            'role' => 'lecturer',
            'matric_no' => null,
        ])->assertSessionHasNoErrors();

        $this->assertSame('lecturer', $other->fresh()->role);
    }

    public function test_the_role_control_is_disabled_on_your_own_edit_screen(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->get(route('admin.users.edit', $admin))
            ->assertOk()
            ->assertSee('You cannot change your own role', false)
            // The radio inputs are replaced by static labels plus a hidden field.
            ->assertSee('<input type="hidden" name="role" value="admin">', false)
            ->assertDontSee('name="role" value="student"', false);
    }

    public function test_the_role_control_stays_live_on_someone_elses_edit_screen(): void
    {
        $other = User::factory()->create();

        $this->actingAs($this->admin())
            ->get(route('admin.users.edit', $other))
            ->assertOk()
            ->assertSee('name="role" value="student"', false)
            ->assertDontSee('You cannot change your own role', false);
    }

    /*
    | Activate / deactivate
    */

    public function test_an_account_can_be_deactivated_and_reactivated(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();

        $this->actingAs($admin)->put(route('admin.users.status', $user));
        $this->assertFalse($user->fresh()->is_active);

        $this->actingAs($admin)->put(route('admin.users.status', $user));
        $this->assertTrue($user->fresh()->is_active);
    }

    public function test_an_admin_cannot_deactivate_their_own_account(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->put(route('admin.users.status', $admin))
            ->assertSessionHasErrors('status');

        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_a_deactivated_user_is_turned_away_at_the_door(): void
    {
        $user = User::factory()->deactivated()->create(['must_change_password' => false]);

        // RoleMiddleware guards the admin area; a deactivated admin is ejected.
        $deactivatedAdmin = User::factory()->admin()->deactivated()->create(['must_change_password' => false]);

        $this->actingAs($deactivatedAdmin)
            ->get(route('admin.users'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    /*
    | Reset password
    */

    public function test_resetting_a_password_issues_a_new_temporary_one(): void
    {
        Mail::fake();

        $user = User::factory()->create([
            'password' => 'the-old-password',
            'must_change_password' => false,
        ]);
        $originalHash = $user->password;

        $this->actingAs($this->admin())
            ->put(route('admin.users.password', $user))
            ->assertSessionMissing('temporary_password')
            ->assertSessionHas('status');

        $user->refresh();

        $this->assertNotSame($originalHash, $user->password);
        $this->assertFalse(Hash::check('the-old-password', $user->password));
        $this->assertTrue($user->must_change_password);

        // The new one reaches its owner by email rather than the screen.
        Mail::assertSent(TemporaryPasswordIssued::class, fn (TemporaryPasswordIssued $mail) => Hash::check($mail->temporaryPassword, $user->password));
    }
}
