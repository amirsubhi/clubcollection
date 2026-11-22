<?php

namespace App\Mail;

use App\Models\Payment;
use App\Models\PaymentBill;
use App\Models\PaymentCancellation;
use App\Services\Payments\PaymentCancellationService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

/**
 * Sent when an admin cancels a member's payment ("Cancel payment"), once per cancel (a set is one email). BM
 * first, then EN, like the other member emails. The wording is fixed per reason; the admin's note is internal
 * and never included.
 */
class PaymentCancelled extends Mailable
{
    use Queueable, SerializesModels;

    /** @var array{0: string, 1: string} BM, EN */
    public array $wording;

    /**
     * @param  Collection<int, Payment>  $fees  every fee cancelled together (one fee, or the set)
     * @param  PaymentCancellation  $cancellation  one row of the cancel (receipt number, reason)
     * @param  PaymentBill|null  $group  the "Bayar semua" bill or slip they were paid with
     */
    public function __construct(public Payment $payment, public Collection $fees, public PaymentCancellation $cancellation, public ?PaymentBill $group = null)
    {
        $this->wording = PaymentCancellationService::MEMBER_WORDING[$cancellation->reason_code]
            ?? PaymentCancellationService::MEMBER_WORDING['other'];
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Bayaran dibatalkan / Payment cancelled – {$this->payment->club->name}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.payment-cancelled',
        );
    }
}
