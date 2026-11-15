<?php

namespace App\Http\Controllers\Member;

use App\Contracts\PaymentGateway;
use App\Http\Controllers\Controller;
use App\Models\Club;
use App\Models\Payment;
use App\Services\AuditService;
use App\Services\PaymentGateways\PaymentGatewayManager;
use App\Support\Member\MemberPaymentPresenter as P;
use App\Support\Payments\GatewayFee;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Member pay flow. Flash messages are short codes (member.*) that the React
 * pages translate (BM/EN); unknown strings are shown as-is.
 */
class PaymentController extends Controller
{
    public function __construct(protected PaymentGatewayManager $gateways) {}

    public function index(Request $request, Club $club): Response
    {
        $user = auth()->user();

        $this->authorizeClubMembership($user, $club);

        // Year selection — default to current year
        $selectedYear = (int) $request->get('year', now()->year);

        // Available years: from earliest payment or current year, up to next year
        $earliestDate = $club->payments()->where('user_id', $user->id)->min('period_start');
        $minYear = $earliestDate ? (int) date('Y', strtotime($earliestDate)) : now()->year;
        $maxYear = now()->year + 1;
        $years = range($maxYear, min($minYear, $maxYear));

        $payments = $club->payments()
            ->where('user_id', $user->id)
            ->whereYear('due_date', $selectedYear)
            ->orderByDesc('due_date')
            ->paginate(15)
            ->withQueryString();

        // Annual summary — 1 query instead of 5
        $annualStats = $club->payments()
            ->where('user_id', $user->id)
            ->whereYear('due_date', $selectedYear)
            // A pending fee past its due date counts as overdue (see MemberPaymentPresenter::displayStatus).
            ->selectRaw("
                SUM(CASE WHEN status = 'paid' THEN amount ELSE 0 END) as paid,
                SUM(CASE WHEN status = 'pending' AND due_date >= ? THEN amount ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'overdue' OR (status = 'pending' AND due_date < ?) THEN amount ELSE 0 END) as overdue,
                SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) as paid_count,
                SUM(amount) as total
            ", [today()->toDateString(), today()->toDateString()])
            ->first();

        return Inertia::render('Member/Payments', [
            'club' => $club->only(['id', 'name']),
            'selectedYear' => $selectedYear,
            'years' => $years,
            'summary' => [
                'paid' => P::sen($annualStats->paid ?? 0),
                'pending' => P::sen($annualStats->pending ?? 0),
                'overdue' => P::sen($annualStats->overdue ?? 0),
                'paidCount' => (int) ($annualStats->paid_count ?? 0),
                'total' => P::sen($annualStats->total ?? 0),
            ],
            'payments' => collect($payments->items())->map(fn (Payment $p) => P::summary($p))->values(),
            'pagination' => [
                'currentPage' => $payments->currentPage(),
                'lastPage' => $payments->lastPage(),
                'total' => $payments->total(),
                'prevUrl' => $payments->previousPageUrl(),
                'nextUrl' => $payments->nextPageUrl(),
            ],
            'futureForm' => $this->futureFormProps($club, $user),
            'paymentsUrl' => route('member.payments.index', $club),
            'dashboardUrl' => route('member.dashboard'),
        ]);
    }

    public function invoice(Payment $payment): Response
    {
        $this->authorizePayment($payment);
        $payment->load(['club', 'user', 'discount']);

        // payments.notes are admin-only (see admin payment form); never sent to members.
        return Inertia::render('Member/Invoice', [
            'payment' => P::summary($payment),
            'receipt' => P::receipt($payment),
            'issuedOn' => $payment->created_at?->toDateString(),
            'discount' => $payment->discount ? [
                'name' => $payment->discount->name,
                'type' => $payment->discount->type,
                'value' => $payment->discount->type === 'fixed'
                    ? P::sen($payment->discount->value)
                    : (float) $payment->discount->value,
            ] : null,
            ...$this->context($payment),
        ]);
    }

