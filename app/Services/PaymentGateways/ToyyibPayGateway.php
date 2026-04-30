<?php

namespace App\Services\PaymentGateways;

use App\Contracts\PaymentGateway;
use App\Models\Club;
use App\Support\Payments\PaymentBillResult;
use App\Support\Payments\WebhookResult;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ToyyibPayGateway implements PaymentGateway
{
    protected string $baseUrl;

    protected string $webhookSecret;

    protected string $globalSecretKey;

    protected string $globalCategoryCode;

    public function __construct()
    {
        $this->baseUrl = config('toyyibpay.base_url');
        $this->webhookSecret = config('toyyibpay.webhook_secret');
        $this->globalSecretKey = config('toyyibpay.secret_key');
        $this->globalCategoryCode = config('toyyibpay.category_code');
    }

    public function name(): string
    {
        return 'toyyibpay';
    }

    public function isConfiguredForClub(Club $club): bool
    {
        if ($club->hasToyyibPayCredentials()) {
            return true;
        }

        return ! empty($this->globalSecretKey)
            && $this->globalSecretKey !== 'your_secret_key_here'
            && ! empty($this->globalCategoryCode)
            && $this->globalCategoryCode !== 'your_category_code_here';
    }

    public function createBill(array $params): ?PaymentBillResult
    {
        $club = $params['club'] ?? null;
        $secretKey = ($club instanceof Club && $club->hasToyyibPayCredentials())
                            ? $club->toyyibpay_secret_key
                            : $this->globalSecretKey;
        $categoryCode = ($club instanceof Club && $club->hasToyyibPayCredentials())
                            ? $club->toyyibpay_category_code
                            : $this->globalCategoryCode;

        try {
            $response = Http::asForm()->post("{$this->baseUrl}/index.php/api/createBill", [
                'userSecretKey' => $secretKey,
                'categoryCode' => $categoryCode,
                'billName' => $params['bill_name'],
                'billDescription' => $params['description'],
                'billPriceSetting' => 1,
                'billPayorInfo' => 1,
                'billAmount' => (int) round($params['amount'] * 100),
                'billReturnUrl' => $params['return_url'],
                'billCallbackUrl' => $this->signedCallbackUrl($params['callback_url']),
                'billExternalReferenceNo' => $params['reference_no'],
                'billTo' => $params['payer_name'],
                'billEmail' => $params['payer_email'],
                'billPhone' => $params['payer_phone'] ?? '0100000000',
                'billSplitPayment' => 0,
                'billSplitPaymentArgs' => '',
                'billPaymentChannel' => 0,
                'billContentEmail' => "Thank you for your payment to {$params['club_name']}.",
                'billChargeToCustomer' => 1,
            ]);

            $data = $response->json();

            if (! empty($data[0]['BillCode'])) {
                $billCode = $data[0]['BillCode'];

                return new PaymentBillResult(
                    billCode: $billCode,
                    paymentUrl: $this->paymentUrl($billCode),
                    gateway: $this->name(),
                );
            }

            Log::error('ToyyibPay createBill failed', ['response' => $data, 'club_id' => $club?->id]);

            return null;
        } catch (\Exception $e) {
            Log::error('ToyyibPay exception', ['error' => $e->getMessage(), 'club_id' => $club?->id]);

            return null;
        }
    }

    public function verifyWebhookRequest(Request $request): bool
    {
        if (empty($this->webhookSecret)) {
            Log::error('ToyyibPay webhook denied: TOYYIBPAY_WEBHOOK_SECRET is not configured');

            return false;
        }

        return hash_equals($this->webhookSecret, (string) $request->query('webhook_token', ''));
    }

    public function parseWebhook(Request $request): ?WebhookResult
    {
        $payload = $request->except(['webhook_token']);

        $paid = isset($payload['status_id']) && (int) $payload['status_id'] === 1;

        return new WebhookResult(
            billCode: ! empty($payload['billcode']) ? (string) $payload['billcode'] : null,
            reference: ! empty($payload['order_id']) ? (string) $payload['order_id'] : null,
            paid: $paid,
            transactionId: $payload['transaction_id'] ?? null,
            gateway: $this->name(),
            rawPayload: $payload,
        );
    }

    /**
     * Build the public payment URL for a bill code.
     */
    protected function paymentUrl(string $billCode): string
    {
        return "{$this->baseUrl}/{$billCode}";
    }

    /**
     * Append the shared secret token to the callback URL so the webhook
     * handler can verify the request with hash_equals().
     */
    protected function signedCallbackUrl(string $url): string
    {
        if (empty($this->webhookSecret)) {
            return $url;
        }
        $sep = str_contains($url, '?') ? '&' : '?';

        return $url.$sep.'webhook_token='.urlencode($this->webhookSecret);
    }
}
