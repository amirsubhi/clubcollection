<?php

namespace App\Http\Controllers\Member;

use App\Http\Controllers\Controller;
use App\Models\Attachment;
use App\Models\Club;
use App\Models\Payment;
use App\Models\PaymentBill;
use App\Services\AttachmentService;
use App\Services\AuditService;
use App\Services\PaymentGateways\PaymentGatewayManager;
use App\Services\Payments\SettlementService;
use App\Services\TransferVerificationService;
use App\Support\Member\MemberPaymentPresenter as P;
use App\Support\Payments\DisplayStatus;
use App\Support\Payments\GatewayFee;
use App\Support\Payments\OpenFeeSet;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * "Bayar semua" (R1b): pay every open fee in a club at once, with one FPX
 * bill (one gateway charge instead of one per fee) or one transfer slip.
 *
 * The member confirms the exact set on the review page; pay and transfer
 * refuse when the open fees changed since (member.set_changed). The group is
 * a payment_bills row without payment_id plus payment_bill_items; it is
 * settled all at once (WebhookController / TransferVerificationService) or
 * not at all.
 */
class PayAllController extends Controller
{
    public function __construct(protected PaymentGatewayManager $gateways) {}

    /** The member's fees still owed in the club, oldest first. */
    public static function openFees(Club $club, int $userId): Builder
    {
        return OpenFeeSet::query($club->id, $userId);
    }

    /**
     * Review the whole set: the fees, the total and both ways to pay. With
     * fewer than two open fees there is nothing to group.
     */
    public function review(Club $club)
    {
        $user = auth()->user();
        self::authorizeActiveMembership($club);

        $fees = self::openFees($club, $user->id)->get();
        if ($fees->count() < 2) {
            return $fees->count() === 1
                ? redirect()->route('member.payments.review', $fees->first())
                : redirect()->route('member.dashboard')->with('success', 'member.nothing_to_pay');
        }

        $gateway = $this->gateways->for($club);
        $openBill = $this->matchingOpenBill($club, $user->id, $fees, $gateway->name());
        // An open bill is reused as it is, so show the fee mode it was created with.
        $fee = $openBill ? self::storedFee($openBill) : GatewayFee::forClub($club);
        $sumSen = $fees->sum(fn (Payment $p) => $p->amountSen());
        // A bill (single or group) was already opened for one of these fees: warn before paying again.
        $hasOpenBill = $openBill !== null || $fees->contains(fn (Payment $p) => ! empty($p->bill_code));
        // An FPX payment for this set, or for one of its fees, started recently
        // and is not confirmed yet: checking it comes first (R2a).
        $statuses = DisplayStatus::many($fees);
        $inProgress = $fees->first(fn (Payment $p) => $statuses[$p->id] === DisplayStatus::PROCESSING);

        return Inertia::render('Member/ReviewPay', [
            'payment' => null,
            'group' => self::groupProps($fees),
            'charges' => [
                'payer' => $fee->payer,
                'feeSen' => $fee->memberSurchargeSen(),
                'totalSen' => $fee->totalSen($sumSen),
            ],
            'gateway' => [
                'name' => $gateway->name(),
                'label' => P::gatewayLabel($gateway->name()),
                'available' => $gateway->isConfiguredForClub($club),
            ],
            'hasOpenBill' => $hasOpenBill,
            // Where the member can check the payment they already started for this set.
            'openBillUrl' => $inProgress ? DisplayStatus::memberBillUrl($inProgress)
                : ($openBill ? route('member.payments.group-result', $openBill) : null),
            'processing' => $inProgress !== null,
            // Paying the set again is refused while another bill is in progress for one of its fees.
            'payAgainBlocked' => self::inProgressElsewhere($fees, $openBill),
            'payUrl' => route('member.payments.pay-all', $club),
            'transfer' => $club->transferReady() ? [
                'bankName' => $club->bank_name,
                'accountName' => $club->bank_account_name,
                'accountNo' => $club->bank_account_no,
                'duitnowId' => $club->duitnow_id,
                'reference' => PaymentBill::groupTransferReference($fees->first()->id),
                'submitUrl' => route('member.payments.transfer-all', $club),
                'today' => today()->toDateString(),
                'minDate' => today()->subYear()->toDateString(),
                'maxFiles' => TransferVerificationService::MAX_SLIPS,
                'maxMb' => (int) (Attachment::MAX_KB / 1024),
            ] : null,
            'verification' => null,
            // The last slip for these fees was rejected: the reason, so the member can fix it.
            'rejection' => ($lead = $fees->first())->rejection_reason ? [
                'reason' => $lead->rejection_reason,
                'at' => $lead->verified_at?->toDateString(),
            ] : null,
            ...self::context($club),
        ]);
    }

