@extends('layouts.member')
@section('title', $club->name . ' — My Payments')
@section('page-heading', $club->name . ' — My Payments')
@section('breadcrumb')
<a href="{{ route('member.dashboard') }}" class="back-link text-muted text-decoration-none small">
    <i class="bi bi-arrow-left me-1"></i>Dashboard
</a>
@endsection

@section('content')
{{-- Year Filter --}}
<div class="d-flex align-items-center justify-content-end mb-3">
    <form method="GET" class="d-flex align-items-center gap-2">
        <label class="form-label mb-0 small text-muted fw-semibold">Year:</label>
        <select name="year" class="form-select form-select-sm" style="width:100px" onchange="this.form.submit()">
            @foreach($years as $yr)
                <option value="{{ $yr }}" {{ $yr == $selectedYear ? 'selected' : '' }}>{{ $yr }}</option>
            @endforeach
        </select>
    </form>
</div>

{{-- Annual Summary Cards --}}
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card shadow-sm border-0 bg-success bg-opacity-10">
            <div class="card-body text-center py-3">
                <div class="fw-bold text-success fs-6">RM {{ number_format($annualSummary['paid'], 2) }}</div>
                <div class="text-muted small">Paid ({{ $annualSummary['paid_count'] }}x)</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card shadow-sm border-0 bg-warning bg-opacity-10">
            <div class="card-body text-center py-3">
                <div class="fw-bold text-warning fs-6">RM {{ number_format($annualSummary['pending'], 2) }}</div>
                <div class="text-muted small">Pending</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card shadow-sm border-0 bg-danger bg-opacity-10">
            <div class="card-body text-center py-3">
                <div class="fw-bold text-danger fs-6">RM {{ number_format($annualSummary['overdue'], 2) }}</div>
                <div class="text-muted small">Overdue</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card shadow-sm border-0 bg-primary bg-opacity-10">
            <div class="card-body text-center py-3">
                <div class="fw-bold text-primary fs-6">RM {{ number_format($annualSummary['total'], 2) }}</div>
                <div class="text-muted small">Total {{ $selectedYear }}</div>
            </div>
        </div>
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

{{-- Payment List --}}
<div class="card shadow-sm">
    <div class="card-header fw-semibold d-flex align-items-center gap-2">
        <i class="bi bi-receipt me-1"></i>
        Payments — {{ $selectedYear }}
        <span class="badge bg-secondary ms-1">{{ $payments->total() }}</span>
    </div>
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
            <i class="bi bi-calendar-x fs-2 d-block mb-2"></i>
            No payments recorded for {{ $selectedYear }}.
        </div>
        @endforelse
    </div>
</div>
<div class="mt-3">{{ $payments->appends(['year' => $selectedYear])->links() }}</div>
@endsection
