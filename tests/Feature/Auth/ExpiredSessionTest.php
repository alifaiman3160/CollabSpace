<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * "Page expired" — a CSRF token that no longer matches the session.
 *
 * Laravel's stock answer is a dead-end error page reading "419 | PAGE EXPIRED".
 * It is reached more often than it should be: anything that ends the session
 * behind an open tab produces it, and `migrate:fresh --seed` does exactly that
 * by dropping the sessions table while browsers still hold their cookies.
 * Someone who had been signed in then cannot sign in again, because the login
 * form in front of them is itself carrying the stale token — the one state a
 * sign-in screen must never be able to reach.
 *
 * The real token cannot be exercised from here: VerifyCsrfToken skips
 * verification while running tests, so there is nothing to mismatch. These
 * drive the 419 the framework produces, which is the exception the handler
 * actually receives.
 */
class ExpiredSessionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('web')->post('/_test/expired', fn () => abort(419))->name('test.expired');
        Route::middleware('web')->post('/_test/missing', fn () => abort(404))->name('test.missing');
    }

    public function test_an_expired_page_sends_you_back_to_the_form_rather_than_a_dead_end(): void
    {
        $this->from(route('login'))
            ->post('/_test/expired', ['email' => 'admin@collabspace.test'])
            ->assertRedirect(route('login'))
            ->assertSessionHas('status');

        $this->assertStringContainsString('session expired', session('status'));
        $this->assertStringContainsString('Nothing was saved', session('status'));
    }

    public function test_what_was_typed_comes_back_with_it(): void
    {
        // Retyping an email after the system lost your submission is the sort
        // of small insult that makes people distrust the whole thing.
        $this->from(route('login'))
            ->post('/_test/expired', ['email' => 'admin@collabspace.test'])
            ->assertRedirect(route('login'));

        $this->assertSame('admin@collabspace.test', old('email'));
    }

    public function test_the_password_is_never_handed_back(): void
    {
        // Old input is rendered straight into the next page.
        $this->from(route('login'))
            ->post('/_test/expired', [
                'email' => 'admin@collabspace.test',
                'password' => 'the-real-password',
                'password_confirmation' => 'the-real-password',
            ])
            ->assertRedirect(route('login'));

        $this->assertNull(old('password'));
        $this->assertNull(old('password_confirmation'));
    }

    public function test_the_retry_actually_works(): void
    {
        // The whole point: the page it lands on carries a fresh token, so the
        // second attempt goes through. Before this, the only way out was
        // knowing to clear your cookies by hand.
        $user = \App\Models\User::factory()->admin()->create([
            'password' => 'correct-password',
            'must_change_password' => false,
        ]);

        $this->from(route('login'))
            ->post('/_test/expired', ['email' => $user->email])
            ->assertRedirect(route('login'));

        $this->get(route('login'))->assertOk()->assertSee('session expired');

        $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'correct-password',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_an_xhr_caller_still_gets_the_419(): void
    {
        // Livewire answers a 419 by reloading the page, which gets the same
        // fresh token. A redirect would be swallowed as a page fragment.
        $this->postJson('/_test/expired')
            ->assertStatus(419)
            ->assertJsonStructure(['message']);
    }

    public function test_a_livewire_request_is_left_to_handle_itself(): void
    {
        $this->withHeaders(['X-Livewire' => 'true'])
            ->post('/_test/expired')
            ->assertStatus(419);
    }

    public function test_other_errors_are_left_alone(): void
    {
        // The handler must not swallow every HTTP exception on its way past.
        $this->from(route('login'))
            ->post('/_test/missing')
            ->assertNotFound();
    }

    public function test_it_never_bounces_back_to_the_endpoint_that_refused(): void
    {
        // Without the guard, a form posting to itself would redirect to the
        // same POST target and land straight back on the same error.
        $this->from('http://localhost/_test/expired')
            ->post('/_test/expired')
            ->assertRedirect(route('login'));
    }

    public function test_an_off_site_referrer_is_not_followed(): void
    {
        // url()->previous() trusts the Referer header, which a visitor sets.
        $this->from('https://somewhere-else.test/anything')
            ->post('/_test/expired')
            ->assertRedirect(route('login'));
    }
}
