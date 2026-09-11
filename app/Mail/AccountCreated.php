<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent once, when an administrator creates an account.
 *
 * Carries the temporary password because there is no other channel — the
 * account cannot be used until the holder signs in with it, and the plaintext
 * exists nowhere else after this point. That is a deliberate trade: the
 * password is single-use and EnsurePasswordChanged forces it to be replaced
 * at first sign-in, so its useful life is measured in one login.
 *
 * In development MAIL_MAILER=log writes this to storage/logs/laravel.log
 * rather than sending it, which is what makes it demonstrable without a mail
 * server.
 */
class AccountCreated extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $temporaryPassword,
        public ?string $createdBy = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your '.config('app.name').' account is ready',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.account-created',
            with: [
                'user' => $this->user,
                'temporaryPassword' => $this->temporaryPassword,
                'createdBy' => $this->createdBy,
                'loginUrl' => route('login'),
                'roleLabel' => match ($this->user->role) {
                    'admin' => 'Administrator',
                    'lecturer' => 'Lecturer',
                    default => 'Student',
                },
            ],
        );
    }
}
