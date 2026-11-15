<?php

namespace App\Services\PaymentGateways;

use App\Contracts\PaymentGateway;
use App\Models\Club;
use App\Support\Payments\GatewayFee;
use App\Support\Payments\PaymentBillResult;
use App\Support\Payments\WebhookResult;
use App\Support\Payments\WebhookSecret;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ToyyibPayGateway implements PaymentGateway
{
    /**
     * `billChargeToCustomer` sent to createBill for each club gateway_fee_payer.
     * This is the ONLY place the mapping lives.
     *
     * Our reading of the ToyyibPay createBill docs (not yet verified):
     *   ''  (blank) = bill owner pays the FPX and card charges  → 'club'
     *   '0'         = FPX charge to the customer, card to owner → 'member'
     *   '1'         = FPX charge to the owner, card to customer (the old hard-coded value)
     *   '2'         = both FPX and card charges to the customer
     *
     * TODO: confirm semantics in sandbox (go-live item P3): create one bill per
     * value and check what the FPX payer is shown and what the callback
     * `amount` contains. Until confirmed, clubs should keep payer = 'club'.
     * With 'member' we rely on this flag alone and never add the fee to
     * billAmount, so the member is not charged twice.
     */
    public const BILL_CHARGE_TO_CUSTOMER = [
        'club' => 1, // pre-P1 value, kept until the sandbox confirms how ToyyibPay reads blank vs 1
        'member' => '0',
    ];

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

        $fee = $params['fee'] ?? null;
        $payer = $fee instanceof GatewayFee ? $fee->payer : 'club';

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
                // Fee amount only: a member-borne charge comes from the flag below.
                'billChargeToCustomer' => self::BILL_CHARGE_TO_CUSTOMER[$payer] ?? self::BILL_CHARGE_TO_CUSTOMER['club'],
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
        if (! WebhookSecret::isUsable($this->webhookSecret)) {
            Log::error('ToyyibPay webhook denied: TOYYIBPAY_WEBHOOK_SECRET is missing or still the placeholder value');

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
            // ToyyibPay's callback `amount` is in ringgit (e.g. "10.00").
            paidAmountSen: isset($payload['amount']) && is_numeric($payload['amount']) ? (int) round(((float) $payload['amount']) * 100) : null,
        );
    }

    /**
     * Build the public payment URL for a bill code.
     */
    public function paymentUrl(string $billCode): string
    {
        return "{$this->baseUrl}/".rawurlencode($billCode);
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
