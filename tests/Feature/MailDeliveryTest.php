<?php

namespace Tests\Feature;

use App\Mail\AccountCreated;
use App\Mail\PasswordResetLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Outgoing mail: the configuration, the test command, and the branding every
 * email shares.
 *
 * Delivery itself cannot be tested here — that needs a real SMTP server and
 * real credentials, which is what `mail:test` exists for. What is tested is
 * everything around it: that no delivery setting is hardcoded, that the command
 * reports honestly rather than claiming success on the log mailer, and that
 * nothing Laravel ships by default reaches a recipient.
 */
class MailDeliveryTest extends TestCase
{
    use RefreshDatabase;

    /*
    | Configuration comes from the environment, not from the code
    */

    public function test_every_smtp_setting_is_read_from_the_environment(): void
    {
        // The point of the exercise: moving to a different mail provider must
        // be a .env change and nothing else.
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'smtp.example.test',
            'mail.mailers.smtp.port' => 2525,
            'mail.mailers.smtp.scheme' => 'smtps',
            'mail.mailers.smtp.username' => 'someone@example.test',
            'mail.from.address' => 'sender@example.test',
        ]);

        $this->artisan('mail:test', ['email' => 'recipient@example.test'])
            ->expectsOutputToContain('smtp.example.test')
            ->expectsOutputToContain('2525')
            ->expectsOutputToContain('smtps')
            ->expectsOutputToContain('someone@example.test')
            ->expectsOutputToContain('sender@example.test');
    }

    public function test_every_smtp_key_in_the_config_file_comes_from_env(): void
    {
        // A literal default in config/mail.php would quietly win over an empty
        // .env value and make the deployment's real settings invisible.
        $config = file_get_contents(config_path('mail.php'));

        preg_match('/\'smtp\' => \[(.*?)\n        \],/s', $config, $block);

        $this->assertNotEmpty($block[1] ?? null, 'Could not find the smtp mailer block.');

        foreach (['host', 'port', 'username', 'password', 'scheme'] as $key) {
            $this->assertMatchesRegularExpression(
                "/'{$key}' => env\(/",
                $block[1],
                "The smtp '{$key}' setting must be read from the environment.",
            );
        }
    }

    public function test_no_mail_credentials_are_hardcoded_in_the_application(): void
    {
        $offenders = [];

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());

            // A real mailbox address or an App-Password-shaped literal in
            // application code would mean delivery had stopped being driven by
            // .env alone. Host names are not checked: mail:test names
            // smtp.gmail.com in its help text, which is guidance, not
            // configuration.
            if (preg_match('/@gmail\.com|[\'"][a-z]{4} [a-z]{4} [a-z]{4} [a-z]{4}[\'"]/i', $contents)) {
                $offenders[] = basename($file->getPathname());
            }
        }

        $this->assertSame([], $offenders, 'Mail credentials must live in .env, not in app code.');
    }

    /*
    | The mail:test command
    */

    public function test_the_command_refuses_something_that_is_not_an_address(): void
    {
        $this->artisan('mail:test', ['email' => 'not-an-address'])
            ->expectsOutputToContain('is not an email address')
            ->assertFailed();
    }

    /**
     * The messages the array transport collected.
     *
     * Used instead of Mail::fake() wherever the real Symfony message matters:
     * the fake records mailables, and both the headers applied by the mailer
     * and a raw message sent with Mail::raw() only exist once a transport has
     * actually built one. phpunit.xml puts the suite on the array mailer, so
     * nothing leaves the machine either way.
     *
     * @return \Illuminate\Support\Collection<int, \Symfony\Component\Mailer\SentMessage>
     */
    protected function transportMessages()
    {
        return Mail::getSymfonyTransport()->messages();
    }

    public function test_the_command_sends_to_the_address_given(): void
    {
        config(['mail.default' => 'array']);

        $this->artisan('mail:test', ['email' => 'someone@collabspace.test'])->assertSuccessful();

        $messages = $this->transportMessages();

        $this->assertCount(1, $messages);

        $email = $messages->first()->getOriginalMessage();

        $this->assertSame('someone@collabspace.test', $email->getTo()[0]->getAddress());
        $this->assertStringContainsString('test message', $email->getSubject());
    }

    public function test_the_command_does_not_claim_delivery_on_the_log_mailer(): void
    {
        // The single most misleading thing this command could do is report
        // "sent" while on the log mailer. Somebody would take it as proof that
        // mail works and discover otherwise during UAT.
        config(['mail.default' => 'log']);

        $this->artisan('mail:test', ['email' => 'someone@collabspace.test'])
            ->expectsOutputToContain('nothing was sent over the network')
            ->expectsOutputToContain('MAIL_MAILER=smtp')
            ->assertSuccessful();
    }

    public function test_the_command_never_prints_the_password(): void
    {
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.password' => 'abcd efgh ijkl mnop',
        ]);

        $this->artisan('mail:test', ['email' => 'someone@collabspace.test'])
            ->doesntExpectOutputToContain('abcd efgh ijkl mnop')
            // The length is shown, because a wrong-length App Password is the
            // commonest fault and worth seeing.
            ->expectsOutputToContain('19 characters');
    }

    public function test_the_command_reports_the_smtp_error_rather_than_swallowing_it(): void
    {
        config([
            'mail.default' => 'smtp',
            // Nothing listens here, so the transport fails on connect.
            'mail.mailers.smtp.host' => '127.0.0.1',
            'mail.mailers.smtp.port' => 1,
            'mail.mailers.smtp.scheme' => 'smtp',
        ]);

        $this->artisan('mail:test', ['email' => 'someone@collabspace.test'])
            ->expectsOutputToContain('Not sent.')
            ->assertFailed();
    }

    /*
    | Branding — nothing Laravel ships by default reaches a recipient
    */

    public function test_the_account_email_carries_collabspace_branding(): void
    {
        $user = User::factory()->create(['name' => 'Nurul Izzah', 'role' => 'student']);

        $rendered = (new AccountCreated($user, 'Kx7m-Qp4v-3Rna', 'Admin One'))->render();

        $this->assertStringContainsString('CollabSpace', $rendered);
        $this->assertStringContainsString('Nurul Izzah', $rendered);
        $this->assertStringContainsString('Kx7m-Qp4v-3Rna', $rendered);
        $this->assertStringNotContainsString('laravel.com', $rendered);
    }

    public function test_the_shared_mail_layout_has_no_laravel_defaults_left(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $rendered = (new PasswordResetLink($user, 'https://collabspace.test/reset-password/abc', 60))->render();

        // The published header used to swap in a logo hosted on laravel.com
        // whenever the app was called "Laravel" — dead code here, but also a
        // remote image in a transactional email, which clients block.
        $this->assertStringNotContainsString('laravel.com', $rendered);
        $this->assertStringNotContainsString('notification-logo', $rendered);

        // And the palette is the app's own, not the framework's.
        $this->assertStringNotContainsString('#18181b', $rendered);
        $this->assertStringContainsString('#111114', $rendered);
    }

    public function test_the_sender_identity_comes_from_configuration(): void
    {
        config([
            'mail.from.address' => 'sender-from-config@collabspace.test',
            'mail.from.name' => 'Configured Name',
        ]);

        $user = User::factory()->create(['is_active' => true]);

        $this->post('/forgot-password', ['email' => $user->email]);

        $from = $this->transportMessages()->first()->getOriginalMessage()->getFrom()[0];

        $this->assertSame('sender-from-config@collabspace.test', $from->getAddress());
        $this->assertSame('Configured Name', $from->getName());
    }
}
