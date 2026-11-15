<?php

namespace App\Contracts;

use App\Models\Club;
use App\Support\Payments\PaymentBillResult;
use App\Support\Payments\WebhookResult;
use Illuminate\Http\Request;

interface PaymentGateway
{
    /**
     * Driver name, e.g. 'toyyibpay' or 'billplz'.
     */
    public function name(): string;

    /**
     * Whether a bill can be created for the given club (per-club creds or
     * a usable global fallback).
     */
    public function isConfiguredForClub(Club $club): bool;

    /**
     * Create a bill at the upstream provider. Returns null on failure.
     *
     * Expected $params keys:
     *   club, bill_name, description, amount, return_url, callback_url,
     *   reference_no, payer_name, payer_email, payer_phone (optional), club_name,
     *   fee (optional App\Support\Payments\GatewayFee; absent = club absorbs)
     *
     * `amount` is always the fee amount without any gateway charge. When
     * fee->memberPays(), the gateway must make the member bear its charge
     * exactly once: by its own customer-charge flag, or by adding
     * fee->feeSen to the bill amount when it has no such flag.
     */
    public function createBill(array $params): ?PaymentBillResult;

    /**
     * Public payment page URL for a bill this gateway created earlier, so an
     * open bill can be reused instead of creating a second one.
     */
    public function paymentUrl(string $billCode): string;

    /**
     * Authenticate an incoming webhook request. Must fail closed when the
     * gateway's verification material (shared secret / signature key) is
     * not configured.
     */
    public function verifyWebhookRequest(Request $request): bool;

    /**
     * Parse a verified webhook request into a normalised result.
     */
    public function parseWebhook(Request $request): ?WebhookResult;
}
