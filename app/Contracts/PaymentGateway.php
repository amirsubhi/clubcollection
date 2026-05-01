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
     *   reference_no, payer_name, payer_email, payer_phone (optional), club_name
     */
    public function createBill(array $params): ?PaymentBillResult;

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
