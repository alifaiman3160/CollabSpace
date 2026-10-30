<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Proves mail delivery works, before anything depends on it.
 *
 * Two of CollabSpace's flows are useless if mail does not leave the machine:
 * an account is created with a temporary password that reaches its owner only
 * by email, and a forgotten password can only be reset through a link sent the
 * same way. Both fail quietly in their own way — the account controller catches
 * the error and reports it in a flash message nobody reads twice, and the reset
 * link simply never arrives. So this exists to make the failure loud, on
 * demand, with the actual SMTP response attached.
 *
 * Deliberately not queued and not wrapped in a retry: the point is to stand
 * still and watch one message succeed or fail.
 */
class SendTestMail extends Command
{
    protected $signature = 'mail:test {email : Where to send the test message}';

    protected $description = 'Send a test email and report the result, or the SMTP error behind a failure';

    public function handle(): int
    {
        $recipient = trim((string) $this->argument('email'));

        if (! filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            $this->error("“{$recipient}” is not an email address.");

            return self::FAILURE;
        }

        $mailer = (string) config('mail.default');

        $this->showConfiguration($mailer, $recipient);

        try {
            Mail::raw($this->body($mailer, $recipient), function ($message) use ($recipient) {
                $message->to($recipient)
                    ->subject(config('app.name').' — test message');
            });
        } catch (Throwable $e) {
            $this->reportFailure($e);

            return self::FAILURE;
        }

        return $this->reportSuccess($mailer, $recipient);
    }

    /**
     * What the mailer is actually configured with.
     *
     * Printed before the attempt rather than after, so a hang on a wrong port
     * still leaves the settings on screen to look at. The password is shown
     * only as a length: a wrong-length App Password is the single most common
     * fault and is worth seeing, while the value itself has no business in a
     * terminal someone may screenshot.
     */
    protected function showConfiguration(string $mailer, string $recipient): void
    {
        $password = (string) config('mail.mailers.smtp.password');

        $rows = [
            ['Mailer', $mailer],
            ['To', $recipient],
            ['From', config('mail.from.address').' ('.config('mail.from.name').')'],
        ];

        if ($mailer === 'smtp') {
            $rows = array_merge($rows, [
                ['Host', config('mail.mailers.smtp.host')],
                ['Port', config('mail.mailers.smtp.port')],
                ['Scheme', config('mail.mailers.smtp.scheme') ?: 'inferred from port'],
                ['Username', config('mail.mailers.smtp.username') ?: '(empty)'],
                ['Password', $password === ''
                    ? '(empty)'
                    : str_repeat('•', min(strlen($password), 24)).'  '.strlen($password).' characters'],
            ]);
        }

        $this->table(['Setting', 'Value'], $rows);
    }

    protected function body(string $mailer, string $recipient): string
    {
        return implode("\n", [
            'This is a test message from '.config('app.name').'.',
            '',
            'If you are reading it, outgoing mail works: account details and',
            'password reset links will reach their recipients.',
            '',
            'Sent to:   '.$recipient,
            'Mailer:    '.$mailer,
            'From host: '.config('app.url'),
            'Sent at:   '.now()->format('j F Y, g:ia'),
        ]);
    }

    protected function reportSuccess(string $mailer, string $recipient): int
    {
        if ($mailer === 'log') {
            // Not a failure, but not delivery either. Saying "sent" here would
            // be the most misleading thing this command could do — somebody
            // would take it as proof and find out at UAT that it was not.
            $this->warn('Handed to the "log" mailer — nothing was sent over the network.');
            $this->line('The message was written to storage/logs/laravel.log instead.');
            $this->newLine();
            $this->line('For real delivery set MAIL_MAILER=smtp in .env, fill in MAIL_USERNAME');
            $this->line('and a Google App Password in MAIL_PASSWORD, then run:');
            $this->newLine();
            $this->line('    php artisan config:clear && php artisan mail:test '.$recipient);

            return self::SUCCESS;
        }

        if ($mailer === 'array') {
            $this->warn('The "array" mailer keeps messages in memory. Nothing was sent.');

            return self::SUCCESS;
        }

        $this->info('Sent. The SMTP server accepted the message.');
        $this->line('Check '.$recipient.', including its spam folder — a first message from a');
        $this->line('new sender often lands there.');

        return self::SUCCESS;
    }

    /**
     * Reports what actually went wrong.
     *
     * Symfony's mailer wraps the transport error, so the useful line — the
     * server's own refusal — is usually in the previous exception rather than
     * the one thrown. Both are printed, then the few failures common enough to
     * name are translated out of SMTP-speak.
     */
    protected function reportFailure(Throwable $e): void
    {
        $this->newLine();
        $this->error('Not sent.');
        $this->newLine();

        $this->line('<fg=red>'.$e->getMessage().'</>');

        for ($previous = $e->getPrevious(); $previous !== null; $previous = $previous->getPrevious()) {
            $this->line('<fg=red>'.$previous->getMessage().'</>');
        }

        $this->newLine();

        foreach ($this->hintsFor($e) as $hint) {
            $this->line($hint);
        }
    }

    /** @return list<string> */
    protected function hintsFor(Throwable $e): array
    {
        $text = strtolower($e->getMessage().' '.($e->getPrevious()?->getMessage() ?? ''));

        return match (true) {
            str_contains($text, 'username and password not accepted'),
            str_contains($text, 'application-specific password'),
            str_contains($text, '535') => [
                'Gmail rejected the credentials.',
                '',
                'Almost always this means MAIL_PASSWORD holds the account password.',
                'Google stopped accepting those over SMTP in 2022. Generate an App',
                'Password instead: Google Account → Security → 2-Step Verification →',
                'App passwords. 2-Step Verification must be on first, or the App',
                'passwords screen does not appear at all.',
            ],

            str_contains($text, 'connection could not be established'),
            str_contains($text, 'connection timed out'),
            str_contains($text, 'connection refused') => [
                'Nothing answered on that host and port.',
                '',
                'Check MAIL_HOST is smtp.gmail.com, and that MAIL_PORT and MAIL_SCHEME',
                'agree: 587 with "smtp", or 465 with "smtps". A campus or office',
                'network blocking outbound SMTP looks exactly like this too — try the',
                'other port, or a different network, before suspecting the settings.',
            ],

            str_contains($text, 'ssl'), str_contains($text, 'tls'), str_contains($text, 'certificate') => [
                'The TLS handshake failed.',
                '',
                'Usually MAIL_PORT and MAIL_SCHEME disagree: 587 needs "smtp"',
                '(STARTTLS) and 465 needs "smtps" (implicit TLS). On XAMPP this can',
                'also mean PHP has no CA bundle — check that curl.cainfo and',
                'openssl.cafile are set in php.ini.',
            ],

            str_contains($text, 'must issue a starttls') => [
                'The server wants STARTTLS and did not get it.',
                '',
                'Set MAIL_SCHEME=smtp with MAIL_PORT=587.',
            ],

            default => [
                'Check the settings in the table above against .env, then run',
                'php artisan config:clear and try again — a cached config is the',
                'usual reason a corrected .env appears to change nothing.',
            ],
        };
    }
}
