<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\AuthorizesClubResource;
use App\Http\Controllers\Controller;
use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\Club;
use App\Models\Payment;
use App\Models\PaymentBill;
use App\Services\TransferVerificationService;
use App\Support\Admin\AdminNav;
use App\Support\Admin\FundUsagePresenter;
use App\Support\Admin\FundFigures;
use App\Support\Member\MemberPaymentPresenter as P;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pengesahan bayaran: club admins check members' bank / DuitNow transfer
 * slips and approve (fee paid) or reject (back to pending, with a reason).
 * Rules live in TransferVerificationService.
 */
class TransferVerificationController extends Controller
{
    use AuthorizesClubResource;

    /** Audit actions shown in the "Recently handled" tab. */
    public const HISTORY_ACTIONS = [
        'payment.transfer_approved' => 'approved',
        'payment.transfer_rejected' => 'rejected',
        'payment.gateway_paid_during_verification' => 'gateway_paid',
        'payment.gateway_paid_after_manual' => 'gateway_paid_after_manual',
        'payment.group_bill_refund' => 'group_refund',
        'payment.underpaid_webhook' => 'underpaid',
    ];

    /** How a fee recorded by hand was paid (payments.paid_via), in admin words. */
    public const RECORDED_AS = ['cash' => 'cash', 'transfer' => 'bank transfer', 'manual' => 'other'];

    public function __construct(private TransferVerificationService $service) {}

    public function index(Request $request, Club $club): Response
    {
        $user = $request->user();

        $waiting = $club->payments()->where('status', 'pending_verification')
            ->with(['user:id,name,email'])
            ->orderBy('transfer_submitted_at')
            ->orderBy('id')
            ->get();

        $pending = self::slipSets($waiting)
            ->map(function (array $set) use ($user) {
                ['lead' => $lead, 'group' => $group] = $set;

                return self::card($lead) + [
                    'group' => $group ? self::groupCard($group) : null,
                    'blocker' => $this->service->blocker($lead, $user),
                    'urls' => [
                        'approve' => route('admin.verifications.approve', $lead),
                        'reject' => route('admin.verifications.reject', $lead),
                        'payment' => route('admin.payments.show', $lead),
                    ],
                ];
            })
            ->sortBy('submittedAt')
            ->values();

        return Inertia::render('Admin/Verifications/Index', [
            'nav' => AdminNav::props($club, $user, (new FundFigures($club))->awaiting((int) $user->id)['mine']),
            'club' => ['name' => $club->name, 'transferReady' => $club->transferReady()],
            'tab' => $request->query('tab') === 'handled' ? 'handled' : 'pending',
            'pending' => $pending,
            'handled' => self::history($club, 20),
            'today' => today()->toDateString(),
            'urls' => [
                'index' => route('admin.clubs.verifications.index', $club),
                'billing' => route('admin.clubs.billing.edit', $club),
            ],
        ]);
    }

