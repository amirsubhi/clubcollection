<?php

namespace App\Console\Commands;

use App\Models\Club;
use App\Models\Payment;
use App\Models\PaymentBill;
use App\Services\PaymentGateways\PaymentGatewayManager;
use App\Services\PaymentGateways\ToyyibPayGateway;
use App\Services\Payments\FeeBillService;
use App\Support\Payments\GatewayFee;
use App\Support\Payments\PaymentBillResult;
use App\Support\Payments\WebhookSecret;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * ToyyibPay sandbox test helper (docs/SANDBOX-TEST.md). Talks only to the
 * sandbox (dev.toyyibpay.com) and refuses to run when TOYYIBPAY_SANDBOX is
 * false. Never prints the secret key or a callback token.
 */
#[Signature('toyyibpay:sandbox-check
    {--create : Create a sandbox bill (RM 1.00 unless --amount is given)}
    {--amount=1.00 : Bill amount in ringgit, for --create without --payment}
    {--payer=club : Who bears the ToyyibPay charge on the test bill: club or member (billChargeToCustomer)}
    {--club= : Club id whose ToyyibPay keys to use (default: the keys in .env)}
    {--email=sandbox-test@example.com : Payer email sent with the test bill}
    {--payment= : Open the bill for this demo fee (id), the same way as the member\'s Pay button (always a new bill; skips the active-member checks)}
    {--bill= : Ask getBillTransactions about this bill code and show how the app reads the answer}
    {--all : With --bill, also list the bill\'s transactions of every status}')]
#[Description('ToyyibPay sandbox test: create a bill and check getBillTransactions (sandbox only)')]
class ToyyibPaySandboxCheck extends Command
{
    /** Keys whose values are payer details: hidden in printed requests and answers (billContentEmail is our own text). */
    private const PAYER_KEYS = '/((?<!content)email|phone|^billTo$|payorname|customername)/i';

    /** @var array<int, array{url: string, method: string, data: array<string, mixed>}> */
    private array $sent = [];

    /** @var array<int, array{url: string, status: int, body: string}> */
    private array $received = [];

