<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ToyyibPayService
{
    protected string $secretKey;
    protected string $categoryCode;
    protected string $baseUrl;

    public function __construct()
    {
        $this->secretKey    = config('toyyibpay.secret_key');
        $this->categoryCode = config('toyyibpay.category_code');
        $this->baseUrl      = config('toyyibpay.base_url');
    }

    /**
     * Create a bill on ToyyibPay and return the bill code.
     */
    public function createBill(array $params): ?string
    {
        try {
            $response = Http::asForm()->post("{$this->baseUrl}/index.php/api/createBill", [
                'userSecretKey'     => $this->secretKey,
                'categoryCode'      => $this->categoryCode,
                'billName'          => $params['bill_name'],
                'billDescription'   => $params['description'],
                'billPriceSetting'  => 1,   // fixed price
                'billPayorInfo'     => 1,   // collect payer info
                'billAmount'        => (int) round($params['amount'] * 100), // in cents
                'billReturnUrl'     => $params['return_url'],
                'billCallbackUrl'   => $params['callback_url'],
                'billExternalReferenceNo' => $params['reference_no'],
                'billTo'            => $params['payer_name'],
                'billEmail'         => $params['payer_email'],
                'billPhone'         => $params['payer_phone'] ?? '0100000000',
                'billSplitPayment'  => 0,
                'billSplitPaymentArgs' => '',
                'billPaymentChannel' => 0, // all channels
                'billContentEmail'  => "Thank you for your payment to {$params['club_name']}.",
                'billChargeToCustomer' => 1, // charges borne by customer
            ]);

            $data = $response->json();

            if (!empty($data[0]['BillCode'])) {
                return $data[0]['BillCode'];
            }

            Log::error('ToyyibPay createBill failed', ['response' => $data]);
            return null;

        } catch (\Exception $e) {
            Log::error('ToyyibPay exception', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Build the payment URL from a bill code.
     */
    public function paymentUrl(string $billCode): string
    {
        return "{$this->baseUrl}/{$billCode}";
    }

    /**
     * Verify a callback payload from ToyyibPay.
     * Returns true if payment was successful.
     */
    public function verifyCallback(array $payload): bool
    {
        // ToyyibPay status: 1 = success, 2 = pending, 3 = failed
        return isset($payload['status_id']) && (int) $payload['status_id'] === 1;
    }

    public function isConfigured(): bool
    {
        return !empty($this->secretKey) && $this->secretKey !== 'your_secret_key_here'
            && !empty($this->categoryCode) && $this->categoryCode !== 'your_category_code_here';
    }
}
