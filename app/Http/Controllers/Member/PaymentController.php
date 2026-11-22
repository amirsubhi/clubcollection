<?php

namespace App\Http\Controllers\Member;

use App\Contracts\PaymentGateway;
use App\Http\Controllers\Controller;
use App\Models\Attachment;
use App\Models\Club;
use App\Models\Payment;
use App\Models\PaymentBill;
use App\Services\AttachmentService;
use App\Services\AuditService;
use App\Services\Payments\FeeBillService;
use App\Services\Payments\SettlementService;
use App\Services\TransferVerificationService;
use App\Services\PaymentGateways\PaymentGatewayManager;
use App\Support\Member\MemberPaymentPresenter as P;
use App\Support\Payments\DisplayStatus;
use App\Support\Payments\GatewayFee;
use App\Support\Payments\OpenFeeSet;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

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

        // Only an active member of an active club (G2): a deactivated club or
        // membership is not listed on the home page and its fees can't be paid.
        $this->authorizeActiveMembership($user, $club);

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

        // Annual summary by DisplayStatus (grace period, FPX bills in progress):
        // one year of one member's fees, so loading them is cheap.
        $yearFees = $club->payments()
            ->where('user_id', $user->id)
            ->whereYear('due_date', $selectedYear)
            ->get(['id', 'club_id', 'status', 'due_date', 'amount']);
        $yearStatuses = DisplayStatus::many($yearFees);
        $sumOf = fn (string $status) => $yearFees->filter(fn (Payment $p) => $yearStatuses[$p->id] === $status)->sum(fn (Payment $p) => P::sen($p->amount));
        DisplayStatus::prime($payments->getCollection());

        return Inertia::render('Member/Payments', [
            'club' => $club->only(['id', 'name']),
            'selectedYear' => $selectedYear,
            'years' => $years,
            'summary' => [
                'paid' => $sumOf(DisplayStatus::PAID),
                'due' => $sumOf(DisplayStatus::DUE),
                'overdue' => $sumOf(DisplayStatus::OVERDUE),
                // FPX payment started, bank not confirmed yet: maybe paid already.
                'processing' => $sumOf(DisplayStatus::PROCESSING),
                // Slip sent, treasurer still checking: neither paid nor owed.
                'verifying' => $sumOf(DisplayStatus::VERIFYING),
                'paidCount' => count(array_filter($yearStatuses, fn ($st) => $st === DisplayStatus::PAID)),
                'total' => $yearFees->sum(fn (Payment $p) => P::sen($p->amount)),
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
            // "Bayar semua": every fee still owed in the club (any year), from two on.
            'payAll' => $this->payAllProps($club, $user),
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
            // The latest cancelled payment of this fee: a banner while unpaid, a small note once paid again.
            'cancellation' => P::cancellation($payment),
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
        $openGroup = $payment->status === 'paid' || $payment->isVerifying() ? null
            : PaymentBill::openGatewayGroupsContaining($payment->id)->whereNotNull('bill_code')->latest('id')->first();
        // An FPX payment started recently and not confirmed yet: checking it
        // comes first, paying again is the secondary choice (R2a).
        $processing = DisplayStatus::of($payment) === DisplayStatus::PROCESSING;
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
            // A bill was already opened for this fee, on its own or in a "Bayar
            // semua" bill: warn before paying again, and link to the group's status.
            'hasOpenBill' => $hasOpenBill || $openGroup !== null,
            'openBillUrl' => $processing ? DisplayStatus::memberBillUrl($payment)
                : ($openGroup ? route('member.payments.group-result', $openGroup)
                    : ($hasOpenBill ? route('member.payments.thankyou', $payment) : null)),
            'processing' => $processing,
            'payUrl' => route('member.payments.pay', $payment),
            // Manual bank / DuitNow transfer (P2): null when the club doesn't accept it.
            'transfer' => $this->transferProps($payment),
            // A slip is waiting for the treasurer: shown instead of the pay buttons.
            'verification' => $this->verificationProps($payment),
            // The last slip was rejected: the reason, so the member can fix it.
            'rejection' => $payment->status !== 'paid' && ! $payment->isVerifying() && $payment->rejection_reason ? [
                'reason' => $payment->rejection_reason,
                'at' => $payment->verified_at?->toDateString(),
            ] : null,
            ...$this->context($payment),
        ]);
    }

    /**
     * Member uploads a bank / DuitNow transfer slip (1–3 files). The fee waits
     * for a club admin to approve it; it is never marked paid here.
     */
    public function transfer(Request $request, Payment $payment, TransferVerificationService $service)
    {
        $this->authorizePayment($payment);

        if ($payment->status === 'paid') {
            return redirect()->route('member.payments.invoice', $payment)->with('error', 'member.already_paid');
        }

        $data = $request->validate(self::transferRules(), self::transferMessages());

        $service->submit($payment, $request->user(), array_values($data['files']), $data['transfer_date'], $data['transfer_ref'] ?? null);

        return redirect()->route('member.payments.invoice', $payment)->with('success', 'member.slip_sent');
    }

    /** Rules for a transfer slip submission (single fee or "Bayar semua"). */
    public static function transferRules(): array
    {
        return [
            'files' => ['required', 'array', 'min:1', 'max:'.TransferVerificationService::MAX_SLIPS],
            'files.*' => AttachmentService::fileRules(),
            'transfer_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today', 'after_or_equal:'.today()->subYear()->toDateString()],
            'transfer_ref' => ['nullable', 'string', 'max:60'],
        ];
    }

    /** member.* codes the pages translate. */
    public static function transferMessages(): array
    {
        return [
            'files.required' => 'member.slip_required',
            'files.min' => 'member.slip_required',
            'files.max' => 'member.slip_count',
            'files.*.file' => 'member.slip_type',
            'files.*.mimes' => 'member.slip_type',
            'files.*.max' => 'member.slip_size',
            'files.*.uploaded' => 'member.slip_size',
            'transfer_date.required' => 'member.transfer_date_required',
            'transfer_date.date_format' => 'member.transfer_date_invalid',
            'transfer_date.before_or_equal' => 'member.transfer_date_invalid',
            'transfer_date.after_or_equal' => 'member.transfer_date_invalid',
            'transfer_ref.max' => 'member.transfer_ref_long',
        ];
    }

    /**
     * The member's own transfer slip, opened inline (images and PDF). Owner
     * only; audited like the admin document route; never cached.
     */
    public function slip(Attachment $attachment, AttachmentService $service): HttpResponse
    {
        $owner = $attachment->attachable;
        abort_unless(($owner instanceof Payment || ($owner instanceof PaymentBill && $owner->isGroup())) && $attachment->kind === 'proof_of_payment', 404);
        if ($owner instanceof Payment) {
            $this->authorizePayment($owner);
        } else {
            // A slip sent for several fees at once ("Bayar semua") is kept on the group.
            PayAllController::authorizeGroup($owner);
        }

        $contents = $service->contents($attachment);
        $inline = in_array($attachment->mime, ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'], true);

        AuditService::log(
            'attachment.viewed',
            "Member viewed their transfer slip \"{$attachment->original_name}\".",
            $attachment,
            $attachment->club_id,
            [],
            ['name' => $attachment->original_name, 'attachable_id' => $attachment->attachable_id, 'sha256' => $attachment->sha256]
        );

        $ext = preg_replace('/[^A-Za-z0-9]/', '', (string) pathinfo($attachment->original_name, PATHINFO_EXTENSION));

        return response($contents, 200, [
            'Content-Type' => $inline ? $attachment->mime : 'application/octet-stream',
            'Content-Length' => (string) strlen($contents),
            'Content-Disposition' => HeaderUtils::makeDisposition(
                $inline ? HeaderUtils::DISPOSITION_INLINE : HeaderUtils::DISPOSITION_ATTACHMENT,
                $attachment->original_name,
                'slip'.($ext ? '.'.$ext : '')
            ),
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox",
        ]);
    }

    /**
     * Bank details and limits for the transfer option, or null when the club
     * doesn't accept transfers (or the fee is paid or being checked).
     *
     * @return array<string, mixed>|null
     */
    private function transferProps(Payment $payment): ?array
    {
        $club = $payment->club;
        if (! $club->transferReady() || $payment->status === 'paid') {
            return null;
        }

        return [
            'bankName' => $club->bank_name,
            'accountName' => $club->bank_account_name,
            'accountNo' => $club->bank_account_no,
            'duitnowId' => $club->duitnow_id,
            'reference' => $payment->transferReference(),
            'submitUrl' => route('member.payments.transfer', $payment),
            'today' => today()->toDateString(),
            'minDate' => today()->subYear()->toDateString(),
            'maxFiles' => TransferVerificationService::MAX_SLIPS,
            'maxMb' => (int) (Attachment::MAX_KB / 1024),
        ];
    }

    /** @return array<string, mixed>|null */
    private function verificationProps(Payment $payment): ?array
    {
        if (! $payment->isVerifying()) {
            return null;
        }

        return [
            'submittedAt' => $payment->transfer_submitted_at?->format('Y-m-d\TH:i'),
            'transferDate' => $payment->transfer_date?->toDateString(),
            'transferRef' => $payment->transfer_ref,
            'slips' => $payment->currentSlips()->get()
                ->map(fn (Attachment $a) => [
                    'id' => $a->id,
                    'name' => $a->original_name,
                    'isPdf' => $a->mime === 'application/pdf',
                    'url' => route('member.payments.slip', $a),
                ])->values(),
        ];
    }

    public function pay(Payment $payment)
    {
        $this->authorizePayment($payment);

        // One bill at a time per fee: two quick submits (double click, two
        // tabs) must not both open a bill and orphan the first one (S1).
        $lock = Cache::lock("pay:{$payment->id}", 15);
        if (! $lock->get()) {
            return redirect()->route('member.payments.review', $payment)
                ->with('error', 'member.try_again');
        }

        try {
            $fresh = $payment->fresh();
            if (! $fresh) {
                // Deleted by an admin while the member was on the review page.
                return redirect()->route('member.dashboard')->with('error', 'member.try_again');
            }

            return $this->openBill($fresh);
        } finally {
            $lock->release();
        }
    }

    /** pay() under the per-fee lock, on a freshly read row. */
    private function openBill(Payment $payment)
    {
        if ($payment->status === 'paid') {
            return redirect()->route('member.payments.invoice', $payment)
                ->with('error', 'member.already_paid');
        }

        // A slip is being checked: paying online now could take the money twice.
        if ($payment->isVerifying()) {
            return redirect()->route('member.payments.review', $payment)
                ->with('error', 'member.verifying');
        }

        $club = $payment->club;
        $gateway = $this->gateways->for($club);

        if (! $gateway->isConfiguredForClub($club)) {
            return redirect()->route('member.payments.review', $payment)
                ->with('error', 'member.gateway_unavailable');
        }

        // Reuse the bill already opened at this gateway instead of creating a
        // second one. A replaced bill stays in payment_bills, so a late payment
        // against it is still matched by the webhook.
        if ($url = $this->openBillFor($payment, $gateway)) {
            // Sent to pay it again: "in progress" counts from now.
            $payment->bills()->where('bill_code', $payment->bill_code)->whereNull('superseded_at')->first()?->markOpened();

            return Inertia::location($url);
        }

        $result = app(FeeBillService::class)->open($payment, $gateway, auth()->user());

        if (! $result) {
            return redirect()->route('member.payments.review', $payment)
                ->with('error', 'member.bill_failed');
        }

        // A 409 + X-Inertia-Location for Inertia visits, a plain redirect otherwise.
        return Inertia::location($result->paymentUrl);
    }

    /**
     * JSON status for the PayResult page's polling. Owner only.
     */
    public function status(Payment $payment, SettlementService $settlement): JsonResponse
    {
        $this->authorizePayment($payment);

        // "Semak status bayaran" (G1): with an open bill, ask the gateway (at
        // most once per bill every 30 s) in case its callback was lost.
        $settlement->requeryForMember($payment);
        $payment->refresh();

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

    /** @return array<string, mixed>|null */
    private function payAllProps(Club $club, $user): ?array
    {
        $active = $club->is_active && $club->members()->where('users.id', $user->id)->where('club_user.is_active', true)->exists();
        $open = $active ? OpenFeeSet::query($club->id, $user->id)->get(['id', 'club_id', 'status', 'due_date', 'amount']) : collect();
        if ($open->count() < 2) {
            return null;
        }
        // An FPX payment is in progress for one of them: check it before paying the set (R2a).
        if (in_array(DisplayStatus::PROCESSING, DisplayStatus::many($open), true)) {
            return null;
        }

        return [
            'url' => route('member.payments.review-all', $club),
            'totalSen' => $open->sum(fn (Payment $p) => $p->amountSen()),
            'count' => $open->count(),
            'clubName' => $club->name,
        ];
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

        // No bill was ever opened, or a transfer slip is being checked: the
        // review page shows the right state.
        if ($payment->status !== 'paid' && (empty($payment->bill_code) || $payment->isVerifying())) {
            return redirect()->route('member.payments.review', $payment);
        }
        // The payment this page was opened for was cancelled since (its bill already settled once): the fee page
        // shows the cancelled receipt and what to do, rather than "still processing".
        if ($payment->status !== 'paid' && $payment->bills()->where('bill_code', $payment->bill_code)->whereNotNull('paid_at')->exists()
            && $payment->cancellations()->exists()) {
            return redirect()->route('member.payments.invoice', $payment);
        }

        $payment->load(['club', 'user']);

        $outcome = match (true) {
            $payment->status === 'paid' => 'paid',
            self::gatewayReportsFailure($request) => 'failed',
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
    public static function gatewayReportsFailure(Request $request): bool
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
        if ($payment->status === 'paid' || $payment->isVerifying() || empty($payment->bill_code) || $payment->gateway !== $gateway->name()
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
            'payClub' => self::payClubProps($payment->club),
            'paymentsUrl' => route('member.payments.index', $payment->club_id),
            'dashboardUrl' => route('member.dashboard'),
        ];
    }

    /**
     * The club as the payment pages show it, with where "Perlu bantuan?" goes:
     * WhatsApp (digits for wa.me) when the club set a number, else its email.
     *
     * With the club profile as it is now (logo, registration no., address) for invoice headers.
     *
     * @return array{id: int, name: string, email: ?string, whatsapp: ?string, logoUrl: ?string, registrationNo: ?string, address: ?string}
     */
    public static function payClubProps(Club $club): array
    {
        return [
            'id' => $club->id,
            'name' => $club->name,
            'email' => $club->email,
            'whatsapp' => \App\Support\Contact\WhatsAppNumber::waDigits($club->whatsapp_number),
            'logoUrl' => $club->logo_url,
            'registrationNo' => $club->registration_no ?: null,
            'address' => $club->address ?: null,
        ];
    }

    /** Active member of an active club (the payments list and pay-ahead). */
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
