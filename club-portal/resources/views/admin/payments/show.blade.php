@extends('layouts.app')
@section('title', 'Payment Details')
@section('page-title', 'Payment Details')

@section('content')
<div class="row justify-content-center">
    <div class="col-lg-6">
        <div class="card shadow-sm">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-semibold">Payment #{{ $payment->id }}</h6>
                @if($payment->status === 'paid')
                    <span class="badge bg-success">Paid</span>
                @elseif($payment->status === 'overdue')
                    <span class="badge bg-danger">Overdue</span>
                @else
                    <span class="badge bg-warning text-dark">Pending</span>
                @endif
            </div>
            <div class="card-body p-4">
                <dl class="row mb-0">
                    <dt class="col-5 text-muted">Member</dt>
                    <dd class="col-7">{{ $payment->user->name }}</dd>

                    <dt class="col-5 text-muted">Club</dt>
                    <dd class="col-7">{{ $payment->club->name }}</dd>

                    <dt class="col-5 text-muted">Amount</dt>
                    <dd class="col-7 fw-bold text-success fs-5">RM {{ number_format($payment->amount, 2) }}</dd>

                    <dt class="col-5 text-muted">Frequency</dt>
                    <dd class="col-7">{{ ucfirst($payment->frequency) }}</dd>

                    <dt class="col-5 text-muted">Period</dt>
                    <dd class="col-7">{{ $payment->period_start->format('d M Y') }} – {{ $payment->period_end->format('d M Y') }}</dd>

                    <dt class="col-5 text-muted">Due Date</dt>
                    <dd class="col-7 {{ $payment->isOverdue() ? 'text-danger fw-semibold' : '' }}">
                        {{ $payment->due_date->format('d M Y') }}
                    </dd>

                    @if($payment->paid_date)
                    <dt class="col-5 text-muted">Paid On</dt>
                    <dd class="col-7 text-success">{{ $payment->paid_date->format('d M Y') }}</dd>
                    @endif

                    @if($payment->reference)
                    <dt class="col-5 text-muted">Reference</dt>
                    <dd class="col-7">{{ $payment->reference }}</dd>
                    @endif

                    @if($payment->discount)
                    <dt class="col-5 text-muted">Discount</dt>
                    <dd class="col-7">{{ $payment->discount->name }}</dd>
                    @endif

                    @if($payment->notes)
                    <dt class="col-5 text-muted">Notes</dt>
                    <dd class="col-7">{{ $payment->notes }}</dd>
                    @endif

                    <dt class="col-5 text-muted">Recorded By</dt>
                    <dd class="col-7">{{ $payment->recordedBy->name }}</dd>
                </dl>
            </div>
            <div class="card-footer d-flex gap-2">
                @if($payment->status !== 'paid')
                <form action="{{ route('admin.payments.mark-paid', $payment) }}" method="POST">
                    @csrf @method('PATCH')
                    <button class="btn btn-success btn-sm"><i class="bi bi-check-lg me-1"></i>Mark as Paid</button>
                </form>
                @endif
                <a href="{{ route('admin.payments.edit', $payment) }}" class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-pencil me-1"></i>Edit
                </a>
                <a href="{{ route('admin.payments.index', $payment->club) }}" class="btn btn-outline-secondary btn-sm ms-auto">
                    Back to List
                </a>
            </div>
        </div>
    </div>
</div>
@endsection
