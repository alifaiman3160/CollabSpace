<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Deactivating an account has to end the session that is already open, not just
 * refuse the next sign-in.
 *
 * The regression these cover: the is_active check used to run only in
 * LoginRequest and RoleMiddleware, so a user who was signed in when the
 * administrator deactivated them kept their session on every route without a
 * role guard — "My account" among them. EnsureAccountActive now runs on the
 * whole web group.
 */
class DeactivatedSessionTest extends TestCase
{
    use RefreshDatabase;

    /** Signs in for real, through the login form, so a session exists. */
    protected function signedIn(string $role = 'student'): User
    {
        $user = User::factory()->create([
            'role' => $role,
            'must_change_password' => false,
            'password' => 'correct-password',
        ]);

        $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'correct-password',
        ]);

        $this->assertAuthenticatedAs($user);

        return $user;
    }

    /**
     * Deactivates the account the way an administrator would, then drops the
     * resolved auth guard.
     *
     * The container is reused across requests inside one test, so the guard
     * would otherwise keep handing back the User object it resolved at sign-in
     * — still carrying is_active = true in memory. A real deployment serves
     * each request in its own process and reloads the user from the database,
     * and forgetting the guard is what reproduces that here. Without this the
     * test passes against a broken middleware, which is worse than no test.
     */
    protected function deactivate(User $user): void
    {
        $user->forceFill(['is_active' => false])->save();

        $this->app['auth']->forgetGuards();
    }

    public function test_a_session_open_at_the_moment_of_deactivation_is_refused_on_the_next_request(): void
    {
        $user = $this->signedIn();

        // The session is live: the account page answers.
        $this->get(route('account'))->assertOk();

        // An administrator deactivates the account mid-session.
        $this->deactivate($user);

        $this->get(route('account'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_the_account_route_carries_no_role_guard_and_is_still_covered(): void
    {
        // /account has no 'role' middleware, which is exactly why the old
        // placement of this check missed it.
        $middleware = app('router')->getRoutes()->getByName('account')->gatherMiddleware();

        $this->assertEmpty(
            array_filter($middleware, fn ($m) => is_string($m) && str_starts_with($m, 'role')),
        );

        $user = $this->signedIn('lecturer');
        $this->deactivate($user);

        $this->get(route('account'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_every_role_is_ejected_from_its_own_area(): void
    {
        foreach ([
            'student' => 'student.dashboard',
            'lecturer' => 'lecturer.dashboard',
            'admin' => 'admin.dashboard',
        ] as $role => $route) {
            $user = $this->signedIn($role);

            $this->get(route($route))->assertOk();

            $this->deactivate($user);

            $this->get(route($route))->assertRedirect(route('login'));
            $this->assertGuest();
        }
    }

    public function test_the_dashboard_dispatcher_does_not_leak_through(): void
    {
        $user = $this->signedIn();
        $this->deactivate($user);

        $this->get('/dashboard')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_a_deactivated_session_cannot_post_either(): void
    {
        $user = $this->signedIn();
        $this->deactivate($user);

        $this->patch(route('account.update'), [
            'name' => 'Renamed Anyway',
            'email' => $user->email,
        ])->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertNotSame('Renamed Anyway', $user->fresh()->name);
    }

    public function test_a_user_still_on_a_temporary_password_is_ejected_too(): void
    {
        // EnsurePasswordChanged excepts the change screen so nobody is trapped
        // in a redirect loop. That exception must not become a way for a
        // deactivated account to keep using the app.
        $user = User::factory()->create(['is_active' => false, 'must_change_password' => true]);

        $this->actingAs($user)
            ->get(route('password.change'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_reactivating_lets_them_back_in(): void
    {
        $user = $this->signedIn();
        $this->deactivate($user);

        $this->get(route('account'))->assertRedirect(route('login'));

        $user->forceFill(['is_active' => true])->save();

        $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'correct-password',
        ]);

        $this->assertAuthenticatedAs($user);
        $this->get(route('account'))->assertOk();
    }

    public function test_an_active_session_is_untouched(): void
    {
        $this->signedIn();

        $this->get(route('account'))->assertOk();
        $this->get(route('student.dashboard'))->assertOk();

        $this->assertAuthenticated();
    }

    public function test_a_guest_passes_straight_through(): void
    {
        $this->get(route('login'))->assertOk();

        $this->assertGuest();
    }
}
