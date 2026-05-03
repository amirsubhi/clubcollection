<?php

namespace App\Services\PaymentGateways;

use App\Contracts\PaymentGateway;
use App\Models\Club;
use App\Models\Payment;
use App\Support\Payments\BillplzSignature;
use App\Support\Payments\PaymentBillResult;
use App\Support\Payments\WebhookResult;
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

        try {
            $response = Http::withBasicAuth($apiKey, '')
                ->asForm()
                ->post("{$this->baseUrl}/api/v3/bills", [
                    'collection_id' => $collectionId,
                    'email' => $params['payer_email'],
                    'mobile' => $params['payer_phone'] ?? null,
                    'name' => $params['payer_name'],
                    'amount' => (int) round($params['amount'] * 100),
                    'callback_url' => $params['callback_url'],
                    'redirect_url' => $params['return_url'],
                    'description' => $params['description'],
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

    public function verifyWebhookRequest(Request $request): bool
    {
        $key = $this->resolveSignatureKey($request);
        if (empty($key)) {
            Log::error('Billplz webhook denied: x_signature_key is not configured');

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
        );
    }

    /**
     * Pick the per-club signature key when the bill belongs to a club whose
     * id we can determine from `reference_1`. Otherwise use the global key.
     *
     * Note: Billplz delivers the signature key per-collection. The webhook
     * payload identifies the bill, not the club; we look up the payment to
     * find its club. Falling back to the global key keeps the global-config
     * deployment path working.
     *
     * This runs one DB query per webhook request before the signature is
     * verified — acceptable at this scale but worth noting if load increases.
     */
    protected function resolveSignatureKey(Request $request): string
    {
        $billCode = $request->input('id');
        $reference = $request->input('reference_1');

        $club = null;
        if (! empty($billCode) || ! empty($reference)) {
            $payment = Payment::query()
                ->when($billCode, fn ($q) => $q->where('bill_code', $billCode))
                ->when(! $billCode && $reference, fn ($q) => $q->where('reference', $reference))
                ->first();
            $club = $payment?->club;
        }

        if ($club instanceof Club && ! empty($club->billplz_x_signature_key)) {
            return (string) $club->billplz_x_signature_key;
        }

        return (string) $this->globalSignatureKey;
    }
}
