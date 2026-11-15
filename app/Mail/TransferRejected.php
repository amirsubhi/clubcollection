<?php

namespace App\Mail;

use App\Models\Payment;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Sent when a club admin rejects a member's transfer slip (BM and EN in one email). */
class TransferRejected extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Payment $payment) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Slip pindahan perlu dihantar semula / Transfer slip needs another look – {$this->payment->club->name}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.transfer-rejected',
        );
    }
}
