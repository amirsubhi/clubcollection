<?php

namespace App\Services\PaymentGateways;

use App\Contracts\ConfirmsWebhookPayments;
use App\Contracts\PaymentGateway;
use App\Models\Club;
use App\Models\Payment;
use App\Models\PaymentBill;
use App\Support\Payments\GatewayConfirmation;
use App\Support\Payments\GatewayFee;
use App\Support\Payments\PaymentBillResult;
use App\Support\Payments\WebhookResult;
use App\Support\Payments\WebhookSecret;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ToyyibPayGateway implements ConfirmsWebhookPayments, PaymentGateway
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
        $this->webhookSecret = (string) config('toyyibpay.webhook_secret');
        $this->globalSecretKey = config('toyyibpay.secret_key');
        $this->globalCategoryCode = config('toyyibpay.category_code');
    }

    public function name(): string
    {
        return 'toyyibpay';
    }

    public function isConfiguredForClub(Club $club): bool
    {
        // Without a usable webhook secret no callback can ever be verified, so a
        // bill opened now could be paid but never settle (S1): refuse instead.
        if (! WebhookSecret::isUsable($this->webhookSecret)) {
            return false;
        }

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
            // Bounded, so the 15 s per-fee pay lock always outlives the call.
            $response = Http::asForm()->timeout(10)->post("{$this->baseUrl}/index.php/api/createBill", [
                'userSecretKey' => $secretKey,
                'categoryCode' => $categoryCode,
                'billName' => self::safeText((string) $params['bill_name'], 30),
                'billDescription' => self::safeText((string) $params['description'], 100),
                'billPriceSetting' => 1,
                'billPayorInfo' => 1,
                'billAmount' => (int) round($params['amount'] * 100),
                'billReturnUrl' => $params['return_url'],
                'billCallbackUrl' => $this->signedCallbackUrl($params['callback_url'], (string) $params['reference_no']),
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

    /**
     * The callback token is a per-bill HMAC of the bill's reference (S1), so a
     * token that leaks (logs, browser history) only ever works for that bill.
     * The reference comes from our own records when the fee is found; only an
     * unmatched callback is checked against the order_id it carries, so it
     * can be audited without letting anyone forge one.
     */
    public function verifyWebhookRequest(Request $request, ?Payment $payment, ?PaymentBill $bill = null): bool
    {
        if (! WebhookSecret::isUsable($this->webhookSecret)) {
            Log::error('ToyyibPay webhook denied: TOYYIBPAY_WEBHOOK_SECRET is missing, too short (min '.WebhookSecret::MIN_LENGTH.' characters) or still the placeholder value');

            return false;
        }

        $reference = $payment
            ? ($bill?->reference ?? $payment->reference)
            : $request->input('order_id');
        if (! is_string($reference) || $reference === '') {
            return false;
        }

        return hash_equals($this->callbackToken($reference), (string) $request->query('webhook_token', ''));
    }

    public function confirmsCallbacks(): bool
    {
        return (bool) config('toyyibpay.verify_callback', true);
    }

    /**
     * Ask ToyyibPay itself (getBillTransactions) whether the bill was paid,
     * and for how much. Any failure returns null: the caller does not settle
     * and answers non-200 so the gateway retries the callback later.
     */
    public function confirmPayment(string $billCode): ?GatewayConfirmation
    {
        try {
            $response = Http::asForm()->timeout(10)->post("{$this->baseUrl}/index.php/api/getBillTransactions", [
                'billCode' => $billCode,
                'billpaymentStatus' => '1',
            ]);
        } catch (\Throwable $e) {
            Log::warning('ToyyibPay getBillTransactions unreachable', ['bill_code' => $billCode, 'error' => $e->getMessage()]);

            return null;
        }

        $data = $response->successful() ? $response->json() : null;
        if (! is_array($data)) {
            Log::warning('ToyyibPay getBillTransactions gave no usable answer', ['bill_code' => $billCode, 'status' => $response->status()]);

            return null;
        }

        $best = 0;
        $count = 0;
        $txnId = null;
        foreach ($data as $txn) {
            if (! is_array($txn) || (string) ($txn['billpaymentStatus'] ?? '') !== '1') {
                continue;
            }
            $amount = $txn['billpaymentAmount'] ?? null;
            if (is_numeric($amount) && (float) $amount > 0) {
                $sen = (int) round(((float) $amount) * 100);
                if ($sen > $best) {
                    $best = $sen;
                    $invoice = $txn['billpaymentInvoiceNo'] ?? null;
                    $txnId = is_scalar($invoice) && (string) $invoice !== '' ? mb_substr((string) $invoice, 0, 191) : null;
                }
                $count++;
            }
        }

        return new GatewayConfirmation($best, $count, $txnId);
    }

    /**
     * ToyyibPay accepts only letters, digits, spaces and underscores in billName (max 30)
     * and billDescription (max 100); anything else can make createBill fail.
     */
    public static function safeText(string $text, int $max): string
    {
        $clean = trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^A-Za-z0-9 _]/', ' ', \Illuminate\Support\Str::ascii($text))));

        return rtrim(mb_substr($clean !== '' ? $clean : 'Club fee', 0, $max));
    }

    public function parseWebhook(Request $request): ?WebhookResult
    {
        $payload = $request->except(['webhook_token']);

        // The server callback posts `status` and `refno`; the return-URL query uses `status_id`
        // and some integrations send `transaction_id`. Accept both shapes: 1 = success.
        $isOne = fn ($v) => $v !== null && is_numeric($v) && (int) $v === 1;
        $paid = $isOne($payload['status'] ?? null) || $isOne($payload['status_id'] ?? null);
        $scalar = fn ($v) => is_scalar($v) && (string) $v !== '' ? (string) $v : null;
        $txn = $scalar($payload['refno'] ?? null) ?? $scalar($payload['transaction_id'] ?? null);

        return new WebhookResult(
            billCode: $scalar($payload['billcode'] ?? null),
            reference: $scalar($payload['order_id'] ?? null),
            paid: $paid,
            transactionId: ($txn !== null && $txn !== '') ? (string) $txn : null,
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

    /** The per-bill callback token: HMAC-SHA256 of the bill reference with the webhook secret. */
    public function callbackToken(string $reference): string
    {
        return hash_hmac('sha256', $reference, $this->webhookSecret);
    }

    /**
     * Append the bill's own token to the callback URL so the webhook handler
     * can verify the request with hash_equals().
     */
    protected function signedCallbackUrl(string $url, string $reference): string
    {
        if (! WebhookSecret::isUsable($this->webhookSecret) || $reference === '') {
            return $url;
        }
        $sep = str_contains($url, '?') ? '&' : '?';

        return $url.$sep.'webhook_token='.$this->callbackToken($reference);
    }
}