    public function handle(PaymentGatewayManager $gateways): int
    {
        if (! config('toyyibpay.sandbox')) {
            $this->error('Refused: TOYYIBPAY_SANDBOX is false, so the app is in LIVE mode (real money).');
            $this->line('This command only talks to the ToyyibPay sandbox. On a staging site set TOYYIBPAY_SANDBOX=true and run php artisan config:cache.');

            return self::FAILURE;
        }
        $baseUrl = (string) config('toyyibpay.base_url');
        if (parse_url($baseUrl, PHP_URL_HOST) !== 'dev.toyyibpay.com') {
            $this->error("Refused: the ToyyibPay address is {$baseUrl}, not the sandbox (https://dev.toyyibpay.com). Run php artisan config:clear and try again.");

            return self::FAILURE;
        }

        $this->printSettings();

        $this->captureHttp();
        /** @var ToyyibPayGateway $gateway */
        $gateway = $gateways->driver('toyyibpay');

        if (! $this->option('create') && ! $this->option('bill')) {
            $this->newLine();
            $this->line('Nothing else to do. Use --create to make a sandbox bill, or --bill=<bill code> to check one.');

            return self::SUCCESS;
        }

        $ok = true;
        if ($this->option('create')) {
            $ok = $this->create($gateway) && $ok;
        }
        if ($this->option('bill')) {
            $ok = $this->checkBill($gateway, (string) $this->option('bill')) && $ok;
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    private function printSettings(): void
    {
        $env = (string) app()->environment();
        $this->info('ToyyibPay sandbox check');
        $this->table(['Setting', 'Value'], [
            ['ToyyibPay address', (string) config('toyyibpay.base_url').'  (sandbox)'],
            ['APP_ENV', $env],
            ['APP_URL (callbacks go to)', route('webhook.toyyibpay')],
            ['Global secret key (.env)', $this->usableKey((string) config('toyyibpay.secret_key'), 'your_secret_key_here') ? 'set (hidden)' : 'not set'],
            ['Global category code (.env)', $this->usableKey((string) config('toyyibpay.category_code'), 'your_category_code_here') ? (string) config('toyyibpay.category_code') : 'not set'],
            ['Webhook secret', WebhookSecret::isUsable((string) config('toyyibpay.webhook_secret')) ? 'set (hidden)' : 'MISSING or too short: callbacks are refused and no bill can be opened'],
            ['TOYYIBPAY_VERIFY_CALLBACK', config('toyyibpay.verify_callback') ? 'true' : 'false'],
            ['TOYYIBPAY_CHARGE_VERIFIED', config('toyyibpay.charge_to_customer_verified') ? 'true' : 'false'],
            ['Callback fields in audit log', config('toyyibpay.log_callbacks') ? 'on (TOYYIBPAY_LOG_CALLBACKS)' : 'off: set TOYYIBPAY_LOG_CALLBACKS=true'],
        ]);
        if ($env === 'production') {
            $this->warn('APP_ENV is production while TOYYIBPAY_SANDBOX=true: no real payment can be taken on this site.');
        }
    }

    private function usableKey(string $value, string $placeholder): bool
    {
        return $value !== '' && $value !== $placeholder;
    }

    /** Record what the HTTP client sends to and receives from ToyyibPay. */
    private function captureHttp(): void
    {
        Event::listen(RequestSending::class, function (RequestSending $e) {
            if (str_contains($e->request->url(), 'toyyibpay.com')) {
                $this->sent[] = ['url' => $e->request->url(), 'method' => $e->request->method(), 'data' => (array) $e->request->data()];
            }
        });
        Event::listen(ResponseReceived::class, function (ResponseReceived $e) {
            if (str_contains($e->request->url(), 'toyyibpay.com')) {
                $this->received[] = ['url' => $e->request->url(), 'status' => $e->response->status(), 'body' => $e->response->body()];
            }
        });
    }

    // ── --create ────────────────────────────────────────────────────────────

    private function create(ToyyibPayGateway $gateway): bool
    {
        $this->newLine();
        $this->info('Creating a sandbox bill (createBill)');
        [$sentBefore, $receivedBefore] = [count($this->sent), count($this->received)];

        $result = $this->option('payment') !== null
            ? $this->createForPayment($gateway, (string) $this->option('payment'))
            : $this->createStandalone($gateway);
        if ($result === false) {
            return false; // refused before anything was sent
        }

        $this->printExchange($sentBefore, $receivedBefore, '/api/createBill');

        if (! $result instanceof PaymentBillResult) {
            $this->error('createBill FAILED: no BillCode in the answer above. Copy the answer back to the developers.');

            return false;
        }

        $this->newLine();
        $this->table(['Result', 'Value'], [
            ['Bill code', $result->billCode],
            ['Payment page', $result->paymentUrl],
        ]);
        $this->line('Open the payment page, pay with the sandbox bank simulator, then run:');
        $this->line("  php artisan toyyibpay:sandbox-check --bill={$result->billCode}");

        return true;
    }

    private function createStandalone(ToyyibPayGateway $gateway): PaymentBillResult|false|null
    {
        $amount = (string) $this->option('amount');
        if (! is_numeric($amount) || (float) $amount < 1 || (float) $amount > 100) {
            $this->error('--amount must be a number from 1.00 to 100.00 (ringgit).');

            return false;
        }
        $payer = (string) $this->option('payer');
        if (! array_key_exists($payer, ToyyibPayGateway::BILL_CHARGE_TO_CUSTOMER)) {
            $this->error('--payer must be club or member.');

            return false;
        }

        $club = null;
        if ($this->option('club') !== null) {
            $club = Club::find((int) $this->option('club'));
            if (! $club) {
                $this->error('No club with id '.$this->option('club').'.');

                return false;
            }
        }
        if (! $gateway->isConfiguredForClub($club ?? new Club)) {
            $this->error($club
                ? "Club #{$club->id} has no usable ToyyibPay keys (its own or the global ones), or the webhook secret is missing."
                : 'No usable ToyyibPay keys in .env (TOYYIBPAY_SECRET_KEY, TOYYIBPAY_CATEGORY_CODE), or the webhook secret is missing.');

            return false;
        }

        $clubName = $club?->name ?? (string) config('app.name');
        $this->line('Not linked to any fee: paying it gives a callback that matches no payment (audit log: "Payment Unmatched Webhook").');
        $this->line('Keys: '.($club?->hasToyyibPayCredentials() ? "club #{$club->id}'s own" : 'global (.env)').". Charge borne by: {$payer}.");

        // Same bill name and description format as a member's real bill.
        $month = now()->format('M Y');

        return $gateway->createBill([
            'club' => $club,
            'bill_name' => "Club Fee – {$clubName}",
            'description' => "Payment for {$month} to {$month}",
            'amount' => round((float) $amount, 2),
            'return_url' => url('/'),
            'callback_url' => route('webhook.toyyibpay'),
            'reference_no' => 'SANDBOX-'.now()->format('YmdHis').'-'.Str::upper(Str::random(4)),
            'payer_name' => 'Sandbox Tester',
            'payer_email' => (string) $this->option('email'),
            'club_name' => $clubName,
            'fee' => new GatewayFee($payer, 0),
        ]);
    }

    private function createForPayment(ToyyibPayGateway $gateway, string $id): PaymentBillResult|false|null
    {
        $payment = Payment::with(['club', 'user'])->find((int) $id);
        if (! $payment) {
            $this->error("No fee with id {$id}.");

            return false;
        }
        if ($payment->club->activeGateway() !== 'toyyibpay') {
            $this->error("Fee #{$payment->id}'s club uses {$payment->club->activeGateway()}, not ToyyibPay.");

            return false;
        }
        if ($payment->status === 'paid' || $payment->isVerifying()) {
            $this->error("Fee #{$payment->id} is {$payment->status}: pick an unpaid demo fee.");

            return false;
        }
        if (! $gateway->isConfiguredForClub($payment->club)) {
            $this->error("Club #{$payment->club_id} has no usable ToyyibPay keys, or the webhook secret is missing.");

            return false;
        }
        if ($this->option('amount') !== '1.00' || $this->option('payer') !== 'club') {
            $this->warn('--amount and --payer are ignored with --payment: the fee amount and the club\'s billing settings are used.');
        }

        $fee = GatewayFee::forClub($payment->club);
        $this->line("Fee #{$payment->id} (member #{$payment->user_id}): RM ".number_format((float) $payment->amount, 2).", charge borne by: {$fee->payer}. The bill is saved on the fee, like the member's Pay button.");

        $lock = Cache::lock("pay:{$payment->id}", 15);
        if (! $lock->get()) {
            $this->error('Someone is opening a bill for this fee right now. Try again in a few seconds.');

            return false;
        }
        try {
            return app(FeeBillService::class)->open($payment->fresh(['club', 'user']), $gateway, $payment->user);
        } finally {
            $lock->release();
        }
    }

    // ── --bill ──────────────────────────────────────────────────────────────

    private function checkBill(ToyyibPayGateway $gateway, string $billCode): bool
    {
        $this->newLine();
        $this->info("Checking bill {$billCode} (getBillTransactions, the app's confirm step)");
        [$sentBefore, $receivedBefore] = [count($this->sent), count($this->received)];

        $confirmation = $gateway->confirmPayment($billCode);
        $this->printExchange($sentBefore, $receivedBefore, '/api/getBillTransactions');
        $raw = collect($this->received)->slice($receivedBefore)->last();

        if ($confirmation === null) {
            $this->error('The app could NOT read an answer (unreachable, error or not a JSON list). A paid callback would get 503 and wait for a retry.');

            return false;
        }

        $rows = [];
        $data = $raw ? json_decode($raw['body'], true) : null;
        foreach (is_array($data) ? $data : [] as $i => $txn) {
            if (! is_array($txn)) {
                $rows[] = [$i, '(not an object)', '', '', 'no'];

                continue;
            }
            $status = (string) ($txn['billpaymentStatus'] ?? '(missing)');
            $amount = $txn['billpaymentAmount'] ?? null;
            $sen = is_numeric($amount) ? (int) round(((float) $amount) * 100) : null;
            $counted = $status === '1' && $sen !== null && $sen > 0;
            $rows[] = [$i, $status, $amount === null ? '(missing)' : (string) $amount, $sen === null ? '-' : $sen.' sen (RM '.number_format($sen / 100, 2).')', $counted ? 'yes' : 'no'];
        }

        $this->newLine();
        $this->line('Each transaction as the app reads it (billpaymentAmount is taken as RINGGIT):');
        $this->table(['#', 'billpaymentStatus', 'billpaymentAmount (raw)', 'Read as', 'Counted as paid'], $rows ?: [['-', '-', '-', '-', '-']]);

        $bill = PaymentBill::where('gateway', 'toyyibpay')->where('bill_code', $billCode)->latest('id')->first();
        $summary = [
            ['Paid?', $confirmation->paid() ? 'YES' : 'NO'],
            ['Largest successful amount', $confirmation->amountSen.' sen (RM '.number_format($confirmation->amountSen / 100, 2).')'],
            ['Successful transactions', (string) $confirmation->successCount],
        ];
        if ($bill) {
            $expected = (int) $bill->amount_sen;
            $summary[] = ['Fee amount on our bill', $expected.' sen (RM '.number_format($expected / 100, 2).")  fee #{$bill->payment_id}"];
            $summary[] = ['Charge borne by (our bill)', (string) $bill->fee_payer.($bill->fee_payer === 'member' ? ' (configured fee RM '.number_format(((int) $bill->fee_sen) / 100, 2).')' : '')];
            $summary[] = ['Would settle the fee?', ! $confirmation->paid() ? 'no (not paid yet)' : ($confirmation->amountSen >= $expected ? 'yes (amount covers the fee)' : 'NO: amount below the fee (unit mismatch?)')];
        } else {
            $summary[] = ['Our records', 'no fee has this bill code (a --create test bill without --payment)'];
        }
        $this->newLine();
        $this->table(['App reads this as', ''], $summary);

        if ($this->option('all')) {
            $this->newLine();
            $this->info('All transactions of the bill (every status)');
            [$sentBefore, $receivedBefore] = [count($this->sent), count($this->received)];
            try {
                Http::asForm()->timeout(10)->post(config('toyyibpay.base_url').'/index.php/api/getBillTransactions', ['billCode' => $billCode]);
            } catch (\Throwable $e) {
                $this->error('Request failed: '.$e->getMessage());
            }
            $this->printExchange($sentBefore, $receivedBefore, '/api/getBillTransactions');
            $this->line('billpaymentStatus: 1 = successful, 2 = pending, 3 = unsuccessful (per the ToyyibPay docs).');
        }

        return true;
    }

    // ── output helpers ──────────────────────────────────────────────────────

    private function printExchange(int $sentFrom, int $receivedFrom, string $path): void
    {
        foreach (array_slice($this->sent, $sentFrom) as $req) {
            if (! str_contains($req['url'], $path)) {
                continue;
            }
            $this->newLine();
            $this->line("Request sent: {$req['method']} {$req['url']}");
            $this->table(['Field', 'Value'], collect($this->maskRequest($req['data']))
                ->map(fn ($v, $k) => [$k, is_scalar($v) || $v === null ? var_export($v, true) : json_encode($v)])->values()->all());
            if (str_contains($path, 'createBill')) {
                $this->warnAgainstDocs($req['data']);
            }
        }
        foreach (array_slice($this->received, $receivedFrom) as $res) {
            if (! str_contains($res['url'], $path)) {
                continue;
            }
            $this->line("Raw answer (HTTP {$res['status']}; payer name, email and phone hidden):");
            $this->line($this->maskBody($res['body']));
        }
        if (array_slice($this->received, $receivedFrom) === []) {
            $this->warn('No answer received from ToyyibPay (timeout or connection error; see storage/logs/laravel.log).');
        }
    }

    /**
     * ToyyibPay's createBill docs limit billName to 30 and billDescription to
     * 100 characters of letters, digits, spaces and "_". Flag a request that
     * goes beyond that, so a rejected bill is easy to explain.
     *
     * @param  array<string, mixed>  $data
     */
    private function warnAgainstDocs(array $data): void
    {
        foreach (['billName' => 30, 'billDescription' => 100] as $field => $max) {
            $value = (string) ($data[$field] ?? '');
            $problems = [];
            if (mb_strlen($value) > $max) {
                $problems[] = mb_strlen($value)." characters (docs: at most {$max})";
            }
            if (preg_match_all('/[^A-Za-z0-9 _]/u', $value, $m)) {
                $problems[] = 'characters other than letters, digits, spaces and _ ('.implode(' ', array_unique($m[0])).')';
            }
            if ($problems) {
                $this->warn("Note: {$field} has ".implode(' and ', $problems).'. If createBill fails or the name looks wrong on the payment page, report this line.');
            }
        }
        if (isset($data['billAmount'])) {
            $this->line("billAmount is sent in SEN: {$data['billAmount']} = RM ".number_format(((int) $data['billAmount']) / 100, 2).'.');
        }
    }

    /**
     * The secret key is never shown, and the callback URL's per-bill token is
     * hidden, as are the payer's name, email and phone. Everything else is
     * exactly what the app sent.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function maskRequest(array $data): array
    {
        foreach ($data as $k => $v) {
            if (is_string($k) && preg_match(self::PAYER_KEYS, $k)) {
                $data[$k] = '[hidden]';
            }
        }
        if (array_key_exists('userSecretKey', $data)) {
            $data['userSecretKey'] = '[hidden, '.strlen((string) $data['userSecretKey']).' characters]';
        }
        if (isset($data['billCallbackUrl'])) {
            $data['billCallbackUrl'] = preg_replace('/(webhook_token=)[^&]+/', '$1[hidden]', (string) $data['billCallbackUrl']);
        }

        return $data;
    }

    private function maskBody(string $body): string
    {
        $json = json_decode($body, true);
        if (! is_array($json)) {
            return $body === '' ? '(empty)' : Str::limit($body, 2000);
        }
        $mask = function ($value) use (&$mask) {
            if (! is_array($value)) {
                return $value;
            }
            foreach ($value as $k => $v) {
                $value[$k] = is_string($k) && preg_match(self::PAYER_KEYS, $k) ? '[hidden]' : $mask($v);
            }

            return $value;
        };

        return (string) json_encode($mask($json), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
