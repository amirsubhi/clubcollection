<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Tells each super admin how an update ended, with the same words as the Updates page (spec J1, J4, J5) and the report.
 * Sent synchronously, never queued: with QUEUE_CONNECTION=database there may be no worker, and the sender (the first
 * request after the update) already treats a failed send as best effort. English only: admin pages and emails are.
 */
class PortalUpdateResult extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array{subject: string, title: string, lines: list<string>, what_happened: ?string, what_to_do: ?string}  $text  UpdateOutcomes::describe()
     */
    public function __construct(public array $text, public string $report = '') {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->text['subject']);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.portal-update-result');
    }
}
