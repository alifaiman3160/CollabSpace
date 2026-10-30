<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The password reset link.
 *
 * Replaces Laravel's built-in ResetPassword notification, which arrives as
 * "Hello!" from an unnamed application and reads like scaffolding — fine for a
 * fresh install, wrong for the only email a locked-out student will judge as
 * genuine or not. Phishing is the reason this matters: a reset link that does
 * not look like it came from the system it claims to is one people are right
 * to distrust, and one they will click anyway if the real thing looks the same.
 *
 * Rendered through the same markdown mail layout as AccountCreated, so both
 * carry identical branding.
 */
class PasswordResetLink extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $resetUrl,
        public int $expiresInMinutes,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Reset your '.config('app.name').' password',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.reset-password',
            with: [
                'user' => $this->user,
                'resetUrl' => $this->resetUrl,
                'expiresInMinutes' => $this->expiresInMinutes,
            ],
        );
    }
}
