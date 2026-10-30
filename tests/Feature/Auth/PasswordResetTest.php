<?php

namespace Tests\Feature\Auth;

use App\Mail\PasswordResetLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/**
 * The forgotten-password round trip, end to end.
 *
 * CollabSpace sends its own branded mailable rather than Laravel's built-in
 * ResetPassword notification, so these assert against App\Mail\PasswordResetLink
 * — the stock Breeze tests watched for the notification and would go on passing
 * while the email people actually receive was broken.
 */
class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Pulls the token out of the sent email.
     *
     * The token never appears in the database in readable form — only its hash
     * does — so the email is the only place to read it, which is exactly what a
     * user does.
     */
    protected function tokenFromSentMail(): string
    {
        $url = null;

        Mail::assertSent(PasswordResetLink::class, function (PasswordResetLink $mail) use (&$url) {
            $url = $mail->resetUrl;

            return true;
        });

        $this->assertNotNull($url, 'No reset link email was sent.');

        preg_match('#/reset-password/([^?]+)#', $url, $matches);

        $this->assertNotEmpty($matches[1] ?? null, "No token in the reset URL: {$url}");

        return $matches[1];
    }

    /*
    | The screens
    */

    public function test_reset_password_link_screen_can_be_rendered(): void
    {
        $this->get('/forgot-password')
            ->assertOk()
            ->assertSee('Forgot password')
            // Restyled to the CollabSpace design system, not stock Breeze.
            ->assertSee('Email password reset link');
    }

    public function test_reset_password_screen_can_be_rendered(): void
    {
        Mail::fake();

        $user = User::factory()->create(['is_active' => true]);

        $this->post('/forgot-password', ['email' => $user->email]);

        $this->get('/reset-password/'.$this->tokenFromSentMail())
            ->assertOk()
            ->assertSee('New password')
            ->assertSee('Confirm new password');
    }

    /*
    | Requesting the link
    */

    public function test_reset_password_link_can_be_requested(): void
    {
        Mail::fake();

        $user = User::factory()->create(['is_active' => true]);

        $this->post('/forgot-password', ['email' => $user->email])
            ->assertSessionHasNoErrors();

        Mail::assertSent(PasswordResetLink::class, fn (PasswordResetLink $mail) => $mail->hasTo($user->email));
    }

    public function test_the_email_is_the_collabspace_one_not_laravels(): void
    {
        Mail::fake();

        $user = User::factory()->create(['name' => 'Nurul Izzah', 'is_active' => true]);

        $this->post('/forgot-password', ['email' => $user->email]);

        Mail::assertSent(PasswordResetLink::class, function (PasswordResetLink $mail) use ($user) {
            $rendered = $mail->render();

            // Branding: the subject and body name the application, and the
            // recipient is greeted by name rather than with Laravel's "Hello!".
            $this->assertSame('Reset your CollabSpace password', $mail->envelope()->subject);
            $this->assertStringContainsString('CollabSpace', $rendered);
            $this->assertStringContainsString('Nurul Izzah', $rendered);
            $this->assertStringContainsString('Choose a new password', $rendered);

            // And none of the framework's defaults survive.
            $this->assertStringNotContainsString('laravel.com', $rendered);
            $this->assertStringNotContainsString('Hello!', $rendered);
            $this->assertStringNotContainsString('If you did not request a password reset, no further action is required.', $rendered);

            $this->assertSame($user->email, $mail->user->email);

            return true;
        });
    }

    public function test_the_email_states_the_real_expiry(): void
    {
        Mail::fake();

        $user = User::factory()->create(['is_active' => true]);

        $this->post('/forgot-password', ['email' => $user->email]);

        $expected = (int) config('auth.passwords.users.expire');

        Mail::assertSent(PasswordResetLink::class, function (PasswordResetLink $mail) use ($expected) {
            $this->assertSame($expected, $mail->expiresInMinutes);
            $this->assertStringContainsString("expires in {$expected} minutes", $mail->render());

            return true;
        });
    }

    public function test_an_unknown_address_sends_nothing(): void
    {
        Mail::fake();

        $this->post('/forgot-password', ['email' => 'nobody@collabspace.test'])
            ->assertSessionHasErrors('email');

        Mail::assertNothingSent();
    }

    /*
    | Completing the reset
    */

    public function test_password_can_be_reset_with_valid_token(): void
    {
        Mail::fake();

        $user = User::factory()->create(['is_active' => true]);

        $this->post('/forgot-password', ['email' => $user->email]);

        $this->post('/reset-password', [
            'token' => $this->tokenFromSentMail(),
            'email' => $user->email,
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])->assertSessionHasNoErrors()->assertRedirect(route('login'));

        $this->assertTrue(Hash::check('a-brand-new-password', $user->fresh()->password));
    }

    public function test_resetting_clears_a_temporary_password_flag(): void
    {
        // Somebody who never used the temporary password the administrator
        // issued, and reset it instead. They chose this password themselves, so
        // being bounced onto the forced-change screen straight afterwards and
        // told to choose one would be nonsense.
        Mail::fake();

        $user = User::factory()->create(['is_active' => true, 'must_change_password' => true]);

        $this->post('/forgot-password', ['email' => $user->email]);

        $this->post('/reset-password', [
            'token' => $this->tokenFromSentMail(),
            'email' => $user->email,
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])->assertSessionHasNoErrors();

        $this->assertFalse($user->fresh()->must_change_password);

        // And the whole way through: sign in, and land on the dashboard rather
        // than the change-password screen.
        $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'a-brand-new-password',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user->fresh());
    }

    public function test_a_used_token_does_not_work_twice(): void
    {
        Mail::fake();

        $user = User::factory()->create(['is_active' => true]);

        $this->post('/forgot-password', ['email' => $user->email]);
        $token = $this->tokenFromSentMail();

        $payload = [
            'token' => $token,
            'email' => $user->email,
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ];

        $this->post('/reset-password', $payload)->assertSessionHasNoErrors();
        $this->post('/reset-password', $payload)->assertSessionHasErrors('email');
    }

    public function test_a_mail_failure_is_reported_rather_than_becoming_a_500(): void
    {
        // Somebody who has forgotten their password is already having a bad
        // day; a stack trace is not the answer, and neither is "link sent"
        // when it was not.
        $user = User::factory()->create(['is_active' => true]);

        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP is down'));

        $this->from(route('password.request'))
            ->post('/forgot-password', ['email' => $user->email])
            ->assertRedirect(route('password.request'))
            ->assertSessionHasErrors('email');

        $this->assertStringContainsString(
            'could not be sent',
            session('errors')->first('email'),
        );
    }

    /*
    | Deactivated accounts
    */

    public function test_a_deactivated_account_cannot_request_a_reset_link(): void
    {
        // Deactivation has to close this door too. Password::sendResetLink()
        // knows nothing about is_active, so without the guard an account that
        // cannot sign in would still be handed a working way to change its
        // password and try again.
        Mail::fake();

        $user = User::factory()->deactivated()->create();

        $this->from(route('password.request'))
            ->post('/forgot-password', ['email' => $user->email])
            ->assertRedirect(route('password.request'))
            ->assertSessionHasErrors('email');

        Mail::assertNothingSent();

        $this->assertSame(
            'This account has been deactivated. Ask your administrator to reactivate it.',
            session('errors')->first('email'),
        );
    }

    public function test_a_token_issued_before_deactivation_stops_working(): void
    {
        // The gap that matters: an hour can pass between asking for the link
        // and following it, and the account can be switched off in between.
        Mail::fake();

        $user = User::factory()->create(['is_active' => true]);

        $this->post('/forgot-password', ['email' => $user->email]);
        $token = $this->tokenFromSentMail();

        $user->forceFill(['is_active' => false])->save();

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])->assertSessionHasErrors('email');

        $this->assertFalse(Hash::check('a-brand-new-password', $user->fresh()->password));
    }

    public function test_reactivating_lets_the_reset_work_again(): void
    {
        Mail::fake();

        $user = User::factory()->deactivated()->create();

        $this->post('/forgot-password', ['email' => $user->email])->assertSessionHasErrors('email');

        $user->forceFill(['is_active' => true])->save();

        $this->post('/forgot-password', ['email' => $user->email])->assertSessionHasNoErrors();

        Mail::assertSent(PasswordResetLink::class);
    }

    public function test_an_unknown_address_is_not_reported_as_deactivated(): void
    {
        // The deactivation message must not become a way to find out which
        // addresses have accounts.
        $this->post('/forgot-password', ['email' => 'nobody@collabspace.test'])
            ->assertSessionHasErrors('email');

        $this->assertSame(
            __(Password::INVALID_USER),
            session('errors')->first('email'),
        );
    }
}
