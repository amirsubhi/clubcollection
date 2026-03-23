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
            $query->where('status', $status);
        }

        if ($month) {
            $query->whereRaw("strftime('%Y-%m', period_start) = ?", [$month]);
        }

        if ($jobLevel) {
            $query->whereHas('user', function ($q) use ($club, $jobLevel) {
                $q->whereHas('clubs', function ($q2) use ($club, $jobLevel) {
                    $q2->where('clubs.id', $club->id)->where('club_user.job_level', $jobLevel);
                });
            });
        }

        $payments = $query->paginate(20)->withQueryString();

        $summary = [
            'total_paid'    => $club->payments()->where('status', 'paid')->sum('amount'),
            'total_pending' => $club->payments()->where('status', 'pending')->count(),
            'total_overdue' => $club->payments()->where('status', 'overdue')->count(),
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
            'user_id'      => 'required|exists:users,id',
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
