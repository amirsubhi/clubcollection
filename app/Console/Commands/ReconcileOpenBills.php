<?php

namespace App\Console\Commands;

use App\Models\Payment;
use App\Models\PaymentBill;
use App\Services\Payments\SettlementService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * G1: a lost gateway callback must not leave a paid fee "Dalam proses" for
 * days and then trigger an overdue reminder. Every 15 minutes, ask the
 * gateway about each open, unsuperseded bill opened between 10 minutes and
 * 3 days ago, and settle the ones it reports paid through the same code the
 * webhook uses (SettlementService). Bounded per run; a gateway error on one
 * bill is logged and the run carries on.
 *
 * ToyyibPay is asked with getBillTransactions. Billplz bills are skipped:
 * BillplzGateway has no get-bill call yet, so they rely on the callback.
 */
#[Signature('payments:reconcile-open-bills {--limit=100 : Most bills asked about in one run}')]
#[Description('Ask the payment gateway about open bills whose callback may have been lost, and settle the paid ones')]
class ReconcileOpenBills extends Command
{
    /** Where the next run resumes, so a long list is worked through over several runs. */
    public const CURSOR_KEY = 'payments:reconcile:cursor';

    public function handle(SettlementService $settlement): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $from = now()->subDays(3)->toDateTimeString();
        $to = now()->subMinutes(10)->toDateTimeString();
        $counts = [SettlementService::SETTLED => 0, SettlementService::UNPAID => 0, SettlementService::NOTHING => 0,
            SettlementService::UNAVAILABLE => 0, SettlementService::AMOUNT_UNKNOWN => 0, SettlementService::UNSUPPORTED => 0, 'error' => 0];

        $askable = collect(['toyyibpay', 'billplz'])->filter(fn ($g) => $settlement->canRequery($g))->values()->all();
        $open = fn () => PaymentBill::query()
            ->where('method', 'gateway')
            ->whereNotNull('bill_code')
            ->whereNull('paid_at')
            ->whereNull('superseded_at')
            ->whereRaw('COALESCE(opened_at, created_at) >= ?', [$from])
            ->whereRaw('COALESCE(opened_at, created_at) <= ?', [$to]);

        foreach (array_diff(['toyyibpay', 'billplz'], $askable) as $gateway) {
            $n = $open()->where('gateway', $gateway)->count();
            if ($n > 0) {
                $why = $gateway === 'billplz'
                    ? 'BillplzGateway has no get-bill call yet; these rely on the Billplz callback.'
                    : 'asking the gateway is turned off (TOYYIBPAY_VERIFY_CALLBACK=false).';
                $this->line("Skipped {$n} open {$gateway} bill(s): {$why}");
            }
        }

        // Bills in payment_bills, resuming after the last id the previous run reached.
        $cursor = (int) Cache::get(self::CURSOR_KEY, 0);
        $bills = $askable ? $open()->whereIn('gateway', $askable)->where('id', '>', $cursor)->orderBy('id')->limit($limit)->get() : collect();
        Cache::forever(self::CURSOR_KEY, $bills->count() < $limit ? 0 : (int) $bills->last()->id);

        foreach ($bills as $bill) {
            $this->tally($counts, fn () => $settlement->requery($bill, 'reconcile'), "bill {$bill->bill_code}");
        }

        // Fees opened before payment_bills existed: payments.bill_code only.
        $left = $limit - $bills->count();
        if ($askable && $left > 0) {
            Payment::query()
                ->whereIn('gateway', $askable)
                ->whereNotNull('bill_code')
                ->whereIn('status', ['pending', 'overdue'])
                ->whereDoesntHave('bills')
                ->whereBetween('updated_at', [$from, $to])
                ->orderBy('id')->limit($left)->get()
                ->each(fn (Payment $p) => $this->tally($counts, fn () => $settlement->requeryLegacy($p, 'reconcile'), "fee #{$p->id}"));
        }

        $this->info(sprintf(
            'Checked %d bill(s): %d settled, %d not paid yet, %d already handled or flagged, %d gateway unreachable, %d amount unknown, %d gateway not supported, %d errors.',
            array_sum($counts), $counts[SettlementService::SETTLED], $counts[SettlementService::UNPAID], $counts[SettlementService::NOTHING],
            $counts[SettlementService::UNAVAILABLE], $counts[SettlementService::AMOUNT_UNKNOWN], $counts[SettlementService::UNSUPPORTED], $counts['error']
        ));

        return self::SUCCESS;
    }

    private function tally(array &$counts, callable $run, string $what): void
    {
        try {
            $outcome = $run();
            $counts[$outcome] = ($counts[$outcome] ?? 0) + 1;
            if ($outcome === SettlementService::SETTLED) {
                $this->line("Settled {$what}.");
            }
        } catch (\Throwable $e) {
            $counts['error']++;
            Log::error('payments:reconcile-open-bills failed for one bill', ['what' => $what, 'error' => $e->getMessage()]);
            $this->warn("Error on {$what}: {$e->getMessage()}");
        }
    }
}
