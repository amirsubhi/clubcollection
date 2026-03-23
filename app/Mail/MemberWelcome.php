<?php

namespace App\Mail;

use App\Models\Club;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

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
        return new Envelope(
            subject: "Welcome to {$this->club->name} — Your Account Details",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.member-welcome',
        );
    }
}
