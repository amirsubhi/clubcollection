<?php

namespace App\Support\Payments;

final readonly class WebhookResult
{
    public function __construct(
        public ?string $billCode,
        public ?string $reference,
        public bool $paid,
        public ?string $transactionId,
        public string $gateway,
        public array $rawPayload,
        /** Amount the gateway says was paid, in sen, when the payload carries it. */
        public ?int $paidAmountSen = null,
    ) {}

    public function withPaidAmountSen(?int $sen): self
    {
        return new self($this->billCode, $this->reference, $this->paid, $this->transactionId, $this->gateway, $this->rawPayload, $sen);
    }

    /**
     * The only callback fields that may be written to the log (S1): never
     * the full payload, which can carry payer names, emails and phones.
     *
     * @return array<string, mixed>
     */
    public function logContext(): array
    {
        return [
            'gateway' => $this->gateway,
            'bill_code' => $this->billCode,
            'paid' => $this->paid,
            'amount_sen' => $this->paidAmountSen,
            'transaction_id' => $this->transactionId,
        ];
    }
}