    /**
     * Review & pay: the confirm step before handing off to the gateway.
     */
    public function review(Payment $payment)
    {
        $this->authorizePayment($payment);

        if ($payment->status === 'paid') {
            return redirect()->route('member.payments.invoice', $payment)
                ->with('success', 'member.already_paid');
        }

        $payment->load(['club', 'user']);
        $gateway = $this->gateways->for($payment->club);
        $hasOpenBill = $this->openBillFor($payment, $gateway) !== null;
        // An open bill is reused as it is, so show the fee mode it was created
        // with; otherwise the club's current setting (what a new bill will use).
        $fee = $hasOpenBill ? self::storedFee($payment) : GatewayFee::forClub($payment->club);
        $amountSen = P::sen($payment->amount);

        return Inertia::render('Member/ReviewPay', [
            'payment' => P::summary($payment),
            // What the member will be charged: the fee amount, plus the gateway
            // charge only when the bill passes it on to the member.
            'charges' => [
                'payer' => $fee->payer,
                'feeSen' => $fee->memberSurchargeSen(),
                'totalSen' => $fee->totalSen($amountSen),
            ],
            'gateway' => [
                'name' => $gateway->name(),
                'label' => P::gatewayLabel($gateway->name()),
                'available' => $gateway->isConfiguredForClub($payment->club),
            ],
            // A bill was already opened for this fee: warn before paying again.
            'hasOpenBill' => $hasOpenBill,
            'payUrl' => route('member.payments.pay', $payment),
            ...$this->context($payment),
        ]);
    }

    public function pay(Payment $payment)
    {
        $this->authorizePayment($payment);

        if ($payment->status === 'paid') {
            return redirect()->route('member.payments.invoice', $payment)
                ->with('error', 'member.already_paid');
        }

        $club = $payment->club;
        $gateway = $this->gateways->for($club);

        if (! $gateway->isConfiguredForClub($club)) {
            return redirect()->route('member.payments.review', $payment)
                ->with('error', 'member.gateway_unavailable');
        }

        // Reuse the bill already opened at this gateway instead of creating a
        // second one: a new bill_code would orphan the first, and a payment
        // made against the orphan would never be matched by the webhook.
        if ($url = $this->openBillFor($payment, $gateway)) {
            return Inertia::location($url);
        }

        $user = auth()->user();
        $fee = GatewayFee::forClub($club);
        $refNo = 'PAY-'.$payment->id.'-'.time();
        $callbackRoute = match ($gateway->name()) {
            'toyyibpay' => 'webhook.toyyibpay',
            'billplz' => 'webhook.billplz',
            default => throw new \LogicException("No webhook route registered for gateway '{$gateway->name()}'"),
        };

        $result = $gateway->createBill([
            'club' => $club,
            'bill_name' => "Club Fee – {$club->name}",
            'description' => "Payment for {$payment->period_start->format('M Y')} to {$payment->period_end->format('M Y')}",
            'amount' => $payment->amount,
            'return_url' => route('member.payments.thankyou', $payment),
            'callback_url' => route($callbackRoute),
            'reference_no' => $refNo,
            'payer_name' => $user->name,
            'payer_email' => $user->email,
            'club_name' => $club->name,
            'fee' => $fee,
        ]);

        if (! $result) {
            return redirect()->route('member.payments.review', $payment)
                ->with('error', 'member.bill_failed');
        }

        $billFields = ['bill_code', 'bill_amount', 'bill_fee_payer', 'bill_fee_sen', 'gateway', 'reference'];
        $old = $payment->only($billFields);

        // bill_amount is the fee amount; the fee mode is stored alongside so the
        // bill is reused only while amount, gateway and fee mode all still match,
        // and so the dashboard can total the fees the club absorbed.
        $payment->update([
            'bill_code' => $result->billCode,
            'bill_amount' => $payment->amount,
            'bill_fee_payer' => $fee->payer,
            'bill_fee_sen' => $fee->feeSen,
            'reference' => $refNo,
            'gateway' => $result->gateway,
        ]);

        // An earlier bill (other gateway, or a different amount) is superseded:
        // record it so a late payment against it can be reconciled by hand.
        if (! empty($old['bill_code'])) {
            AuditService::log(
                'payment.bill_replaced',
                "Payment #{$payment->id}: bill {$old['bill_code']} (".($old['gateway'] ?? 'n/a').") replaced by {$result->billCode} ({$result->gateway}).",
                $payment,
                $payment->club_id,
                $old,
                $payment->only($billFields)
            );
        }

        // A 409 + X-Inertia-Location for Inertia visits, a plain redirect otherwise.
        return Inertia::location($result->paymentUrl);
    }

    /**
     * JSON status for the PayResult page's polling. Owner only.
     */
    public function status(Payment $payment): JsonResponse
    {
        $this->authorizePayment($payment);

        return response()->json(['status' => P::displayStatus($payment)])
            ->header('Cache-Control', 'no-store');
    }

