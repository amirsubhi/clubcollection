<?php

namespace App\Mail;

use App\Models\Payment;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** An overdue fee, in the member's language (User::preferredLocale(); send it with Mail::to($payment->user)). */
class OverdueReminder extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Payment $payment) {}

    public function envelope(): Envelope
    {
        // Built here, at send time: Laravel calls envelope() under the recipient's locale (not the constructor).
        return new Envelope(
            subject: __('Action Required: Overdue Club Fee — :club', ['club' => $this->payment->club->name]),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.overdue-reminder',
        );
    }
}
