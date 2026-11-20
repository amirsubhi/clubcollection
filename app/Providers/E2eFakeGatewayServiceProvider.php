<?php

namespace App\Providers;

use App\Models\PaymentBill;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

/**
 * End-to-end smoke test support (tests/e2e/smoke.mjs). Does nothing unless
 * APP_ENV is exactly "local" AND the process environment has
 * E2E_FAKE_GATEWAY=1, so it can never activate in production.
 *
 * When active:
 *  - ToyyibPay bill creation (createBill) is answered by Http::fake with a
 *    random bill code, so no real gateway is called;
 *  - the callback confirmation (getBillTransactions) is answered with one
 *    successful transaction for the amount the bill was opened for, so the
 *    real confirm step still runs against the e2e bill. A bill counts as paid
 *    only once the smoke's (verified) webhook for it has asked: before that,
 *    the member's status-check requery (G1) sees it unpaid, so the smoke still
 *    settles through the webhook and the pending screens stay deterministic;
 *  - E2E_PRIVATE_ROOT (optional) moves the private "local" disk, so test
 *    uploads stay out of storage/app/private.
 */
class E2eFakeGatewayServiceProvider extends ServiceProvider
{
    public static function shouldActivate(string $environment, mixed $flag): bool
    {
        return $environment === 'local' && (string) $flag === '1';
    }

    public function boot(): void
    {
        if ($this->app->isProduction() || ! self::shouldActivate((string) $this->app->environment(), env('E2E_FAKE_GATEWAY'))) {
            return;
        }

        Http::fake([
            '*/index.php/api/createBill' => fn () => Http::response([['BillCode' => 'e2e'.Str::lower(Str::random(8))]]),
            '*/index.php/api/getBillTransactions' => function (Request $request) {
                $code = (string) ($request['billCode'] ?? '');
                $bill = PaymentBill::where('gateway', 'toyyibpay')->where('bill_code', $code)->first();
                $flag = 'e2e:toyyibpay:paid:'.$code;
                if ($bill && request()->is('webhook/*')) {
                    Cache::forever($flag, true);
                }

                return Http::response($bill && Cache::get($flag) ? [[
                    'billpaymentStatus' => '1',
                    'billpaymentAmount' => number_format($bill->amount_sen / 100, 2, '.', ''),
                ]] : []);
            },
        ]);

        $root = env('E2E_PRIVATE_ROOT');
        if (is_string($root) && $root !== '') {
            config(['filesystems.disks.local.root' => $root]);
        }
    }
}
