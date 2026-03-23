@extends('layouts.member')
@section('title', 'Invoice #' . $payment->id)

@push('styles')
<style>
@media print {
    .topnav, .no-print { display: none !important; }
    .main { max-width: 100%; margin: 0; padding: 0; }
    body { background: #fff; }
}
.invoice-box { background: #fff; border: 1px solid #dee2e6; border-radius: 8px; padding: 40px; max-width: 680px; margin: 0 auto; }
.invoice-header { border-bottom: 2px solid #198754; padding-bottom: 20px; margin-bottom: 24px; }
.status-paid { color: #198754; }
.status-pending { color: #ffc107; }
.status-overdue { color: #dc3545; }
</style>
@endpush

@section('content')
<div class="no-print d-flex gap-2 mb-3">
    <a href="{{ route('member.payments.index', $payment->club) }}" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>Back
    </a>
    <button onclick="window.print()" class="btn btn-sm btn-outline-secondary ms-auto">
        <i class="bi bi-printer me-1"></i>Print
    </button>
    @if($payment->status !== 'paid')
    <a href="{{ route('member.payments.pay', $payment) }}" class="btn btn-sm btn-success">
        <i class="bi bi-credit-card me-1"></i>Pay Now
    </a>
    @endif
</div>

<div class="invoice-box shadow-sm">
    <div class="invoice-header d-flex justify-content-between align-items-start">
        <div>
            @if($payment->club->logo)
                <img src="{{ asset('storage/'.$payment->club->logo) }}" height="50" class="mb-2">
            @endif
            <h5 class="mb-0 fw-bold">{{ $payment->club->name }}</h5>
            @if($payment->club->email)
                <div class="text-muted small">{{ $payment->club->email }}</div>
            @endif
        </div>
        <div class="text-end">
            <div class="fw-bold fs-5">INVOICE</div>
            <div class="text-muted small">#{{ str_pad($payment->id, 6, '0', STR_PAD_LEFT) }}</div>
            <div class="mt-2">
                @if($payment->status === 'paid')
                    <span class="badge bg-success fs-6">PAID</span>
                @elseif($payment->status === 'overdue')
                    <span class="badge bg-danger fs-6">OVERDUE</span>
                @else
                    <span class="badge bg-warning text-dark fs-6">PENDING</span>
                @endif
            </div>
        </div>
    </div>

    <div class="row mb-4">
        <div class="col-6">
            <div class="text-muted small mb-1">BILLED TO</div>
            <div class="fw-semibold">{{ $payment->user->name }}</div>
            <div class="text-muted small">{{ $payment->user->email }}</div>
        </div>
        <div class="col-6 text-end">
            <div class="text-muted small mb-1">INVOICE DATE</div>
            <div>{{ $payment->created_at->format('d M Y') }}</div>
            <div class="text-muted small mt-2 mb-1">DUE DATE</div>
            <div class="{{ $payment->isOverdue() ? 'text-danger fw-semibold' : '' }}">
                {{ $payment->due_date->format('d M Y') }}
            </div>
        </div>
    </div>

    <table class="table table-bordered mb-4">
        <thead class="table-light">
            <tr>
                <th>Description</th>
                <th class="text-center" style="width:120px">Frequency</th>
                <th class="text-end" style="width:130px">Amount</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>
                    <div class="fw-semibold">Club Membership Fee</div>
                    <div class="text-muted small">
                        Period: {{ $payment->period_start->format('d M Y') }} – {{ $payment->period_end->format('d M Y') }}
                    </div>
                </td>
                <td class="text-center">{{ ucfirst($payment->frequency) }}</td>
                <td class="text-end fw-semibold">RM {{ number_format($payment->amount, 2) }}</td>
            </tr>
            @if($payment->discount)
            <tr>
                <td colspan="2" class="text-end text-muted small">Discount: {{ $payment->discount->name }}</td>
                <td class="text-end text-danger small">
                    @if($payment->discount->type === 'fixed')
                        - RM {{ number_format($payment->discount->value, 2) }}
                    @else
                        - {{ $payment->discount->value }}%
                    @endif
                </td>
            </tr>
            @endif
        </tbody>
        <tfoot>
            <tr class="table-light">
                <td colspan="2" class="text-end fw-bold">TOTAL</td>
                <td class="text-end fw-bold fs-5">RM {{ number_format($payment->amount, 2) }}</td>
            </tr>
        </tfoot>
    </table>

    @if($payment->status === 'paid')
    <div class="alert alert-success py-2 small mb-4">
        <i class="bi bi-check-circle me-1"></i>
        <strong>Paid on {{ $payment->paid_date->format('d M Y') }}</strong>
        @if($payment->transaction_id) &middot; Transaction: {{ $payment->transaction_id }} @endif
        @if($payment->reference) &middot; Ref: {{ $payment->reference }} @endif
    </div>
    @endif

    @if($payment->notes)
    <div class="text-muted small border-top pt-3">
        <strong>Notes:</strong> {{ $payment->notes }}
    </div>
    @endif

    <div class="border-top pt-3 mt-3 text-center text-muted small">
        Thank you for your continued membership in {{ $payment->club->name }}.
    </div>
</div>
@endsection
