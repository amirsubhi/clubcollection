<?php

namespace App\Http\Controllers\Member;

use App\Http\Controllers\Controller;
use App\Mail\PaymentConfirmation;
use App\Models\Club;
use App\Models\FeeRate;
use App\Models\Payment;
use App\Services\ToyyibPayService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class PaymentController extends Controller
{
    public function __construct(protected ToyyibPayService $toyyibPay) {}

    public function index(Request $request, Club $club)
    {
        $user = auth()->user();

        $this->authorizeClubMembership($user, $club);

        // Year selection — default to current year
        $selectedYear = (int) $request->get('year', now()->year);

        // Available years: from earliest payment or 2 years back, up to next year
        $earliestYear = $club->payments()
            ->where('user_id', $user->id)
            ->min(\Illuminate\Support\Facades\DB::raw('strftime("%Y", period_start)'));
        $minYear  = $earliestYear ? (int) $earliestYear : now()->year;
        $maxYear  = now()->year + 1;
        $years    = range($maxYear, $minYear);

        $payments = $club->payments()
            ->where('user_id', $user->id)
            ->whereYear('due_date', $selectedYear)
            ->orderByDesc('due_date')
            ->paginate(15);

        // Annual summary for the selected year
        $annualSummary = [
            'paid'       => $club->payments()->where('user_id', $user->id)->whereYear('due_date', $selectedYear)->where('status', 'paid')->sum('amount'),
            'pending'    => $club->payments()->where('user_id', $user->id)->whereYear('due_date', $selectedYear)->where('status', 'pending')->sum('amount'),
            'overdue'    => $club->payments()->where('user_id', $user->id)->whereYear('due_date', $selectedYear)->where('status', 'overdue')->sum('amount'),
            'paid_count' => $club->payments()->where('user_id', $user->id)->whereYear('due_date', $selectedYear)->where('status', 'paid')->count(),
            'total'      => $club->payments()->where('user_id', $user->id)->whereYear('due_date', $selectedYear)->sum('amount'),
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

        if (!$this->toyyibPay->isConfiguredForClub($club)) {
            return back()->with('error', 'Online payment is not available for this club yet. Please contact the club administrator.');
        }

        $user  = auth()->user();
        $refNo = 'PAY-' . $payment->id . '-' . time();

        $billCode = $this->toyyibPay->createBill([
            'club'         => $club,   // service picks up club's own credentials
            'bill_name'    => "Club Fee – {$club->name}",
            'description'  => "Payment for {$payment->period_start->format('M Y')} to {$payment->period_end->format('M Y')}",
            'amount'       => $payment->amount,
            'return_url'   => route('member.payments.thankyou', $payment),
            'callback_url' => route('webhook.toyyibpay'),
            'reference_no' => $refNo,
            'payer_name'   => $user->name,
            'payer_email'  => $user->email,
            'club_name'    => $club->name,
        ]);

        if (!$billCode) {
            return back()->with('error', 'Unable to create payment bill. Please try again.');
        }

        $payment->update([
            'bill_code' => $billCode,
            'reference' => $refNo,
        ]);

        return redirect($this->toyyibPay->paymentUrl($billCode));
    }

    public function generateFuture(Request $request, Club $club)
    {
        $user = auth()->user();
        $this->authorizeClubMembership($user, $club);

        $request->validate([
            'period_start' => 'required|date|after:today',
            'frequency'    => 'required|in:monthly,quarterly,yearly',
        ]);

        $level    = $club->members()->where('users.id', $user->id)->first()?->pivot->job_level;
        $feeRate  = $club->feeRates()->where('job_level', $level)->whereNull('effective_to')->first();

        if (!$feeRate) {
            return back()->with('error', 'No fee rate configured for your job level.');
        }

        $start = Carbon::parse($request->period_start)->startOfMonth();
        $multipliers = ['monthly' => 1, 'quarterly' => 3, 'yearly' => 12];
        $end   = $start->copy()->addMonths($multipliers[$request->frequency])->subDay();

        // Check if payment already exists for this period
        $exists = $club->payments()
            ->where('user_id', $user->id)
            ->where('period_start', $start->toDateString())
            ->exists();

        if ($exists) {
            return back()->with('error', 'A payment record already exists for this period.');
        }

        $payment = Payment::create([
            'club_id'      => $club->id,
            'user_id'      => $user->id,
            'recorded_by'  => $user->id,
            'amount'       => $feeRate->monthly_amount * $multipliers[$request->frequency],
            'frequency'    => $request->frequency,
            'period_start' => $start->toDateString(),
            'period_end'   => $end->toDateString(),
            'due_date'     => $start->toDateString(),
            'status'       => 'pending',
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
        if (!$isMember) {
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
