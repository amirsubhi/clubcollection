<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\Payment;
use App\Models\PaymentBill;
use App\Models\User;
use App\Support\Updates\UpdaterHost;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** The real host facts that need the application (database, config, storage). */
class UpdaterHostTest extends TestCase
{
    private string $job;

    protected function setUp(): void
    {
        parent::setUp();
        $this->job = storage_path('app/updater/job.json');
        $this->removeJob();
    }

    protected function tearDown(): void
    {
        $this->removeJob();
        parent::tearDown();
    }

    private function removeJob(): void
    {
        if (is_file($this->job)) {
            unlink($this->job);
        }
    }

    private function hostWithDriver(string $driver): UpdaterHost
    {
        return new class($driver) extends UpdaterHost
        {
            public function __construct(private string $driver) {}

            public function databaseDriver(): string
            {
                return $this->driver;
            }
        };
    }

    public function test_sqlite_needs_no_backup_tick(): void
    {
        $this->assertFalse($this->hostWithDriver('sqlite')->databaseBackupNeedsTick());
    }

    public function test_other_drivers_need_a_tick_without_a_program(): void
    {
        $this->assertTrue($this->hostWithDriver('pgsql')->databaseBackupNeedsTick());
    }

    public function test_no_job_file_means_nothing_is_running(): void
    {
        $this->assertFalse((new UpdaterHost)->updateRunning());
    }

    public function test_job_in_progress_blocks_and_terminal_job_statuses_do_not_block(): void
    {
        @mkdir(dirname($this->job), 0775, true);
        $host = new UpdaterHost;

        foreach (['ready', 'running', 'something-new'] as $status) {
            file_put_contents($this->job, json_encode(['status' => $status]));
            $this->assertTrue($host->updateRunning(), $status);
        }
        file_put_contents($this->job, 'not json');
        $this->assertTrue($host->updateRunning());
        foreach (['finished', 'undone', 'failed', 'rolled_back'] as $status) {
            file_put_contents($this->job, json_encode(['status' => $status]));
            $this->assertFalse($host->updateRunning(), $status);
        }
    }

    private function bill(array $attrs): PaymentBill
    {
        static $n = 0;
        $club = Club::factory()->create();
        $member = User::factory()->create();
        $fee = Payment::factory()->forClub($club)->forMember($member)->create([
            'status' => 'pending', 'paid_date' => null, 'amount' => 10.00,
            'period_start' => '2026-01-01', 'period_end' => '2026-01-31', 'due_date' => '2026-11-30',
        ]);

        return PaymentBill::create($attrs + [
            'payment_id' => $fee->id, 'method' => 'gateway', 'gateway' => 'toyyibpay', 'bill_code' => 'B'.++$n,
            'reference' => 'PAY-'.$n, 'amount_sen' => 1000,
        ]);
    }

    private function inProgress(): bool
    {
        return (new UpdaterHost)->paymentInProgress();
    }

    public function test_payment_in_progress_looks_at_open_bills_of_the_last_two_minutes(): void
    {
        $this->travelTo(Carbon::create(2026, 11, 28, 10));
        $this->assertFalse($this->inProgress());

        $this->bill(['opened_at' => now()->subMinutes(3), 'created_at' => now()->subMinutes(3)]);
        $this->assertFalse($this->inProgress(), 'opened 3 minutes ago');

        $this->bill(['opened_at' => now()->subMinute(), 'created_at' => now()->subMinutes(3)]);
        $this->assertTrue($this->inProgress(), 'created 3 minutes ago but reopened 1 minute ago');
    }

    public function test_a_bill_opened_a_minute_ago_counts_and_bills_that_are_not_open_do_not(): void
    {
        $this->travelTo(Carbon::create(2026, 11, 28, 10));
        $recent = ['opened_at' => now()->subMinute(), 'created_at' => now()->subMinute()];

        $this->bill($recent + ['paid_at' => now()]);
        $this->bill($recent + ['superseded_at' => now()]);
        $this->bill($recent + ['method' => 'transfer', 'gateway' => null, 'bill_code' => null]);
        $this->bill($recent + ['bill_code' => null]);
        $this->assertFalse($this->inProgress(), 'paid, superseded, transfer and no bill code do not count');

        $this->bill($recent);
        $this->assertTrue($this->inProgress(), 'opened 1 minute ago');
    }

    public function test_a_bill_never_reopened_falls_back_to_its_creation_time(): void
    {
        $this->travelTo(Carbon::create(2026, 11, 28, 10));
        $this->bill(['opened_at' => null, 'created_at' => now()->subMinute()]);
        $this->assertTrue($this->inProgress());
    }
}
