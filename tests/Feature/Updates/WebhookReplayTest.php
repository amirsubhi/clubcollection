<?php

namespace Tests\Feature\Updates;

use App\Mail\PaymentConfirmation;
use App\Models\AuditLog;
use App\Models\Club;
use App\Models\Payment;
use App\Models\User;
use App\Support\Payments\BillplzSignature;
use App\Support\Updates\UpdateOutcomes;
use App\Support\Updates\UpdaterFiles;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Payment callbacks saved by the swap guard are replayed through the normal handler once the guard is gone. */
class WebhookReplayTest extends TestCase
{
    use UsesUpdaterStorage;

    private string $key = 'test-billplz-x-signature-key-2026';

    protected function setUp(): void
    {
        parent::setUp();
        $this->useUpdaterStorage();
        config(['billplz.x_signature_key' => $this->key, 'toyyibpay.webhook_secret' => 'test-toyyibpay-webhook-secret-2026-long']);
        Mail::fake();
    }

    protected function tearDown(): void
    {
        $this->dropUpdaterStorage();
        parent::tearDown();
    }

    private function pending(string $gateway = 'billplz'): Payment
    {
        $club = Club::factory()->create(['payment_gateway' => $gateway]);
        $member = User::factory()->create();
        $club->members()->attach($member, ['role' => 'member', 'job_level' => 'executive', 'joined_date' => now()->toDateString(), 'is_active' => true]);

        return Payment::factory()->forClub($club)->forMember($member)->pending()->create([
            'bill_code' => 'BPLZ-'.uniqid(), 'reference' => 'REF-'.uniqid(), 'gateway' => $gateway,
        ]);
    }

    /** @return array<string, string> */
    private function billplzBody(Payment $p, bool $sign = true): array
    {
        $body = [
            'id' => $p->bill_code, 'collection_id' => 'col-1', 'paid' => 'true', 'state' => 'paid',
            'amount' => (string) (int) round($p->amount * 100), 'paid_amount' => (string) (int) round($p->amount * 100),
            'transaction_id' => 'TXN-'.$p->id, 'transaction_status' => 'completed', 'reference_1' => $p->reference,
        ];
        $body['x_signature'] = $sign ? BillplzSignature::compute($body, $this->key) : str_repeat('0', 64);

        return $body;
    }

    private function save(array $body, string $name, string $path = '/webhook/billplz', string $query = '', array $headers = ['content-type' => 'application/x-www-form-urlencoded']): string
    {
        File::ensureDirectoryExists(UpdaterFiles::webhooksDir());
        $file = UpdaterFiles::webhooksDir().'/'.$name.'.json';
        file_put_contents($file, json_encode(['path' => $path, 'body' => http_build_query($body), 'headers' => $headers, 'query' => $query, 'at' => time()]));

        return $file;
    }

    public function test_a_saved_billplz_callback_with_a_valid_signature_settles_the_payment(): void
    {
        $p = $this->pending();
        $file = $this->save($this->billplzBody($p), '1796330000000001-aaaaaaaaaaaa');

        UpdateOutcomes::replayWebhooks();

        $this->assertSame('paid', $p->fresh()->status);
        $this->assertFileDoesNotExist($file);
        Mail::assertSent(PaymentConfirmation::class, 1);
    }

    public function test_a_replayed_duplicate_does_not_settle_twice(): void
    {
        $p = $this->pending();
        $one = $this->save($this->billplzBody($p), '1796330000000001-aaaaaaaaaaaa');
        $two = $this->save($this->billplzBody($p), '1796330000000002-bbbbbbbbbbbb');

        UpdateOutcomes::replayWebhooks();

        $this->assertFileDoesNotExist($one);
        $this->assertFileDoesNotExist($two);
        $this->assertSame(1, AuditLog::where('action', 'payment.paid_via_webhook')->count());
        Mail::assertSent(PaymentConfirmation::class, 1);
    }

    public function test_a_bad_signature_is_not_settled_and_is_set_aside_after_three_spaced_tries(): void
    {
        Carbon::setTestNow('2026-12-04 10:00:00');
        $p = $this->pending();
        $file = $this->save($this->billplzBody($p, sign: false), '1796330000000001-aaaaaaaaaaaa');

        UpdateOutcomes::replayWebhooks();
        $this->assertSame(1, json_decode(file_get_contents($file), true)['tries']);
        // Three requests within a second: the later ones find it not yet due.
        UpdateOutcomes::replayWebhooks();
        UpdateOutcomes::replayWebhooks();
        $this->assertFileExists($file);
        $this->assertSame(1, json_decode(file_get_contents($file), true)['tries']);

        Carbon::setTestNow('2026-12-04 10:01:01');
        UpdateOutcomes::replayWebhooks();
        $this->assertSame(2, json_decode(file_get_contents($file), true)['tries']);
        Carbon::setTestNow('2026-12-04 10:02:30');
        UpdateOutcomes::replayWebhooks();
        $this->assertSame(2, json_decode(file_get_contents($file), true)['tries']);
        Carbon::setTestNow('2026-12-04 10:03:10');
        UpdateOutcomes::replayWebhooks();

        $this->assertFileDoesNotExist($file);
        $this->assertFileExists(UpdaterFiles::webhooksDir().'/failed/1796330000000001-aaaaaaaaaaaa.json');
        $this->assertSame('pending', $p->fresh()->status);
        Carbon::setTestNow();
    }