    /** One gateway bill for every open fee. POST only, like the single pay. */
    public function pay(Request $request, Club $club)
    {
        $user = $request->user();
        self::authorizeActiveMembership($club);
        $data = self::validateSet($request);

        // One group bill at a time per member and club: a double click or a
        // second tab must not open two bills for the same fees.
        $lock = Cache::lock("pay-all:{$club->id}:{$user->id}", 30);
        if (! $lock->get()) {
            return redirect()->route('member.payments.review-all', $club)->with('error', 'member.try_again');
        }

        try {
            return $this->openGroupBill($club, $user, $data['payment_ids'], (int) $data['total_sen']);
        } finally {
            $lock->release();
        }
    }

    /** One transfer slip for every open fee; an admin approves or rejects the set. */
    public function transfer(Request $request, Club $club, TransferVerificationService $service)
    {
        $user = $request->user();
        self::authorizeActiveMembership($club);
        $set = self::validateSet($request);
        $data = $request->validate(PaymentController::transferRules(), PaymentController::transferMessages());

        $lock = Cache::lock("pay-all:{$club->id}:{$user->id}", 15);
        if (! $lock->get()) {
            return redirect()->route('member.payments.review-all', $club)->with('error', 'member.try_again');
        }

        try {
            $group = $service->submitGroup($club, $user, $set['payment_ids'], (int) $set['total_sen'], array_values($data['files']), $data['transfer_date'], $data['transfer_ref'] ?? null);
        } finally {
            $lock->release();
        }

        return redirect()->route('member.payments.group-result', $group)->with('success', 'member.slip_sent');
    }

    /**
     * Where the gateway sends the member back, and the page for a group
     * transfer: pending / failed / paid (with one receipt line per fee), or
     * "being checked" for a slip. Only the stored state shows success.
     */
    public function result(Request $request, PaymentBill $paymentBill)
    {
        $bill = $paymentBill;
        self::authorizeGroup($bill);
        $club = Club::findOrFail($bill->club_id);
        $fees = $bill->orderedPayments()->get();

        // Settled, but an admin has since reverted a fee in the set: "paid" would no longer be true,
        // so send the member to their fee list, where that fee shows as open again.
        if ($bill->paid_at && ! self::allStillPaid($fees)) {
            return redirect()->route('member.payments.index', $club);
        }

        if ($bill->isTransfer()) {
            // A rejected slip: the fees are open again, so the review page shows them.
            if ($bill->isSuperseded()) {
                return redirect()->route('member.payments.review-all', $club);
            }
            $outcome = $bill->paid_at ? 'paid' : 'verifying';
        } else {
            $outcome = match (true) {
                $bill->paid_at !== null => 'paid',
                PaymentController::gatewayReportsFailure($request) => 'failed',
                default => 'pending',
            };
        }

        $lead = $fees->first();

        return Inertia::render('Member/PayResult', [
            'outcome' => $outcome,
            'payment' => null,
            'group' => self::groupProps($fees, $bill),
            'receipt' => $outcome === 'paid' ? P::groupReceipt($bill, $fees) : null,
            'gateway' => $bill->isTransfer() ? null : P::gatewayLabel($bill->gateway),
            'reference' => $bill->reference,
            'verification' => $outcome === 'verifying' && $lead ? [
                'submittedAt' => $lead->transfer_submitted_at?->format('Y-m-d\TH:i'),
                'transferDate' => $lead->transfer_date?->toDateString(),
                'transferRef' => $lead->transfer_ref,
                'slips' => $bill->attachments()->where('kind', 'proof_of_payment')->get()
                    ->map(fn (Attachment $a) => [
                        'id' => $a->id,
                        'name' => $a->original_name,
                        'isPdf' => $a->mime === 'application/pdf',
                        'url' => route('member.payments.slip', $a),
                    ])->values(),
            ] : null,
            'statusUrl' => route('member.payments.group-status', $bill),
            'reviewUrl' => route('member.payments.review-all', $club),
            // The group receipt's own clean address (no gateway query string), for "Kongsi".
            'receiptUrl' => $outcome === 'paid' ? route('member.payments.group-result', $bill) : null,
            ...self::context($club),
        ]);
    }