    /**
     * Pay ahead: one fee record covering 1, 3 or 12 months (as the club allows),
     * priced at the member's monthly rate × months. Starts at most 12 months
     * ahead and never overlaps a period the member already has.
     */
    public function generateFuture(Request $request, Club $club)
    {
        $user = auth()->user();
        $this->authorizeActiveMembership($user, $club);

        $request->validate([
            'period_start' => 'required|date|after:today',
            'frequency' => 'required|in:monthly,quarterly,yearly',
        ]);

        if (! in_array($request->frequency, $club->allowedPlans(), true)) {
            return back()->with('error', 'member.plan_not_allowed');
        }

        $monthlySen = $this->monthlyFeeSen($club, $user);
        if ($monthlySen === null) {
            return back()->with('error', 'member.no_fee_rate');
        }

        $months = Club::PLAN_MONTHS[$request->frequency];
        $start = Carbon::parse($request->period_start)->startOfMonth();
        $end = $start->copy()->addMonthsNoOverflow($months)->subDay();

        // Pay ahead means a coming month: the current month's fee is billed by the club.
        if ($start->lt(now()->addMonthNoOverflow()->startOfMonth())) {
            return back()->with('error', 'member.start_next_month');
        }
        if ($end->gt(self::latestFutureEnd())) {
            return back()->with('error', 'member.too_far_ahead');
        }

        // Serialise per member+club so two quick submits can't both pass the
        // overlap check (the unique index also guards the same start month).
        $lock = Cache::lock("pay-ahead:{$club->id}:{$user->id}", 10);
        if (! $lock->get()) {
            return back()->with('error', 'member.try_again');
        }

        try {
            $overlaps = $club->payments()
                ->where('user_id', $user->id)
                ->whereDate('period_start', '<=', $end->toDateString())
                ->whereDate('period_end', '>=', $start->toDateString())
                ->exists();

            if ($overlaps) {
                return back()->with('error', 'member.period_overlaps');
            }

            try {
                $payment = Payment::create([
                    'club_id' => $club->id,
                    'user_id' => $user->id,
                    'recorded_by' => $user->id,
                    'amount' => number_format(($monthlySen * $months) / 100, 2, '.', ''),
                    'frequency' => $request->frequency,
                    'period_start' => $start->toDateString(),
                    'period_end' => $end->toDateString(),
                    'due_date' => $start->toDateString(),
                    'status' => 'pending',
                ]);
            } catch (QueryException $e) {
                // Unique (club, user, period_start) lost a race.
                return back()->with('error', 'member.period_overlaps');
            }
        } finally {
            $lock->release();
        }

        AuditService::log(
            'payment.pay_ahead_created',
            "Payment #{$payment->id} created by the member to pay ahead ({$request->frequency}, {$start->toDateString()} to {$end->toDateString()}).",
            $payment,
            $club->id,
            [],
            $payment->only(['amount', 'frequency', 'period_start', 'period_end'])
        );

        return redirect()->route('member.payments.review', $payment)
            ->with('success', 'member.future_created');
    }

    /** The fee mode an existing bill was created with (legacy bills: club absorbed). */
    private static function storedFee(Payment $payment): GatewayFee
    {
        return $payment->bill_fee_payer === null
            ? new GatewayFee('club', 0)
            : new GatewayFee($payment->bill_fee_payer, (int) $payment->bill_fee_sen);
    }

    /**
     * Pay-ahead cap: a period may run at most to the end of the month 12 months
     * from now (so a monthly fee can start 12 months ahead, a yearly one at most
     * next month). Capping the end, not just the start, stops a yearly plan
     * starting a year out from billing almost two years ahead.
     */
    public static function latestFutureEnd(): Carbon
    {
        return now()->startOfMonth()->addMonthsNoOverflow(12)->endOfMonth()->startOfDay();
    }

    /** The member's current monthly fee in sen, or null when no rate is set. */
    private function monthlyFeeSen(Club $club, $user): ?int
    {
        $level = $club->members()->where('users.id', $user->id)->first()?->pivot->job_level;
        $feeRate = $club->feeRates()->where('job_level', $level)->whereNull('effective_to')->first();

        return $feeRate ? P::sen($feeRate->monthly_amount) : null;
    }

    /**
     * Pay-ahead chooser props: plans the club allows, prices, the fee mode for
     * the savings hint and a sensible first month (after the member's last period).
     *
     * @return array<string, mixed>
     */
    private function futureFormProps(Club $club, $user): array
    {
        $nextMonth = now()->addMonthNoOverflow()->startOfMonth();
        $maxEnd = self::latestFutureEnd();

        // Months already covered by one of the member's fees (any status).
        $periods = $club->payments()->where('user_id', $user->id)
            ->whereDate('period_end', '>=', $nextMonth->toDateString())
            ->get(['period_start', 'period_end']);
        $free = [];
        for ($m = $nextMonth->copy(); $m->lte($maxEnd); $m->addMonthNoOverflow()) {
            $monthEnd = $m->copy()->endOfMonth();
            $covered = $periods->contains(fn ($p) => $p->period_start->lte($monthEnd) && $p->period_end->gte($m));
            if (! $covered) {
                $free[] = $m->toDateString();
            }
        }
        $paidUntil = $club->payments()->where('user_id', $user->id)->max('period_end');
        $fee = GatewayFee::forClub($club);

        return [
            'action' => route('member.payments.generate-future', $club),
            // Start months not yet covered; the first is the default. Empty when
            // the member already has fees up to the cap.
            'freeMonths' => $free,
            'defaultStart' => $free[0] ?? null,
            'maxEnd' => $maxEnd->toDateString(),
            'paidUntil' => $paidUntil ? Carbon::parse($paidUntil)->toDateString() : null,
            'plans' => $club->allowedPlans(),
            'defaultPlan' => $club->defaultPlan(),
            'monthlySen' => $this->monthlyFeeSen($club, $user),
            'feePayer' => $fee->payer,
            'feeSen' => $fee->feeSen,
        ];
    }

