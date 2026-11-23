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
 * Sent when an admin cancels a member's payment ("Cancel payment"), once per cancel (a set is one email), in the
 * member's language (User::preferredLocale(); send it with Mail::to($user)). The wording is fixed per reason (the
 * English of PaymentCancellationService::MEMBER_WORDING, translated by lang/<code>.json at send time); the admin's
 * note is internal and never included.
 */
class PaymentCancelled extends Mailable
{
    use Queueable, SerializesModels;

    /** The reason in English: a translation key, turned into the member's language in the template. */
    public string $reasonText;

    /** Every fee went back to its transfer slip still being checked (an online payment made meanwhile was cancelled). */
    public bool $slipPending;

    /**
     * @param  Collection<int, Payment>  $fees  every fee cancelled together (one fee, or the set)
     * @param  PaymentCancellation  $cancellation  one row of the cancel (receipt number, reason)
     * @param  PaymentBill|null  $group  the "Bayar semua" bill or slip they were paid with
     */
    public function __construct(public Payment $payment, public Collection $fees, public PaymentCancellation $cancellation, public ?PaymentBill $group = null)
    {
        // Only the English key here: the constructor runs before the recipient's locale is set.
        $this->reasonText = PaymentCancellationService::MEMBER_WORDING[$cancellation->reason_code]
            ?? PaymentCancellationService::MEMBER_WORDING['other'];
        $this->slipPending = $fees->isNotEmpty() && $fees->every(fn (Payment $f) => $f->status === 'pending_verification');
    }

    public function envelope(): Envelope
    {
        // Built here, at send time: Laravel calls envelope() under the recipient's locale (not the constructor).
        return new Envelope(
            subject: __('Payment cancelled – :club', ['club' => $this->payment->club->name]),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.payment-cancelled',
        );
    }
}