    /** JSON status for the result page's polling. Owner only. */
    public function status(PaymentBill $paymentBill, SettlementService $settlement): JsonResponse
    {
        self::authorizeGroup($paymentBill);
        // "Semak status bayaran" (G1): ask the gateway, at most once per bill every 30 s.
        $settlement->requeryGroupForMember($paymentBill);
        $paymentBill->refresh();
        $paid = $paymentBill->paid_at && self::allStillPaid($paymentBill->orderedPayments()->get());

        return response()->json(['status' => $paid ? 'paid' : ($paymentBill->isTransfer() && $paymentBill->isOpen() ? 'verifying' : 'pending')])
            ->header('Cache-Control', 'no-store');
    }

    /** Every fee of a settled group is still paid (none reverted by an admin since). */
    private static function allStillPaid($fees): bool
    {
        return $fees->isNotEmpty() && $fees->every(fn ($f) => $f->status === 'paid');
    }

    /** pay() under the member+club lock. */
    private function openGroupBill(Club $club, $user, array $ids, int $totalSen)
    {
        $back = fn (string $code) => redirect()->route('member.payments.review-all', $club)->with('error', $code);
        $gateway = $this->gateways->for($club);
        if (! $gateway->isConfiguredForClub($club)) {
            return $back('member.gateway_unavailable');
        }
        $fee = GatewayFee::forClub($club);

        // Under row locks: the set the member confirmed must still be exactly
        // the open fees, at the same total. Reuse a bill already opened for it.
        [$fees, $bill] = DB::transaction(function () use ($club, $user, $ids, $totalSen, $gateway, $fee) {
            $fees = self::openFees($club, $user->id)->lockForUpdate()->get();
            if (! OpenFeeSet::matches($fees, $ids, $totalSen)) {
                return [null, null];
            }
            $open = $this->matchingOpenBill($club, $user->id, $fees, $gateway->name());
            // An FPX payment for one of these fees is in progress through another
            // bill: paying the set now could take that fee twice (R2a).
            if (self::inProgressElsewhere($fees, $open)) {
                return [false, null];
            }
            if ($open) {
                return [$fees, $open];
            }

            // Older group bills of this member are for another set or gateway: retire them.
            self::openGroupBills($club, $user->id)->update(['superseded_at' => now()]);

            $bill = PaymentBill::create([
                'club_id' => $club->id,
                'user_id' => $user->id,
                'method' => 'gateway',
                'gateway' => $gateway->name(),
                'amount_sen' => $fees->sum(fn (Payment $p) => $p->amountSen()),
                'fee_payer' => $fee->payer,
                'fee_sen' => $fee->feeSen,
            ]);
            $bill->forceFill(['reference' => 'PAYG-'.$bill->id.'-'.time()])->save();
            $bill->payments()->attach($fees->mapWithKeys(fn (Payment $p) => [$p->id => ['amount_sen' => $p->amountSen()]])->all());

            return [$fees, $bill];
        });

        if ($fees === false) {
            return $back('member.check_status_first');
        }
        if (! $fees) {
            return $back('member.set_changed');
        }
        if ($bill->bill_code) {
            // The bill already opened for this set: "in progress" counts from now.
            $bill->markOpened();

            return Inertia::location($gateway->paymentUrl($bill->bill_code));
        }

        $first = $fees->first();
        $last = $fees->last();
        $callbackRoute = match ($gateway->name()) {
            'toyyibpay' => 'webhook.toyyibpay',
            'billplz' => 'webhook.billplz',
            default => throw new \LogicException("No webhook route registered for gateway '{$gateway->name()}'"),
        };

        $result = $gateway->createBill([
            'club' => $club,
            'bill_name' => "Club Fee – {$club->name}",
            'description' => "Payment for {$fees->count()} fees, {$first->period_start->format('M Y')} to {$last->period_end->format('M Y')}",
            'amount' => $bill->amount_sen / 100,
            'return_url' => route('member.payments.group-result', $bill),
            'callback_url' => route($callbackRoute),
            'reference_no' => $bill->reference,
            'payer_name' => $user->name,
            'payer_email' => $user->email,
            'club_name' => $club->name,
            'fee' => $fee,
        ]);

        if (! $result) {
            $bill->delete();

            return $back('member.bill_failed');
        }

        $bill->forceFill(['bill_code' => $result->billCode, 'gateway' => $result->gateway, 'opened_at' => now()])->save();

        AuditService::log(
            'payment.group_bill_opened',
            "Group bill {$result->billCode} ({$result->gateway}) opened for {$fees->count()} fees (".$fees->pluck('id')->map(fn ($id) => "#{$id}")->implode(', ').'), RM '.number_format($bill->amount_sen / 100, 2).'.',
            $first,
            $club->id,
            [],
            ['bill_code' => $result->billCode, 'reference' => $bill->reference, 'payment_ids' => $fees->pluck('id')->all(), 'amount_sen' => $bill->amount_sen, 'fee_payer' => $fee->payer, 'fee_sen' => $fee->feeSen]
        );

        // A fee changed while the gateway was being called: the Payment hook has
        // superseded the bill. Don't send the member to pay a stale set.
        if ($bill->fresh()->isSuperseded()) {
            return $back('member.set_changed');
        }

        return Inertia::location($result->paymentUrl);
    }

