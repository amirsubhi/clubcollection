<?php

namespace App\Services\PaymentGateways;

use App\Contracts\PaymentGateway;
use App\Models\Club;
use App\Models\Payment;
use App\Models\PaymentBill;
use App\Support\Payments\BillplzSignature;
use App\Support\Payments\GatewayFee;
use App\Support\Payments\PaymentBillResult;
use App\Support\Payments\WebhookResult;
use App\Support\Payments\WebhookSecret;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BillplzGateway implements PaymentGateway
{
    protected string $baseUrl;

    protected string $globalApiKey;

    protected string $globalCollectionId;

    protected string $globalSignatureKey;

    public function __construct()
    {
        $this->baseUrl = config('billplz.base_url');
        $this->globalApiKey = config('billplz.api_key', '');
        $this->globalCollectionId = config('billplz.collection_id', '');
        $this->globalSignatureKey = config('billplz.x_signature_key', '');
    }

    public function name(): string
    {
        return 'billplz';
    }

    public function isConfiguredForClub(Club $club): bool
    {
        if ($club->hasBillplzCredentials()) {
            return true;
        }

        return ! empty($this->globalApiKey)
            && $this->globalApiKey !== 'your_billplz_api_key_here'
            && ! empty($this->globalCollectionId)
            && $this->globalCollectionId !== 'your_billplz_collection_id_here'
            && ! empty($this->globalSignatureKey)
            && $this->globalSignatureKey !== 'your_billplz_x_signature_key_here';
    }

    public function createBill(array $params): ?PaymentBillResult
    {
        $club = $params['club'] ?? null;
        $apiKey = ($club instanceof Club && $club->hasBillplzCredentials())
                            ? $club->billplz_api_key
                            : $this->globalApiKey;
        $collectionId = ($club instanceof Club && $club->hasBillplzCredentials())
                            ? $club->billplz_collection_id
                            : $this->globalCollectionId;

        // Billplz has no "charge the customer" flag: when the member bears the
        // gateway fee, add it to the bill amount explicitly.
        $fee = $params['fee'] ?? null;
        $amountSen = (int) round($params['amount'] * 100);
        $description = $params['description'];
        if ($fee instanceof GatewayFee && $fee->memberPays() && $fee->feeSen > 0) {
            $amountSen += $fee->feeSen;
            $description = mb_substr($description.' (incl. RM'.number_format($fee->feeSen / 100, 2).' gateway charge)', 0, 200);
        }

        try {
            // Bounded, so the 15 s per-fee pay lock always outlives the call.
            $response = Http::withBasicAuth($apiKey, '')
                ->asForm()
                ->timeout(10)
                ->post("{$this->baseUrl}/api/v3/bills", [
                    'collection_id' => $collectionId,
                    'email' => $params['payer_email'],
                    'mobile' => $params['payer_phone'] ?? null,
                    'name' => $params['payer_name'],
                    'amount' => $amountSen,
                    'callback_url' => $params['callback_url'],
                    'redirect_url' => $params['return_url'],
                    'description' => $description,
                    'reference_1_label' => 'PaymentRef',
                    'reference_1' => $params['reference_no'],
                ]);

            $data = $response->json() ?? [];

            if (! empty($data['id']) && ! empty($data['url'])) {
                return new PaymentBillResult(
                    billCode: (string) $data['id'],
                    paymentUrl: (string) $data['url'],
                    gateway: $this->name(),
                );
            }

            Log::error('Billplz createBill failed', ['response' => $data, 'club_id' => $club?->id]);

            return null;
        } catch (\Exception $e) {
            Log::error('Billplz exception', ['error' => $e->getMessage(), 'club_id' => $club?->id]);

            return null;
        }
    }

    public function paymentUrl(string $billCode): string
    {
        return rtrim($this->baseUrl, '/').'/bills/'.rawurlencode($billCode);
    }

    public function verifyWebhookRequest(Request $request, ?Payment $payment, ?PaymentBill $bill = null): bool
    {
        $key = $this->signatureKeyFor($payment);
        // The key is issued by Billplz in its own format: only blanks and
        // placeholders are refused, not short keys.
        if (! WebhookSecret::isUsable($key, 1)) {
            Log::error('Billplz webhook denied: x_signature_key is missing or still the placeholder value');

            return false;
        }

        $payload = $request->all();
        $supplied = isset($payload['x_signature']) ? (string) $payload['x_signature'] : '';
        if ($supplied === '') {
            return false;
        }

        $expected = BillplzSignature::compute($payload, $key);

        return hash_equals($expected, $supplied);
    }

    public function parseWebhook(Request $request): ?WebhookResult
    {
        $billCode = $request->input('id');
        $reference = $request->input('reference_1');
        $paid = $request->input('paid') === 'true'
                  || $request->input('paid') === true
                  || $request->input('state') === 'paid';

        $payload = $request->except(['x_signature']);

        return new WebhookResult(
            billCode: $billCode !== null ? (string) $billCode : null,
            reference: $reference !== null ? (string) $reference : null,
            paid: (bool) $paid,
            transactionId: $request->input('transaction_id'),
            gateway: $this->name(),
            rawPayload: $payload,
            paidAmountSen: is_numeric($request->input('paid_amount')) ? (int) $request->input('paid_amount') : null,
        );
    }

    /**
     * The club's own signature key when the fee belongs to a club with complete
     * Billplz credentials (S1: a stray key on a club still using the global
     * collection is ignored); otherwise the global key. The webhook controller
     * looks the fee up from the bill id / reference_1 before verifying.
     */
    protected function signatureKeyFor(?Payment $payment): string
    {
        $club = $payment?->club;

        if ($club instanceof Club && $club->hasBillplzCredentials()) {
            return (string) $club->billplz_x_signature_key;
        }

        return (string) $this->globalSignatureKey;
    }
}
