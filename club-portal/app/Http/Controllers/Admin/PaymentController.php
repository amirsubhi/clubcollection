<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\AuthorizesClubResource;
use App\Http\Controllers\Controller;
use App\Models\Club;
use App\Models\Discount;
use App\Models\FeeRate;
use App\Models\Payment;
use Carbon\Carbon;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    use AuthorizesClubResource;
    public function index(Club $club)
    {
        $status    = request('status');
        $month     = request('month', now()->format('Y-m'));
        $jobLevel  = request('job_level');

        $query = $club->payments()->with(['user', 'discount'])
            ->orderByDesc('due_date');

        if ($status) {
            $query->where('payments.status', $status);
        }

        if ($month) {
            $query->whereRaw("strftime('%Y-%m', period_start) = ?", [$month]);
        }

        if ($jobLevel) {
            // JOIN is more efficient than nested whereHas for filtering on a pivot column
            $query->select('payments.*')
                  ->join('club_user', function ($join) use ($club, $jobLevel) {
                      $join->on('payments.user_id', '=', 'club_user.user_id')
                           ->where('club_user.club_id', $club->id)
                           ->where('club_user.job_level', $jobLevel);
                  });
        }

        $payments = $query->paginate(20)->withQueryString();

        // 1 query instead of 3 separate status queries
        $summaryStats = $club->payments()
            ->selectRaw("
                SUM(CASE WHEN status = 'paid'    THEN amount ELSE 0 END) as total_paid,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as total_pending,
                SUM(CASE WHEN status = 'overdue' THEN 1 ELSE 0 END) as total_overdue
            ")
            ->first();

        $summary = [
            'total_paid'    => (float) ($summaryStats->total_paid    ?? 0),
            'total_pending' => (int)   ($summaryStats->total_pending ?? 0),
            'total_overdue' => (int)   ($summaryStats->total_overdue ?? 0),
        ];

        $jobLevels = FeeRate::jobLevelLabels();

        return view('admin.payments.index', compact('club', 'payments', 'summary', 'jobLevels', 'status', 'month', 'jobLevel'));
    }

    public function create(Club $club)
    {
        $members   = $club->members()->wherePivot('is_active', true)->get();
        $discounts = $club->discounts()->where('is_active', true)->get();
        $feeRates  = $club->feeRates()->whereNull('effective_to')->get()->keyBy('job_level');
        $jobLevels = FeeRate::jobLevelLabels();

        return view('admin.payments.create', compact('club', 'members', 'discounts', 'feeRates', 'jobLevels'));
    }

    public function store(Request $request, Club $club)
    {
        $data = $request->validate([
            'user_id'      => [
                'required',
                'exists:users,id',
                function ($attribute, $value, $fail) use ($club) {
                    if (!$club->members()->where('users.id', $value)->exists()) {
                        $fail('The selected member does not belong to this club.');
                    }
                },
            ],
            'frequency'    => 'required|in:monthly,quarterly,yearly',
            'period_start' => 'required|date',
            'due_date'     => 'required|date',
            'amount'       => 'required|numeric|min:0',
            'discount_id'  => [
                'nullable',
                'exists:discounts,id',
                // Ensure discount belongs to this club
                function ($attribute, $value, $fail) use ($club) {
                    if ($value && !$club->discounts()->where('id', $value)->exists()) {
                        $fail('The selected discount does not belong to this club.');
                    }
                },
            ],
            'notes'        => 'nullable|string|max:500',
        ]);

        $start = Carbon::parse($data['period_start']);
        $end   = match($data['frequency']) {
            'monthly'   => $start->copy()->endOfMonth(),
            'quarterly' => $start->copy()->addMonths(3)->subDay(),
            'yearly'    => $start->copy()->addYear()->subDay(),
        };

        Payment::create([
            'club_id'      => $club->id,
            'user_id'      => $data['user_id'],
            'recorded_by'  => auth()->id(),
            'amount'       => $data['amount'],
            'frequency'    => $data['frequency'],
            'period_start' => $start->toDateString(),
            'period_end'   => $end->toDateString(),
            'due_date'     => $data['due_date'],
            'paid_date'    => null,
            'status'       => 'pending',
            'discount_id'  => $data['discount_id'] ?? null,
            'notes'        => $data['notes'] ?? null,
        ]);

        return redirect()->route('admin.payments.index', $club)
            ->with('success', 'Payment record created.');
    }

    public function show(Payment $payment)
    {
        $this->authorizeClubAdmin($payment->club);
        $payment->load(['user', 'club', 'discount', 'recordedBy']);
        return view('admin.payments.show', compact('payment'));
    }

    public function edit(Payment $payment)
    {
        $this->authorizeClubAdmin($payment->club);
        $club      = $payment->club;
        $members   = $club->members()->wherePivot('is_active', true)->get();
        $discounts = $club->discounts()->where('is_active', true)->get();
        return view('admin.payments.edit', compact('payment', 'club', 'members', 'discounts'));
    }

    public function update(Request $request, Payment $payment)
    {
        $this->authorizeClubAdmin($payment->club);
        $data = $request->validate([
            'due_date'    => 'required|date',
            'amount'      => 'required|numeric|min:0',
            'status'      => 'required|in:pending,paid,overdue',
            'paid_date'   => 'nullable|date',
            'discount_id' => [
                'nullable',
                'exists:discounts,id',
                function ($attribute, $value, $fail) use ($payment) {
                    if ($value && ! $payment->club->discounts()->where('id', $value)->exists()) {
                        $fail('The selected discount does not belong to this club.');
                    }
                },
            ],
            'notes'       => 'nullable|string|max:500',
            'reference'   => 'nullable|string|max:255',
        ]);

        if ($data['status'] === 'paid' && empty($data['paid_date'])) {
            $data['paid_date'] = now()->toDateString();
        }

        $payment->update($data);

        return redirect()->route('admin.payments.show', $payment)
            ->with('success', 'Payment updated.');
    }

    public function markPaid(Request $request, Payment $payment)
    {
        $this->authorizeClubAdmin($payment->club);
        $request->validate(['reference' => 'nullable|string|max:255']);

        $payment->update([
            'status'    => 'paid',
            'paid_date' => now()->toDateString(),
            'reference' => $request->reference,
        ]);

        return back()->with('success', 'Payment marked as paid.');
    }

    public function destroy(Payment $payment)
    {
        $this->authorizeClubAdmin($payment->club);
        $club = $payment->club;
        $payment->delete();
        return redirect()->route('admin.payments.index', $club)
            ->with('success', 'Payment record deleted.');
    }
}
