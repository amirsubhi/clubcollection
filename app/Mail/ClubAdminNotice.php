<?php

namespace App\Mail;

use App\Models\Club;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A short security notice to every admin of a club (S1): a new club admin was
 * granted, or the club's bank / DuitNow details were changed or approved.
 * Admins who did not expect it should check the audit log.
 */
class ClubAdminNotice extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  list<string>  $lines  plain-text paragraphs
     */
    public function __construct(
        public Club $club,
        public string $heading,
        public array $lines,
        public ?string $actionUrl = null,
        public ?string $actionLabel = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "{$this->heading} – {$this->club->name}");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.club-admin-notice');
    }
}