    public function test_the_replay_stops_when_its_time_budget_is_spent(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->save(['id' => 'NOPE-'.$i, 'paid' => 'false'], sprintf('17963300000%05d-aaaaaaaaaaaa', $i));
        }
        $t = 0.0;
        // Each look at the clock costs 2 s: deadline 7 s; files are looked at at 4, 6 and 8 s, so two are replayed.
        UpdateOutcomes::replayWebhooks(function () use (&$t) {
            return $t += 2.0;
        });

        $tried = array_filter(glob(UpdaterFiles::webhooksDir().'/*.json'), fn ($f) => isset(json_decode(file_get_contents($f), true)['tries']));
        $this->assertCount(2, $tried);
    }

    public function test_a_saved_toyyibpay_token_travels_with_the_callback(): void
    {
        config(['toyyibpay.verify_callback' => false]);
        $p = $this->pending('toyyibpay');
        $token = hash_hmac('sha256', $p->reference, 'test-toyyibpay-webhook-secret-2026-long');
        $body = ['billcode' => $p->bill_code, 'order_id' => $p->reference, 'status_id' => '1', 'transaction_id' => 'TP1', 'amount' => (string) $p->amount];

        $bad = $this->save($body, '1796330000000001-aaaaaaaaaaaa', '/webhook/toyyibpay', 'webhook_token=wrong');
        UpdateOutcomes::replayWebhooks();
        $this->assertFileExists($bad);
        $this->assertSame('pending', $p->fresh()->status);
        unlink($bad);

        $ok = $this->save($body, '1796330000000002-bbbbbbbbbbbb', '/webhook/toyyibpay', 'webhook_token='.$token);
        UpdateOutcomes::replayWebhooks();
        $this->assertFileDoesNotExist($ok);
        $this->assertSame('paid', $p->fresh()->status);
    }

    public function test_replay_runs_on_a_normal_request_without_any_outcome_and_waits_for_the_guard(): void
    {
        $p = $this->pending();
        $file = $this->save($this->billplzBody($p), '1796330000000001-aaaaaaaaaaaa');

        touch(UpdaterFiles::guardPath());
        $this->get('/login');
        $this->assertFileExists($file);
        $this->assertSame('pending', $p->fresh()->status);

        unlink(UpdaterFiles::guardPath());
        $this->get('/login');
        $this->assertFileDoesNotExist($file);
        $this->assertSame('paid', $p->fresh()->status);
    }

    public function test_at_most_fifty_files_are_replayed_per_request_oldest_first(): void
    {
        for ($i = 1; $i <= 52; $i++) {
            $this->save(['id' => 'NOPE-'.$i, 'paid' => 'false'], sprintf('17963300000%05d-aaaaaaaaaaaa', $i));
        }
        // Unknown bills: each answers 403 (no signature) and stays, with one try used.
        UpdateOutcomes::replayWebhooks();

        $tried = array_filter(glob(UpdaterFiles::webhooksDir().'/*.json'), fn ($f) => (json_decode(file_get_contents($f), true)['tries'] ?? 0) === 1);
        $this->assertCount(50, $tried);
        $this->assertArrayNotHasKey('tries', json_decode(file_get_contents(UpdaterFiles::webhooksDir().'/1796330000000052-aaaaaaaaaaaa.json'), true) ?? []);
    }

    public function test_an_unknown_path_or_unreadable_file_is_set_aside_not_replayed(): void
    {
        $this->save(['a' => '1'], '1796330000000001-aaaaaaaaaaaa', '/webhook/evil');
        file_put_contents(UpdaterFiles::webhooksDir().'/1796330000000002-bbbbbbbbbbbb.json', 'not json');

        UpdateOutcomes::replayWebhooks();

        foreach (['1796330000000001-aaaaaaaaaaaa', '1796330000000002-bbbbbbbbbbbb'] as $name) {
            $this->assertFileDoesNotExist(UpdaterFiles::webhooksDir().'/'.$name.'.json');
            $this->assertFileExists(UpdaterFiles::webhooksDir().'/failed/'.$name.'.json');
        }
    }

    public function test_a_stale_claim_from_a_request_that_died_is_put_back(): void
    {
        File::ensureDirectoryExists(UpdaterFiles::webhooksDir());
        $claim = UpdaterFiles::webhooksDir().'/1796330000000001-aaaaaaaaaaaa.json.claim-abcdef012345';
        file_put_contents($claim, '{}');
        touch($claim, time() - 3600);

        UpdateOutcomes::replayWebhooks();

        $this->assertFileDoesNotExist($claim);
        $this->assertFileExists(UpdaterFiles::webhooksDir().'/1796330000000001-aaaaaaaaaaaa.json');
    }
}
