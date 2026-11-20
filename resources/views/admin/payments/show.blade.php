@extends('layouts.app')
@section('title', 'Payment Details')
@section('page-title', 'Payment #' . $payment->id)
@section('page-club', $payment->club->name)

@section('content')
@php $display = \App\Support\Payments\DisplayStatus::of($payment); @endphp
<div class="row justify-content-center">
    <div class="col-lg-6">
        @foreach($refundFlags as $flag)
        @php $nv = (array) $flag->new_values; @endphp
        <div class="alert alert-warning d-flex gap-2" role="status" data-testid="refund-flag">
            <i class="bi bi-exclamation-triangle mt-1" aria-hidden="true"></i>
            <div>
                <p class="fw-semibold mb-1">
                    @if($flag->action === 'payment.gateway_paid_after_manual')
                        Paid online after it was recorded as {{ \App\Http\Controllers\Admin\TransferVerificationController::RECORDED_AS[$nv['paid_via'] ?? ''] ?? 'paid by hand' }}: both payments reached the club. Refund the online payment.
                    @elseif($flag->action === 'payment.group_bill_refund')
                        Online payment received for a group bill ({{ $nv['bill_code'] ?? 'n/a' }}), but nothing was marked paid: refund it, or record the fees.
                    @elseif($flag->action === 'payment.underpaid_webhook')
                        Online payment was less than the bill{{ isset($nv['bill_code']) ? ' ('.$nv['bill_code'].')' : '' }}: nothing was marked paid. Refund it, or record the fee once the rest is paid.
                    @elseif($flag->action === 'payment.unmatched_webhook')
                        A payment for bill {{ $nv['bill_code'] ?? 'n/a' }} referencing this fee was reported, but the bill isn't ours: nothing was marked paid. Check {{ ($nv['gateway'] ?? '') === 'billplz' ? 'Billplz' : 'ToyyibPay' }} before marking the fee paid.
                    @elseif($flag->action === 'payment.webhook_amount_unknown')
                        Online payment reported{{ isset($nv['bill_code']) ? ' ('.$nv['bill_code'].')' : '' }}, but the amount paid is not known: nothing was marked paid. Check the gateway account, then record the fee by hand.
                    @elseif($flag->action === 'payment.gateway_paid_during_verification')
                        Paid online as well as by bank transfer: refund one of them to the member.
                    @else
                        Paid online twice{{ ((int) $flag->auditable_id !== (int) $payment->id) ? ' (group bill '.($nv['bill_code'] ?? 'n/a').')' : '' }}: refund one of them to the member.
                    @endif
                </p>
                <p class="small mb-0">{{ $flag->created_at->format('d M Y, g:i a') }}@if(!empty($nv['transaction_id'])) · online txn {{ $nv['transaction_id'] }}@endif @if(isset($nv['paid_amount_sen'])) · RM {{ number_format($nv['paid_amount_sen'] / 100, 2) }}@endif</p>
            </div>
        </div>
        @endforeach
        <div class="card shadow-sm">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h2 class="h6 mb-0 fw-semibold">{{ $payment->period_start->format('M Y') }} fee</h2>
                @include('admin.payments._status-badge', ['status' => $display])
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
                    <dd class="col-7 {{ $display === 'overdue' ? 'fw-semibold' : '' }}">
                        {{ $payment->due_date->format('d M Y') }}
                    </dd>

                    @if($display === 'processing')
                    <dt class="col-5 text-muted">Online payment</dt>
                    <dd class="col-7">The member started an FPX payment @php $ob = \App\Support\Payments\DisplayStatus::openBill($payment); @endphp{{ ($ob?->opened_at ?? $ob?->created_at)?->format('d M Y, g:i a') }}; the bank has not confirmed it yet.</dd>
                    @endif

                    @if($payment->paid_date)
                    <dt class="col-5 text-muted">Paid On</dt>
                    <dd class="col-7 text-success">{{ $payment->paid_date->format('d M Y') }}</dd>
                    @endif

                    @if($payment->status === 'paid' && $payment->paid_via)
                    <dt class="col-5 text-muted">Method</dt>
                    <dd class="col-7">
                        @switch($payment->paid_via)
                            @case('transfer')
                                @if($payment->transfer_submitted_at || $payment->verifiedBy)
                                    Bank transfer / DuitNow (slip checked{{ $payment->verifiedBy ? ' by '.$payment->verifiedBy->name : '' }})
                                @else
                                    Bank transfer (recorded by an admin)
                                @endif
                                @break
                            @case('cash') Cash (recorded by an admin) @break
                            @case('manual') Other (recorded by an admin) @break
                            @default Online ({{ ucfirst($payment->paid_via) }})
                        @endswitch
                    </dd>
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
                @include('admin.payments._record-payment-button', ['payment' => $payment, 'status' => $display, 'class' => 'btn btn-primary btn-sm'])
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
@include('admin.payments._record-payment-modal')
@endsection
