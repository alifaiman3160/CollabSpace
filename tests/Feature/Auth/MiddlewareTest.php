<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class MiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['web', 'auth', 'role:admin,lecturer'])
            ->get('/_test/staff-only', fn () => 'staff area')
            ->name('test.staff');

        Route::middleware(['web', 'auth', 'password.changed'])
            ->get('/_test/guarded', fn () => 'guarded')
            ->name('test.guarded');
    }

    public function test_role_middleware_allows_a_listed_role(): void
    {
        $lecturer = User::factory()->create(['role' => 'lecturer', 'must_change_password' => false]);

        $this->actingAs($lecturer)->get('/_test/staff-only')->assertOk();
    }

    public function test_role_middleware_forbids_an_unlisted_role(): void
    {
        $student = User::factory()->create(['role' => 'student', 'must_change_password' => false]);

        $this->actingAs($student)->get('/_test/staff-only')->assertForbidden();
    }

    public function test_role_middleware_logs_out_a_deactivated_account(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => false]);

        $this->actingAs($admin)
            ->get('/_test/staff-only')
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_a_temporary_password_is_held_at_the_change_screen(): void
    {
        $user = User::factory()->create(['must_change_password' => true]);

        $this->actingAs($user)
            ->get('/_test/guarded')
            ->assertRedirect(route('password.change'));
    }

    public function test_the_change_screen_itself_stays_reachable(): void
    {
        $user = User::factory()->create(['must_change_password' => true]);

        $this->actingAs($user)->get(route('password.change'))->assertOk();
    }

    public function test_changing_the_password_clears_the_flag_and_releases_the_user(): void
    {
        $user = User::factory()->create([
            'password' => 'temp-password',
            'must_change_password' => true,
        ]);

        $this->actingAs($user)
            ->put(route('password.change.update'), [
                'current_password' => 'temp-password',
                'password' => 'a-much-better-password',
                'password_confirmation' => 'a-much-better-password',
            ])
            ->assertRedirect(route('dashboard'));

        $this->assertFalse($user->fresh()->must_change_password);

        $this->actingAs($user->fresh())->get('/_test/guarded')->assertOk();
    }

    public function test_a_settled_password_passes_straight_through(): void
    {
        $user = User::factory()->create(['must_change_password' => false]);

        $this->actingAs($user)->get('/_test/guarded')->assertOk();
    }

    public function test_a_deactivated_account_cannot_sign_in(): void
    {
        $user = User::factory()->deactivated()->create([
            'password' => 'correct-password',
            'must_change_password' => false,
        ]);

        $this->from(route('login'))
            ->post(route('login'), [
                'email' => $user->email,
                'password' => 'correct-password',
            ])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_an_active_account_signs_in_normally(): void
    {
        $user = User::factory()->create([
            'password' => 'correct-password',
            'must_change_password' => false,
        ]);

        $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'correct-password',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_the_verify_email_screen_renders(): void
    {
        $user = User::factory()->unverified()->create(['must_change_password' => false]);

        $this->actingAs($user)->get(route('verification.notice'))->assertOk();
    }
}