    /**
     * Gateway return URL. The redirect back is not proof of payment: only the
     * stored status (set by the verified webhook) can show success. The
     * gateway's query string is used solely to tell "failed" from "pending".
     */
    public function thankyou(Request $request, Payment $payment)
    {
        $this->authorizePayment($payment);

        // No bill was ever opened: there is nothing to report on yet.
        if ($payment->status !== 'paid' && empty($payment->bill_code)) {
            return redirect()->route('member.payments.review', $payment);
        }

        $payment->load(['club', 'user']);

        $outcome = match (true) {
            $payment->status === 'paid' => 'paid',
            $this->gatewayReportsFailure($request) => 'failed',
            default => 'pending',
        };

        return Inertia::render('Member/PayResult', [
            'outcome' => $outcome,
            'payment' => P::summary($payment),
            'receipt' => P::receipt($payment),
            'gateway' => P::gatewayLabel($payment->gateway ?? $payment->club->activeGateway()),
            'reference' => $payment->reference,
            'statusUrl' => route('member.payments.status', $payment),
            'reviewUrl' => route('member.payments.review', $payment),
            ...$this->context($payment),
        ]);
    }

    /**
     * ToyyibPay returns ?status_id=3 for a failed/cancelled payment; Billplz
     * returns billplz[paid]=false. Anything else is treated as still pending.
     */
    private function gatewayReportsFailure(Request $request): bool
    {
        if ($request->query('status_id') === '3') {
            return true;
        }

        $billplz = $request->query('billplz');

        return is_array($billplz) && ($billplz['paid'] ?? null) === 'false';
    }

    /**
     * The payment URL of the bill already opened for this fee at the club's
     * current gateway and for the current amount (whatever fee mode it was
     * created with), or null when there is none to reuse.
     */
    private function openBillFor(Payment $payment, PaymentGateway $gateway): ?string
    {
        // The fee mode is deliberately not compared: a later change to the club's
        // fee setting must not orphan a bill the member may already be paying
        // (its webhook would no longer match). The bill keeps its stored mode.
        if ($payment->status === 'paid' || empty($payment->bill_code) || $payment->gateway !== $gateway->name()
            || ! $payment->billMatchesAmount()) {
            return null;
        }

        return $gateway->paymentUrl($payment->bill_code);
    }

    /**
     * Props shared by the single-payment pages: member, club and nav links.
     *
     * @return array<string, mixed>
     */
    private function context(Payment $payment): array
    {
        return [
            'member' => ['name' => $payment->user->name, 'email' => $payment->user->email],
            'payClub' => ['id' => $payment->club->id, 'name' => $payment->club->name, 'email' => $payment->club->email],
            'paymentsUrl' => route('member.payments.index', $payment->club_id),
            'dashboardUrl' => route('member.dashboard'),
        ];
    }

    private function authorizeClubMembership($user, Club $club): void
    {
        $isMember = $club->members()->where('users.id', $user->id)->exists();
        if (! $isMember) {
            abort(403, 'You are not a member of this club.');
        }
    }

    /** Active member of an active club (pay-ahead creates new fees). */
    private function authorizeActiveMembership($user, Club $club): void
    {
        $active = $club->is_active
            && $club->members()->where('users.id', $user->id)->where('club_user.is_active', true)->exists();
        if (! $active) {
            abort(403, 'You are not an active member of this club.');
        }
    }

    /**
     * The member owns the fee and is still an active member of an active club.
     */
    private function authorizePayment(Payment $payment): void
    {
        if ($payment->user_id !== auth()->id()) {
            abort(403);
        }

        $activeMember = $payment->club()
            ->where('is_active', true)
            ->whereHas('members', fn ($q) => $q->where('users.id', auth()->id())->where('club_user.is_active', true))
            ->exists();

        if (! $activeMember) {
            abort(403, 'You are not an active member of this club.');
        }
    }
}
