@extends('layouts.member')
@section('title', 'Payment Successful')

@section('content')
<div class="text-center py-5">
    <div class="mb-4">
        <div class="bg-success bg-opacity-10 rounded-circle d-inline-flex align-items-center justify-content-center" style="width:90px;height:90px">
            <i class="bi bi-check-circle-fill text-success" style="font-size:48px"></i>
        </div>
    </div>
    <h4 class="fw-bold">Payment Received!</h4>
    <p class="text-muted mb-1">Thank you, <strong>{{ $payment->user->name }}</strong>.</p>
    <p class="text-muted mb-4">
        Your payment of <strong class="text-success">RM {{ number_format($payment->amount, 2) }}</strong>
        to <strong>{{ $payment->club->name }}</strong> has been confirmed.
    </p>

    <div class="card shadow-sm mx-auto mb-4" style="max-width:400px">
        <div class="card-body py-3">
            <div class="row g-2 text-start">
                <div class="col-6 text-muted small">Club</div>
                <div class="col-6 small fw-semibold">{{ $payment->club->name }}</div>
                <div class="col-6 text-muted small">Period</div>
                <div class="col-6 small fw-semibold">{{ $payment->period_start->format('M Y') }} – {{ $payment->period_end->format('M Y') }}</div>
                <div class="col-6 text-muted small">Amount</div>
                <div class="col-6 small fw-semibold text-success">RM {{ number_format($payment->amount, 2) }}</div>
                @if($payment->transaction_id)
                <div class="col-6 text-muted small">Transaction ID</div>
                <div class="col-6 small">{{ $payment->transaction_id }}</div>
                @endif
            </div>
        </div>
    </div>

    <p class="text-muted small mb-4">
        <i class="bi bi-envelope me-1"></i>A confirmation email has been sent to <strong>{{ $payment->user->email }}</strong>.
    </p>

    <div class="d-flex gap-3 justify-content-center">
        <a href="{{ route('member.payments.invoice', $payment) }}" class="btn btn-outline-secondary">
            <i class="bi bi-file-text me-1"></i>View Invoice
        </a>
        <a href="{{ route('member.dashboard') }}" class="btn btn-primary">
            <i class="bi bi-house me-1"></i>Back to Dashboard
        </a>
    </div>
</div>
@endsection
