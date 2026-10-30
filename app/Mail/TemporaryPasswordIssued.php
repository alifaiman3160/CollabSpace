<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent when an administrator resets somebody's password for them.
 *
 * This did not exist, and its absence was a real hole: resetting a password
 * invalidated the old one immediately and put the new one on the
 * administrator's screen and nowhere else. If they navigated away before
 * writing it down, the account was locked out with no way back except another
 * reset. Now the person whose account it is receives it directly, which is
 * also the only party who should ever have held it.
 *
 * Separate from AccountCreated rather than reusing it: that one welcomes
 * somebody to a new account, and telling an existing user their account "is
 * ready" when they have been using it all semester reads as a phishing attempt.
 */
class TemporaryPasswordIssued extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $temporaryPassword,
        public ?string $issuedBy = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your '.config('app.name').' password has been reset',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.temporary-password',
            with: [
                'user' => $this->user,
                'temporaryPassword' => $this->temporaryPassword,
                'issuedBy' => $this->issuedBy,
                'loginUrl' => route('login'),
            ],
        );
    }
}
