<?php

namespace App\Mail;

use App\Models\Payment;
use App\Models\PaymentBill;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

class PaymentConfirmation extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  Collection<int, Payment>|null  $fees  every fee settled (or rejected) together ("Bayar semua"); null for one fee
     * @param  PaymentBill|null  $group  the "Bayar semua" bill or slip they came from
     */
    public function __construct(public Payment $payment, public ?Collection $fees = null, public ?PaymentBill $group = null)
    {
        // One fee on its own reads as a single payment, unless it came from a group.
        if ($this->fees !== null && $this->fees->count() < 2 && $this->group === null) {
            $this->fees = null;
        }
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Payment Confirmed – {$this->payment->club->name}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.payment-confirmation',
        );
    }
}