    public function approve(Request $request, Payment $payment): RedirectResponse
    {
        $this->authorizeClubAdmin($payment->club);

        return $this->act($payment, function () use ($request, $payment) {
            $data = $request->validate([
                'paid_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today', 'after_or_equal:'.today()->subYear()->toDateString()],
                'reference' => ['nullable', 'string', 'max:100'],
            ], [
                'paid_date.before_or_equal' => 'The paid date can\'t be in the future.',
                'paid_date.after_or_equal' => 'The paid date must be within the last year.',
            ]);
            $this->service->approve($payment, $request->user(), $data['paid_date'], $data['reference'] ?? null);
        }, 'Slip approved. '.$this->who($payment).'\'s '.$this->fees($payment).' now paid and a receipt was emailed.');
    }

    public function reject(Request $request, Payment $payment): RedirectResponse
    {
        $this->authorizeClubAdmin($payment->club);

        return $this->act($payment, function () use ($request, $payment) {
            $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);
            $this->service->reject($payment, $request->user(), $data['reason']);
        }, 'Slip rejected. '.$this->who($payment).' was emailed the reason and can send a new slip.');
    }

    /**
     * How many slips wait in a club, counted as slipSets() does but in SQL: a fee in an open
     * group slip counts once per group, any other waiting fee once. For the sidebar badge.
     */
    public static function waitingSlipCount(Club $club): int
    {
        $openGroup = 'payments.transfer_group_id IS NOT NULL AND EXISTS (SELECT 1 FROM payment_bills b WHERE b.id = payments.transfer_group_id'
            ." AND b.method = 'transfer' AND b.paid_at IS NULL AND b.superseded_at IS NULL)";
        $row = $club->payments()->where('status', 'pending_verification')->toBase()
            ->selectRaw("COUNT(DISTINCT CASE WHEN {$openGroup} THEN payments.transfer_group_id END) AS g_count, SUM(CASE WHEN {$openGroup} THEN 0 ELSE 1 END) AS p_count")
            ->first();

        return (int) ($row->g_count ?? 0) + (int) ($row->p_count ?? 0);
    }

    /**
     * Waiting fees as the slips an admin checks: "Bayar semua" fees sent with one slip
     * (payments.transfer_group_id, open groups only) form one set; any other fee is its own.
     * Each set has its lead fee (oldest due), its fees and the group bill, if any.
     *
     * @param  Collection<int, Payment>  $waiting
     * @return Collection<string, array{lead: Payment, fees: Collection<int, Payment>, group: ?PaymentBill}>
     */
    public static function slipSets(Collection $waiting): Collection
    {
        $groups = PaymentBill::whereIn('id', $waiting->pluck('transfer_group_id')->filter()->unique())
            ->where('method', 'transfer')->whereNull('paid_at')->whereNull('superseded_at')
            ->get()->keyBy('id');

        return $waiting
            ->groupBy(fn (Payment $p) => $p->transfer_group_id && isset($groups[$p->transfer_group_id]) ? 'g'.$p->transfer_group_id : 'p'.$p->id)
            ->map(function (Collection $fees, string $key) use ($groups) {
                $lead = $fees->sortBy(fn (Payment $p) => [$p->due_date->toDateString(), $p->id])->first();

                return ['lead' => $lead, 'fees' => $fees->values(), 'group' => str_starts_with($key, 'g') ? $groups[(int) substr($key, 1)] : null];
            });
    }

    /**
     * Pending slip summary shared by this page and the dashboard panel.
     *
     * @return array<string, mixed>
     */
    public static function card(Payment $p, bool $withSlips = true): array
    {
        return [
            'id' => $p->id,
            'invoiceNo' => P::invoiceNo($p),
            'member' => ['name' => $p->user?->name ?? 'Member', 'email' => $p->user?->email],
            'periodStart' => $p->period_start->toDateString(),
            'periodEnd' => $p->period_end->toDateString(),
            'frequency' => $p->frequency,
            'amount' => P::sen($p->amount),
            'dueDate' => $p->due_date->toDateString(),
            'transferDate' => $p->transfer_date?->toDateString(),
            'transferRef' => $p->transfer_ref,
            'expectedRef' => $p->transferReference(),
            'submittedAt' => $p->transfer_submitted_at?->format('Y-m-d\TH:i'),
            'slips' => $withSlips ? $p->currentSlips()->get()->map(fn (Attachment $a) => FundUsagePresenter::attachment($a))->values() : [],
        ];
    }

    /**
     * The fees of a group slip ("Bayar semua") with the total, for the card:
     * one line per fee, as billed, and its current status.
     *
     * @return array<string, mixed>
     */
    public static function groupCard(PaymentBill $group): array
    {
        $amounts = $group->itemAmounts();
        $fees = $group->orderedPayments()->get();

        $waiting = $fees->filter(fn (Payment $p) => $p->status === 'pending_verification');

        return [
            'id' => $group->id,
            'reference' => $group->reference,
            'totalSen' => array_sum($amounts),
            // What approving marks paid: fees paid online meanwhile are left out (their share is flagged for a refund).
            'approveSen' => $waiting->sum(fn (Payment $p) => $amounts[$p->id] ?? $p->amountSen()),
            'paidOnlineSen' => $fees->filter(fn (Payment $p) => $p->status === 'paid')->sum(fn (Payment $p) => $amounts[$p->id] ?? $p->amountSen()),
            'fees' => $fees->map(fn (Payment $p) => [
                'id' => $p->id,
                'invoiceNo' => P::invoiceNo($p),
                'periodStart' => $p->period_start->toDateString(),
                'periodEnd' => $p->period_end->toDateString(),
                'frequency' => $p->frequency,
                'amount' => $amounts[$p->id] ?? P::sen($p->amount),
                'status' => $p->status,
                'url' => route('admin.payments.show', $p),
            ])->values()->all(),
        ];
    }

    /**
     * The latest approved / rejected slips and gateway payments that arrived
     * while a slip was being checked, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function history(Club $club, int $limit): array
    {
        $logs = AuditLog::where('club_id', $club->id)
            ->whereIn('action', array_keys(self::HISTORY_ACTIONS))
            ->where('auditable_type', Payment::class)
            ->latest('id')
            ->limit($limit)
            ->get();
        $payments = Payment::with('user:id,name')->whereIn('id', $logs->pluck('auditable_id'))->get()->keyBy('id');

        return $logs->map(function (AuditLog $log) use ($payments) {
            $p = $payments[$log->auditable_id] ?? null;
            $new = (array) ($log->new_values ?? []);

            return [
                'id' => $log->id,
                'paymentId' => $log->auditable_id,
                'outcome' => self::HISTORY_ACTIONS[$log->action],
                'member' => $p?->user?->name ?? 'Member',
                'amount' => $p ? P::sen($p->amount) : 0,
                'periodStart' => $p?->period_start->toDateString(),
                'periodEnd' => $p?->period_end->toDateString(),
                'by' => $log->user_name,
                'at' => $log->created_at->format('Y-m-d\TH:i'),
                'reason' => $new['reason'] ?? null,
                'reference' => $new['transfer_bank_ref'] ?? $new['transfer_ref'] ?? $new['transaction_id'] ?? null,
                'paidDate' => $new['paid_date'] ?? null,
                // gateway_paid_after_manual: how the admin had recorded it.
                'recordedAs' => isset($new['paid_via']) ? (self::RECORDED_AS[$new['paid_via']] ?? null) : null,
                'url' => $p ? route('admin.payments.show', $p) : null,
            ];
        })->values()->all();
    }

    /** "fee is" or "3 fees are", for a group slip. */
    private function fees(Payment $payment): string
    {
        $group = $payment->openTransferGroup();
        $n = $group ? Payment::whereIn('id', array_keys($group->itemAmounts()))->where('status', 'pending_verification')->count() : 0;

        return $n > 1 ? "{$n} fees are" : 'fee is';
    }

    private function who(Payment $payment): string
    {
        return $payment->user?->name ?? 'The member';
    }

    /** Run an action; success and refusals both land back on the queue. */
    private function act(Payment $payment, callable $fn, string $success): RedirectResponse
    {
        $back = redirect()->route('admin.clubs.verifications.index', $payment->club_id);
        try {
            $fn();
        } catch (ValidationException $e) {
            return $back->withErrors($e->errors());
        }

        return $back->with('success', $success);
    }
}
