<?php

namespace App\Services;

use App\Models\Club;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ToyyibPayService
{
    protected string $baseUrl;
    protected string $webhookSecret;

    // Global fallback credentials from .env
    protected string $globalSecretKey;
    protected string $globalCategoryCode;

    public function __construct()
    {
        $this->baseUrl            = config('toyyibpay.base_url');
        $this->webhookSecret      = config('toyyibpay.webhook_secret');
        $this->globalSecretKey    = config('toyyibpay.secret_key');
        $this->globalCategoryCode = config('toyyibpay.category_code');
    }

    /**
     * Create a bill on ToyyibPay using the club's own credentials.
     * Falls back to global .env credentials if the club has none.
     */
    public function createBill(array $params): ?string
    {
        $club         = $params['club'];
        $secretKey    = ($club instanceof Club && $club->hasToyyibPayCredentials())
                            ? $club->toyyibpay_secret_key
                            : $this->globalSecretKey;
        $categoryCode = ($club instanceof Club && $club->hasToyyibPayCredentials())
                            ? $club->toyyibpay_category_code
                            : $this->globalCategoryCode;

        try {
            $response = Http::asForm()->post("{$this->baseUrl}/index.php/api/createBill", [
                'userSecretKey'          => $secretKey,
                'categoryCode'           => $categoryCode,
                'billName'               => $params['bill_name'],
                'billDescription'        => $params['description'],
                'billPriceSetting'       => 1,   // fixed price
                'billPayorInfo'          => 1,   // collect payer info
                'billAmount'             => (int) round($params['amount'] * 100), // in cents
                'billReturnUrl'          => $params['return_url'],
                'billCallbackUrl'        => $this->signedCallbackUrl($params['callback_url']),
                'billExternalReferenceNo' => $params['reference_no'],
                'billTo'                 => $params['payer_name'],
                'billEmail'              => $params['payer_email'],
                'billPhone'              => $params['payer_phone'] ?? '0100000000',
                'billSplitPayment'       => 0,
                'billSplitPaymentArgs'   => '',
                'billPaymentChannel'     => 0,   // all channels
                'billContentEmail'       => "Thank you for your payment to {$params['club_name']}.",
                'billChargeToCustomer'   => 1,   // charges borne by customer
            ]);

            $data = $response->json();

            if (!empty($data[0]['BillCode'])) {
                return $data[0]['BillCode'];
            }

            Log::error('ToyyibPay createBill failed', ['response' => $data, 'club_id' => $club?->id]);
            return null;

        } catch (\Exception $e) {
            Log::error('ToyyibPay exception', ['error' => $e->getMessage(), 'club_id' => $club?->id]);
            return null;
        }
    }

    /**
     * Returns true if a bill can be created for the given club.
     * Prefers the club's own credentials; falls back to global config.
     */
    public function isConfiguredForClub(Club $club): bool
    {
        if ($club->hasToyyibPayCredentials()) {
            return true;
        }

        // Fall back to global credentials
        return !empty($this->globalSecretKey)
            && $this->globalSecretKey !== 'your_secret_key_here'
            && !empty($this->globalCategoryCode)
            && $this->globalCategoryCode !== 'your_category_code_here';
    }

    /**
     * Build the payment URL from a bill code.
     */
    public function paymentUrl(string $billCode): string
    {
        return "{$this->baseUrl}/{$billCode}";
    }

    /**
     * Append a shared secret token to the callback URL.
     * The webhook controller will verify this token using hash_equals().
     */
    public function signedCallbackUrl(string $url): string
    {
        if (empty($this->webhookSecret)) {
            return $url;
        }
        $sep = str_contains($url, '?') ? '&' : '?';
        return $url . $sep . 'webhook_token=' . urlencode($this->webhookSecret);
    }

    /**
     * Verify the shared secret from the webhook request.
     */
    public function verifyWebhookSecret(string $token): bool
    {
        if (empty($this->webhookSecret)) {
            return true; // Not configured — allow but log warning
        }
        return hash_equals($this->webhookSecret, $token);
    }

    /**
     * Verify a callback payload from ToyyibPay.
     * Returns true if payment was successful (status_id = 1).
     */
    public function verifyCallback(array $payload): bool
    {
        return isset($payload['status_id']) && (int) $payload['status_id'] === 1;
    }
}
