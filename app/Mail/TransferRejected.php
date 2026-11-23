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

/** Sent when a club admin rejects a member's transfer slip, in the member's language (send it with Mail::to($user)). */
class TransferRejected extends Mailable
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
        // Built here, at send time: Laravel calls envelope() under the recipient's locale (not the constructor).
        return new Envelope(
            subject: __('Transfer slip needs another look – :club', ['club' => $this->payment->club->name]),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.transfer-rejected',
        );
    }
}