    /** @return array{payment_ids: array<int, int>, total_sen: int} */
    private static function validateSet(Request $request): array
    {
        return $request->validate([
            'payment_ids' => ['required', 'array', 'min:2', 'max:120'],
            'payment_ids.*' => ['required', 'integer', 'distinct'],
            'total_sen' => ['required', 'integer', 'min:1'],
        ], [
            // Any problem with the set means the page is out of date: reload it.
            'payment_ids' => 'member.set_changed',
            'payment_ids.*' => 'member.set_changed',
            'total_sen' => 'member.set_changed',
        ]);
    }

    /** A fee of the set has an FPX payment in progress through a bill other than $reuse (the set's own bill). */
    private static function inProgressElsewhere(Collection $fees, ?PaymentBill $reuse): bool
    {
        DisplayStatus::prime($fees);

        return $fees->contains(fn (Payment $p) => ($b = DisplayStatus::openBill($p)) !== null && (int) $b->id !== (int) $reuse?->id);
    }

    /** Open (unpaid, not superseded) gateway group bills of a member in a club. */
    private static function openGroupBills(Club $club, int $userId)
    {
        return PaymentBill::query()
            ->whereNull('payment_id')
            ->where('method', 'gateway')
            ->where('club_id', $club->id)
            ->where('user_id', $userId)
            ->whereNull('paid_at')
            ->whereNull('superseded_at');
    }

    /** An open group bill at this gateway for exactly these fees at their current amounts. */
    private function matchingOpenBill(Club $club, int $userId, Collection $fees, string $gateway): ?PaymentBill
    {
        $want = $fees->mapWithKeys(fn (Payment $p) => [$p->id => $p->amountSen()])->sortKeys()->all();

        return self::openGroupBills($club, $userId)
            ->where('gateway', $gateway)
            ->whereNotNull('bill_code')
            ->latest('id')
            ->get()
            ->first(function (PaymentBill $b) use ($want) {
                $have = $b->itemAmounts();
                ksort($have);

                return $have === $want;
            });
    }

    private static function storedFee(PaymentBill $bill): GatewayFee
    {
        return new GatewayFee($bill->fee_payer ?? 'club', (int) ($bill->fee_sen ?? 0));
    }

    /**
     * @return array<string, mixed>
     */
    private static function groupProps(Collection $fees, ?PaymentBill $bill = null): array
    {
        $amounts = $bill?->itemAmounts();

        return [
            // Each fee as billed (the group's own amount for a settled bill).
            'fees' => $fees->map(fn (Payment $p) => P::summary($p) + (isset($amounts[$p->id]) ? ['amount' => $amounts[$p->id]] : []))->values(),
            'paymentIds' => $fees->pluck('id')->values(),
            'sumSen' => $amounts !== null ? array_sum($amounts) : $fees->sum(fn (Payment $p) => $p->amountSen()),
            'method' => $bill?->method,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function context(Club $club): array
    {
        $user = auth()->user();

        return [
            'member' => ['name' => $user->name, 'email' => $user->email],
            'payClub' => PaymentController::payClubProps($club),
            'paymentsUrl' => route('member.payments.index', $club),
            'dashboardUrl' => route('member.dashboard'),
        ];
    }

    /** Active member of an active club. */
    private static function authorizeActiveMembership(Club $club): void
    {
        $active = $club->is_active
            && $club->members()->where('users.id', auth()->id())->where('club_user.is_active', true)->exists();
        abort_unless($active, 403, 'You are not an active member of this club.');
    }

    /** The member's own group, and still an active member of its (active) club. */
    public static function authorizeGroup(PaymentBill $bill): void
    {
        abort_unless($bill->isGroup() && (int) $bill->user_id === (int) auth()->id(), 403);
        $club = Club::find($bill->club_id);
        abort_unless($club !== null, 403);
        self::authorizeActiveMembership($club);
    }
}
