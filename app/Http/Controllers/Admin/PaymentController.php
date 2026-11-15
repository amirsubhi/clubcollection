<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\AuthorizesClubResource;
use App\Http\Controllers\Controller;
use App\Models\Club;
use App\Models\Discount;
use App\Models\FeeRate;
use App\Models\Payment;
use App\Services\AuditService;
use App\Support\Payments\OwnFeeRule;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
            // Portable across SQLite/MySQL/Postgres: build the calendar-month
            // range up front and let Eloquent emit a BETWEEN clause. The
            // previous strftime() call only worked on SQLite.
            try {
                $start = Carbon::createFromFormat('Y-m', $month)->startOfMonth();
                $end   = $start->copy()->endOfMonth();
                $query->whereBetween('period_start', [$start->toDateString(), $end->toDateString()]);
            } catch (\Throwable $e) {
                // Ignore malformed user-supplied filter rather than 500ing.
            }
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
                SUM(CASE WHEN status = 'overdue' THEN 1 ELSE 0 END) as total_overdue,
                SUM(CASE WHEN status = 'pending_verification' THEN 1 ELSE 0 END) as total_verifying
            ")
            ->first();

        $summary = [
            'total_paid'    => (float) ($summaryStats->total_paid    ?? 0),
            'total_pending' => (int)   ($summaryStats->total_pending ?? 0),
            'total_overdue' => (int)   ($summaryStats->total_overdue ?? 0),
            'total_verifying' => (int) ($summaryStats->total_verifying ?? 0),
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
            'amount'       => 'required|numeric|min:0|max:100000|decimal:0,2',
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

        // Same rule as member pay-ahead: no two fees for one member may cover
        // the same days (the unique index only catches an identical start).
        // There is no void status on payments yet; when one is added, exclude it here.
        $overlaps = $club->payments()->where('user_id', $data['user_id'])
            ->whereDate('period_start', '<=', $end->toDateString())
            ->whereDate('period_end', '>=', $start->toDateString())
            ->exists();
        if ($overlaps) {
            return back()->withInput()->withErrors(['period_start' => 'This member already has a payment covering part of this period.']);
        }

        $payment = Payment::create([
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

        AuditService::log(
            'payment.created',
            "Payment of RM {$data['amount']} created for user ID {$data['user_id']} (period {$start->toDateString()}).",
            $payment,
            $club->id
        );

        return redirect()->route('admin.clubs.payments.index', $club)
            ->with('success', 'Payment record created.');
    }

    public function show(Payment $payment)
    {
        $this->authorizeClubAdmin($payment->club);
        $payment->load(['user', 'club', 'discount', 'recordedBy', 'verifiedBy']);
        // Every transfer slip ever sent for this fee (rejected ones are kept), newest first.
        // Slips sent for several fees at once ("Bayar semua") are kept on the group.
        $slips = \App\Models\Attachment::query()->where('kind', 'proof_of_payment')
            ->where(function ($q) use ($payment) {
                $q->where(fn ($q) => $q->where('attachable_type', Payment::class)->where('attachable_id', $payment->id))
                    ->orWhere(fn ($q) => $q->where('attachable_type', \App\Models\PaymentBill::class)
                        ->whereIn('attachable_id', $payment->groupBills()->where('method', 'transfer')->select('payment_bills.id')));
            })
            ->orderByDesc('id')->get();
        $currentSlipIds = $payment->transfer_submitted_at ? $payment->currentSlips()->pluck('id')->all() : [];

        return view('admin.payments.show', compact('payment', 'slips', 'currentSlipIds'));
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
            'amount'      => 'required|numeric|min:0|max:100000|decimal:0,2',
            // pending_verification is accepted so the edit form round-trips, but it
            // can only be entered or left through the Verifications queue (below).
            'status'      => 'required|in:pending,paid,overdue,pending_verification',
            // The status the form was loaded with: a save over a newer change
            // (webhook, slip approval, another admin) is refused.
            'original_status' => 'required|in:pending,paid,overdue,pending_verification',
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
        $admin = $request->user();

        // Locked and re-read, so a webhook or slip approval landing at the same
        // moment can't be overwritten by a form loaded before it.
        $refusal = DB::transaction(function () use ($data, $payment, $admin) {
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $originalStatus = $data['original_status'];
            unset($data['original_status']);
            $refuse = fn (string $message) => back()->withInput()->withErrors(['status' => $message]);

            if ($payment->status !== $originalStatus) {
                return $refuse("This payment changed since you opened the form (it is now \"{$payment->status}\"). Reload the page and make your change again.");
            }

            $verifying = $payment->status === 'pending_verification';
            if (($data['status'] === 'pending_verification') !== $verifying) {
                return $refuse($verifying
                    ? 'A transfer slip is waiting for verification. Approve or reject it in Verifications instead.'
                    : '"Awaiting verification" is set only when a member uploads a transfer slip.');
            }

            // The member transferred this exact amount: while the slip is checked only the notes may change.
            if ($verifying) {
                $changed = (int) round(((float) $data['amount']) * 100) !== $payment->amountSen()
                    || (int) ($data['discount_id'] ?? 0) !== (int) ($payment->discount_id ?? 0)
                    || Carbon::parse($data['due_date'])->toDateString() !== $payment->due_date->toDateString()
                    || (empty($data['paid_date']) ? null : Carbon::parse($data['paid_date'])->toDateString()) !== $payment->paid_date?->toDateString()
                    || ($data['reference'] ?? null) !== $payment->reference;
                if ($changed) {
                    return $refuse('A transfer slip is waiting for verification, so only the notes can be changed. Approve or reject it in Verifications first.');
                }
            }

            if ($data['status'] === 'paid' && $payment->status !== 'paid'
                && ($own = OwnFeeRule::blocker($payment, $admin, 'mark it paid'))) {
                return $refuse($own);
            }
            // Nor lower what they owe: amount and discount of their own fee are another admin's call.
            $moneyChanged = (int) round(((float) $data['amount']) * 100) !== $payment->amountSen()
                || (int) ($data['discount_id'] ?? 0) !== (int) ($payment->discount_id ?? 0);
            if ($moneyChanged && ($own = OwnFeeRule::blocker($payment, $admin, 'change its amount or discount'))) {
                return back()->withInput()->withErrors(['amount' => $own]);
            }

            if ($data['status'] === 'paid' && empty($data['paid_date'])) {
                $data['paid_date'] = now()->toDateString();
            }

            $old = $payment->only(['status', 'amount', 'due_date', 'paid_date', 'notes', 'reference', 'paid_via', 'gateway', 'verified_by']);
            $payment->fill($data);
            // No longer paid: how and by whom it was settled no longer applies.
            if ($old['status'] === 'paid' && $data['status'] !== 'paid') {
                // ...nor does a group slip it was settled with.
                $payment->forceFill(['paid_via' => null, 'gateway' => null, 'verified_by' => null, 'verified_at' => null, 'transfer_group_id' => null]);
            }
            $payment->save();

            AuditService::log(
                'payment.updated',
                "Payment #{$payment->id} updated (status: {$old['status']} → {$data['status']}).",
                $payment,
                $payment->club_id,
                $old,
                $payment->fresh()->only(['status', 'amount', 'due_date', 'paid_date', 'notes', 'reference', 'paid_via', 'gateway', 'verified_by'])
            );

            return null;
        });
        if ($refusal) {
            return $refusal;
        }

        return redirect()->route('admin.payments.show', $payment)
            ->with('success', 'Payment updated.');
    }

    public function markPaid(Request $request, Payment $payment)
    {
        $this->authorizeClubAdmin($payment->club);
        $request->validate(['reference' => 'nullable|string|max:255']);
        if ($own = OwnFeeRule::blocker($payment, $request->user(), 'mark it paid')) {
            return back()->with('error', $own);
        }

        // Lock and re-check, so a slip approval or a webhook running at the same moment can't race this.
        $refusal = DB::transaction(function () use ($request, $payment) {
            $locked = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'paid') {
                return back()->with('error', 'This payment is already paid.');
            }
            if ($locked->status === 'pending_verification') {
                return redirect()->route('admin.clubs.verifications.index', $locked->club_id)
                    ->with('error', "Payment #{$locked->id} has a transfer slip waiting. Approve or reject it here instead of marking it paid by hand.");
            }

            $old = $locked->status;
            $locked->update([
                'status'    => 'paid',
                'paid_date' => now()->toDateString(),
                'reference' => $request->reference,
            ]);

            AuditService::log(
                'payment.marked_paid',
                "Payment #{$locked->id} marked as paid" . ($request->reference ? " (ref: {$request->reference})" : '') . '.',
                $locked,
                $locked->club_id,
                ['status' => $old],
                ['status' => 'paid', 'paid_date' => now()->toDateString()]
            );

            return null;
        });
        if ($refusal) {
            return $refusal;
        }

        return back()->with('success', 'Payment marked as paid.');
    }

    public function destroy(Payment $payment)
    {
        $this->authorizeClubAdmin($payment->club);
        $club    = $payment->club;
        $clubId  = $club->id;
        $summary = "Payment #{$payment->id} (RM {$payment->amount}, {$payment->status}) deleted.";
        $old     = $payment->only(['status', 'amount', 'period_start', 'period_end', 'user_id']);
        if ($own = OwnFeeRule::blocker($payment, request()->user(), 'delete it')) {
            return back()->with('error', $own);
        }
        // Transfer slips are kept as evidence: a fee with slips can't be deleted.
        // A slip sent for several fees at once ("Bayar semua") covers this one too.
        if ($payment->status === 'pending_verification' || $payment->attachments()->exists() || $payment->inAnyTransferGroup()) {
            return back()->with('error', 'This payment has transfer slips attached, so it is kept for the record and can\'t be deleted.');
        }
        $payment->delete();

        AuditService::log('payment.deleted', $summary, null, $clubId, $old);

        return redirect()->route('admin.clubs.payments.index', $club)
            ->with('success', 'Payment record deleted.');
    }
}
