<?php

namespace App\Mail;

use App\Models\Club;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** The new member's sign-in details, in their language (User::preferredLocale(); send it with Mail::to($user)). */
class MemberWelcome extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User   $member,
        public Club   $club,
        public string $temporaryPassword,
    ) {}

    public function envelope(): Envelope
    {
        // Built here, at send time: Laravel calls envelope() under the recipient's locale (not the constructor).
        return new Envelope(
            subject: __('Welcome to :club — Your Account Details', ['club' => $this->club->name]),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.member-welcome',
        );
    }
}
