@extends('layouts.member')
@section('title', $club->name . ' - My Payments')

@section('content')
<div class="d-flex align-items-center gap-2 mb-4">
    <a href="{{ route('member.dashboard') }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
    <div>
        <h5 class="mb-0 fw-bold">{{ $club->name }} — My Payments</h5>
    </div>
</div>

{{-- Pay Future Period --}}
<div class="card shadow-sm mb-4">
    <div class="card-header fw-semibold d-flex align-items-center gap-2">
        <i class="bi bi-calendar-plus text-primary"></i> Pay a Future Period
    </div>
    <div class="card-body">
        <form action="{{ route('member.payments.generate-future', $club) }}" method="POST" class="row g-3 align-items-end">
            @csrf
            <div class="col-md-4">
                <label class="form-label small fw-semibold">Start Month</label>
                <input type="date" name="period_start" class="form-control"
                       value="{{ now()->addMonth()->startOfMonth()->format('Y-m-d') }}">
            </div>
            <div class="col-md-4">
                <label class="form-label small fw-semibold">Payment Type</label>
                <select name="frequency" class="form-select">
                    <option value="monthly">Monthly</option>
                    <option value="quarterly">Quarterly (3 months)</option>
                    <option value="yearly">Yearly (12 months)</option>
                </select>
            </div>
            <div class="col-md-4">
                <button type="submit" class="btn btn-primary w-100">
                    <i class="bi bi-plus-lg me-1"></i>Generate & Pay
                </button>
            </div>
        </form>
    </div>
</div>

{{-- Payment History --}}
<div class="card shadow-sm">
    <div class="card-header fw-semibold">Payment History</div>
    <div class="list-group list-group-flush">
        @forelse($payments as $payment)
        <div class="list-group-item py-3">
            <div class="d-flex align-items-center justify-content-between gap-3">
                <div class="flex-grow-1">
                    <div class="d-flex align-items-center gap-2 mb-1">
                        <span class="fw-semibold">
                            {{ $payment->period_start->format('M Y') }}
                            @if($payment->period_start->format('Y-m') !== $payment->period_end->format('Y-m'))
                                – {{ $payment->period_end->format('M Y') }}
                            @endif
                        </span>
                        <span class="badge bg-light text-dark border small">{{ ucfirst($payment->frequency) }}</span>
                        @if($payment->status === 'paid')
                            <span class="badge bg-success-subtle text-success border border-success-subtle">Paid</span>
                        @elseif($payment->status === 'overdue')
                            <span class="badge bg-danger-subtle text-danger border border-danger-subtle">Overdue</span>
                        @else
                            <span class="badge bg-warning-subtle text-warning border border-warning-subtle">Pending</span>
                        @endif
                    </div>
                    <div class="text-muted small">
                        Due: {{ $payment->due_date->format('d M Y') }}
                        @if($payment->paid_date)
                            &middot; Paid: {{ $payment->paid_date->format('d M Y') }}
                        @endif
                        @if($payment->transaction_id)
                            &middot; Ref: {{ $payment->transaction_id }}
                        @endif
                    </div>
                </div>
                <div class="text-end flex-shrink-0">
                    <div class="fw-bold fs-6 {{ $payment->status === 'paid' ? 'text-success' : ($payment->status === 'overdue' ? 'text-danger' : 'text-warning') }}">
                        RM {{ number_format($payment->amount, 2) }}
                    </div>
                    <div class="d-flex gap-1 mt-1 justify-content-end">
                        <a href="{{ route('member.payments.invoice', $payment) }}" class="btn btn-sm btn-outline-secondary">
                            <i class="bi bi-file-text me-1"></i>Invoice
                        </a>
                        @if($payment->status !== 'paid')
                        <a href="{{ route('member.payments.pay', $payment) }}" class="btn btn-sm btn-success">
                            <i class="bi bi-credit-card me-1"></i>Pay Now
                        </a>
                        @endif
                    </div>
                </div>
            </div>
        </div>
        @empty
        <div class="list-group-item text-muted text-center py-5">
            <i class="bi bi-wallet2 fs-2 d-block mb-2"></i>
            No payment records yet.
        </div>
        @endforelse
    </div>
</div>
<div class="mt-3">{{ $payments->links() }}</div>
@endsection
