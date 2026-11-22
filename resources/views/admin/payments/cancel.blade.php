@extends('layouts.app')
@section('title', 'Cancel payment')
@section('page-title', 'Cancel payment #' . $payment->id)
@section('page-club', $payment->club->name)

@section('content')
@php
    $receiptNo = $payment->receiptNumber();
    $totalSen = (int) $fees->sum(fn ($f) => $f->amountSen());
@endphp
<div class="row justify-content-center">
    <div class="col-lg-6">
        <div class="card shadow-sm">
            <div class="card-header">
                <h2 class="h6 mb-0 fw-semibold">Cancel the payment of {{ $payment->user?->name ?? 'Former member' }}</h2>
            </div>
            <div class="card-body p-4">
                <p class="small text-body-secondary">
                    The fee becomes unpaid again and the member is emailed. Receipt <span class="font-monospace">{{ $receiptNo }}</span> is void
                    (the number is never reused; paying again issues a new one). Everything stays on record: the cancellation, the slips and the audit log.
                </p>

                <dl class="row g-0 mb-3 rounded border bg-body-tertiary px-3 py-2 small" data-testid="cancel-summary">
                    <dt class="col-5 fw-normal text-body-secondary">Paid</dt>
                    <dd class="col-7 mb-1">{{ \App\Services\Payments\PaymentCancellationService::METHOD_LABELS[$method] }}</dd>
                    <dt class="col-5 fw-normal text-body-secondary">Paid on</dt>
                    <dd class="col-7 mb-1">{{ $payment->paid_date?->format('d M Y') ?? '—' }}</dd>
                    <dt class="col-5 fw-normal text-body-secondary">Receipt no.</dt>
                    <dd class="col-7 mb-0 font-monospace">{{ $receiptNo }}</dd>
                </dl>

                @if($set)
                <div class="alert alert-warning small" data-testid="cancel-set">
                    <p class="fw-semibold mb-2">
                        Paid together: these {{ $fees->count() }} fees share receipt {{ $receiptNo }}
                        ({{ $set->isTransfer() ? 'one transfer slip, '.$set->reference : 'one online bill'.($set->bill_code ? ', '.$set->bill_code : '') }}).
                        They are all cancelled together.
                    </p>
                    <ul class="mb-1 ps-3">
                        @foreach($fees as $fee)
                        <li>{{ $fee->period_start->format('d M Y') }} – {{ $fee->period_end->format('d M Y') }}: RM {{ number_format($fee->amountSen() / 100, 2) }}</li>
                        @endforeach
                    </ul>
                    <p class="mb-0 fw-semibold">Total: RM {{ number_format($totalSen / 100, 2) }}</p>
                </div>
                @endif

                @if($absorbedSen > 0)
                <div class="alert alert-warning small" data-testid="cancel-charge-warning">
                    <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>
                    When this was paid online the club absorbed a gateway charge of <strong>RM {{ number_format($absorbedSen / 100, 2) }}</strong>.
                    Cancelling takes that charge out of the fund figures with the fee.
                </div>
                @endif

                @if($payment->paid_date && \App\Support\Admin\FundFigures::financialYearStart($payment->paid_date, $payment->club)->lt(\App\Support\Admin\FundFigures::financialYearStart(today(), $payment->club)))
                <div class="alert alert-info small" data-testid="cancel-earlier-year">
                    This payment is dated in an earlier financial year ({{ \App\Support\Admin\FundFigures::yearLabel($payment->paid_date, $payment->club) }}).
                    Cancelling it changes that year's income totals; the audit log keeps the trail.
                </div>
                @endif

                <form method="POST" action="{{ route('admin.payments.cancel', $payment) }}" id="cancelPaymentForm">
                    @csrf
                    <div class="mb-3">
                        <label for="c-reason" class="form-label fw-semibold">Why is it cancelled? <span class="text-danger" aria-hidden="true">*</span></label>
                        <select id="c-reason" name="reason" required class="form-select @error('reason') is-invalid @enderror"
                                @error('reason') aria-invalid="true" aria-describedby="c-reason-error" @enderror>
                            <option value="">Choose a reason…</option>
                            @foreach($reasons as $code => $label)
                            <option value="{{ $code }}" {{ old('reason') === $code ? 'selected' : '' }}>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('reason')<div id="c-reason-error" role="alert" class="invalid-feedback">{{ $message }}</div>@enderror
                        <div class="form-text">The member is told in neutral words that fit the reason; they don't see the note below.</div>
                    </div>
                    <div class="mb-3">
                        <label for="c-note" class="form-label fw-semibold">Note <span class="fw-normal text-body-secondary">(optional, internal)</span></label>
                        <textarea id="c-note" name="note" rows="3" maxlength="{{ \App\Services\Payments\PaymentCancellationService::NOTE_MAX }}"
                                  class="form-control @error('note') is-invalid @enderror" @error('note') aria-invalid="true" aria-describedby="c-note-error" @enderror>{{ old('note') }}</textarea>
                        @error('note')<div id="c-note-error" role="alert" class="invalid-feedback">{{ $message }}</div>@enderror
                        <div class="form-text">For the admins and the audit log only. Plain text, up to {{ \App\Services\Payments\PaymentCancellationService::NOTE_MAX }} characters.</div>
                    </div>
                    @error('status')<div role="alert" class="alert alert-danger small">{{ $message }}</div>@enderror
                    <div class="form-check mb-4">
                        <input class="form-check-input @error('confirm') is-invalid @enderror" type="checkbox" name="confirm" value="1" id="c-confirm" required
                               @error('confirm') aria-invalid="true" aria-describedby="c-confirm-error" @enderror>
                        <label class="form-check-label" for="c-confirm">
                            I understand {{ $fees->count() > 1 ? 'these '.$fees->count().' fees become' : 'this fee becomes' }} unpaid again, receipt {{ $receiptNo }} is void and the member is emailed.
                        </label>
                        @error('confirm')<div id="c-confirm-error" role="alert" class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-danger"><i class="bi bi-x-circle me-1" aria-hidden="true"></i>Cancel payment</button>
                        <a href="{{ route('admin.payments.show', $payment) }}" class="btn btn-outline-secondary">Back</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
