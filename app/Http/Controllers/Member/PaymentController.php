<?php

namespace App\Http\Controllers\Member;

use App\Http\Controllers\Controller;
use App\Models\Club;
use App\Models\Payment;
use App\Services\PaymentGateways\PaymentGatewayManager;
use Carbon\Carbon;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function __construct(protected PaymentGatewayManager $gateways) {}

    public function index(Request $request, Club $club)
    {
        $user = auth()->user();

        $this->authorizeClubMembership($user, $club);

        // Year selection — default to current year
        $selectedYear = (int) $request->get('year', now()->year);

        // Available years: from earliest payment or current year, up to next year
        $earliestDate = $club->payments()->where('user_id', $user->id)->min('period_start');
        $minYear = $earliestDate ? (int) date('Y', strtotime($earliestDate)) : now()->year;
        $maxYear = now()->year + 1;
        $years = range($maxYear, $minYear);

        $payments = $club->payments()
            ->where('user_id', $user->id)
            ->whereYear('due_date', $selectedYear)
            ->orderByDesc('due_date')
            ->paginate(15);

        // Annual summary — 1 query instead of 5
        $annualStats = $club->payments()
            ->where('user_id', $user->id)
            ->whereYear('due_date', $selectedYear)
            ->selectRaw("
                SUM(CASE WHEN status = 'paid'    THEN amount ELSE 0 END) as paid,
                SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'overdue' THEN amount ELSE 0 END) as overdue,
                SUM(CASE WHEN status = 'paid'    THEN 1 ELSE 0 END) as paid_count,
                SUM(amount) as total
            ")
            ->first();

        $annualSummary = [
            'paid' => (float) ($annualStats->paid ?? 0),
            'pending' => (float) ($annualStats->pending ?? 0),
            'overdue' => (float) ($annualStats->overdue ?? 0),
            'paid_count' => (int) ($annualStats->paid_count ?? 0),
            'total' => (float) ($annualStats->total ?? 0),
        ];

        return view('member.payments.index', compact('club', 'payments', 'selectedYear', 'years', 'annualSummary'));
    }

    public function invoice(Payment $payment)
    {
        $this->authorizePayment($payment);
        $payment->load(['club', 'user', 'discount']);

        return view('member.payments.invoice', compact('payment'));
    }

    public function pay(Payment $payment)
    {
        $this->authorizePayment($payment);

        if ($payment->status === 'paid') {
            return back()->with('error', 'This payment has already been paid.');
        }

        $club = $payment->club;
        $gateway = $this->gateways->for($club);

        if (! $gateway->isConfiguredForClub($club)) {
            return back()->with('error', 'Online payment is not available for this club yet. Please contact the club administrator.');
        }

        $user = auth()->user();
        $refNo = 'PAY-'.$payment->id.'-'.time();
        $callbackRoute = $gateway->name() === 'billplz' ? 'webhook.billplz' : 'webhook.toyyibpay';

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
        ]);

        if (! $result) {
            return back()->with('error', 'Unable to create payment bill. Please try again.');
        }

        $payment->update([
            'bill_code' => $result->billCode,
            'reference' => $refNo,
            'gateway' => $result->gateway,
        ]);

        return redirect($result->paymentUrl);
    }

    public function generateFuture(Request $request, Club $club)
    {
        $user = auth()->user();
        $this->authorizeClubMembership($user, $club);

        $request->validate([
            'period_start' => 'required|date|after:today',
            'frequency' => 'required|in:monthly,quarterly,yearly',
        ]);

        $level = $club->members()->where('users.id', $user->id)->first()?->pivot->job_level;
        $feeRate = $club->feeRates()->where('job_level', $level)->whereNull('effective_to')->first();

        if (! $feeRate) {
            return back()->with('error', 'No fee rate configured for your job level.');
        }

        $start = Carbon::parse($request->period_start)->startOfMonth();
        $multipliers = ['monthly' => 1, 'quarterly' => 3, 'yearly' => 12];
        $end = $start->copy()->addMonths($multipliers[$request->frequency])->subDay();

        // Check if payment already exists for this period
        $exists = $club->payments()
            ->where('user_id', $user->id)
            ->where('period_start', $start->toDateString())
            ->exists();

        if ($exists) {
            return back()->with('error', 'A payment record already exists for this period.');
        }

        $payment = Payment::create([
            'club_id' => $club->id,
            'user_id' => $user->id,
            'recorded_by' => $user->id,
            'amount' => $feeRate->monthly_amount * $multipliers[$request->frequency],
            'frequency' => $request->frequency,
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
            'due_date' => $start->toDateString(),
            'status' => 'pending',
        ]);

        return redirect()->route('member.payments.invoice', $payment)
            ->with('success', 'Future payment created. Proceed to pay.');
    }

    public function thankyou(Payment $payment)
    {
        $this->authorizePayment($payment);
        $payment->load(['club', 'user']);

        return view('member.payments.thankyou', compact('payment'));
    }

    private function authorizeClubMembership($user, Club $club): void
    {
        $isMember = $club->members()->where('users.id', $user->id)->exists();
        if (! $isMember) {
            abort(403, 'You are not a member of this club.');
        }
    }

    private function authorizePayment(Payment $payment): void
    {
        if ($payment->user_id !== auth()->id()) {
            abort(403);
        }
    }
}
