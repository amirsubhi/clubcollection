@extends('layouts.app')
@section('title', 'Payment Details')
@section('page-title', 'Payment #' . $payment->id)
@section('page-club', $payment->club->name)

@section('content')
<div class="row justify-content-center">
    <div class="col-lg-6">
        <div class="card shadow-sm">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h2 class="h6 mb-0 fw-semibold">{{ $payment->period_start->format('M Y') }} fee</h2>
                @if($payment->status === 'paid')
                    <span class="badge bg-success">Paid</span>
                @elseif($payment->status === 'overdue')
                    <span class="badge bg-danger">Overdue</span>
                @elseif($payment->status === 'pending_verification')
                    <span class="badge bg-info text-dark">Awaiting verification</span>
                @else
                    <span class="badge bg-warning text-dark">Pending</span>
                @endif
            </div>
            <div class="card-body p-4">
                <dl class="row mb-0">
                    <dt class="col-5 text-muted">Member</dt>
                    <dd class="col-7">{{ $payment->user?->name ?? 'Former member' }}</dd>

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

                    @if($payment->paid_via === 'transfer')
                    <dt class="col-5 text-muted">Method</dt>
                    <dd class="col-7">Bank transfer / DuitNow (slip checked{{ $payment->verifiedBy ? ' by '.$payment->verifiedBy->name : '' }})</dd>
                    @endif

                    @if($payment->transfer_date)
                    <dt class="col-5 text-muted">Transfer date</dt>
                    <dd class="col-7">{{ $payment->transfer_date->format('d M Y') }}{{ $payment->transfer_ref ? ' · member ref: '.$payment->transfer_ref : '' }}</dd>
                    @endif

                    @if($payment->rejection_reason && $payment->status !== 'paid')
                    <dt class="col-5 text-muted">Last slip rejected</dt>
                    <dd class="col-7">{{ $payment->rejection_reason }}</dd>
                    @endif

                    @if($payment->transfer_bank_ref)
                    <dt class="col-5 text-muted">Bank reference</dt>
                    <dd class="col-7">{{ $payment->transfer_bank_ref }}</dd>
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
                    <dd class="col-7">{{ $payment->recordedBy?->name ?? '—' }}</dd>
                </dl>
            </div>
            @if($slips->isNotEmpty())
            @php
                $currentLabel = match (true) {
                    $payment->status === 'pending_verification' => ['Being checked', 'bg-info text-dark'],
                    $payment->status === 'paid' && $payment->paid_via === 'transfer' => ['Approved', 'bg-success'],
                    $payment->status === 'paid' => ['Not used (paid online)', 'bg-warning text-dark'],
                    default => ['Rejected', 'bg-secondary'],
                };
            @endphp
            <div class="card-body border-top px-4 py-3" id="slips">
                <h2 class="h6 fw-semibold mb-2">Transfer slips ({{ $slips->count() }})</h2>
                <ul class="list-unstyled mb-0 small">
                    @foreach($slips as $slip)
                    @php [$label, $badge] = in_array($slip->id, $currentSlipIds, true) ? $currentLabel : ['Earlier, rejected', 'bg-secondary']; @endphp
                    <li class="d-flex align-items-center gap-2 py-1">
                        <i class="bi {{ $slip->mime === 'application/pdf' ? 'bi-file-earmark-pdf' : 'bi-file-earmark-image' }} text-muted"></i>
                        <a href="{{ route('admin.attachments.show', ['attachment' => $slip, 'inline' => 1]) }}" target="_blank" rel="noopener" class="text-truncate">{{ $slip->original_name }}</a>
                        <span class="text-muted text-nowrap">{{ $slip->created_at->format('d M Y, g:i a') }}</span>
                        <span class="badge {{ $badge }} ms-auto">{{ $label }}</span>
                    </li>
                    @endforeach
                </ul>
                <div class="form-text">Opening a slip is logged in the audit trail.</div>
            </div>
            @endif
            <div class="card-footer d-flex flex-wrap gap-2">
                @if($payment->status === 'pending_verification')
                <a href="{{ route('admin.clubs.verifications.index', $payment->club) }}" class="btn btn-info btn-sm">
                    <i class="bi bi-patch-check me-1"></i>Check transfer slip
                </a>
                @elseif($payment->status !== 'paid')
                <form action="{{ route('admin.payments.mark-paid', $payment) }}" method="POST">
                    @csrf @method('PATCH')
                    <button class="btn btn-success btn-sm"><i class="bi bi-check-lg me-1"></i>Mark as Paid</button>
                </form>
                @endif
                <a href="{{ route('admin.payments.edit', $payment) }}" class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-pencil me-1"></i>Edit
                </a>
                <a href="{{ route('admin.clubs.payments.index', $payment->club) }}" class="btn btn-outline-secondary btn-sm ms-auto">
                    Back to List
                </a>
            </div>
        </div>
    </div>
</div>
@endsection
