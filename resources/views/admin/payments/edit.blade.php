@extends('layouts.app')
@section('title', 'Edit Payment')
@section('page-title', 'Edit Payment')
@section('page-club', $payment->club->name)

@section('content')
<div class="row justify-content-center">
    <div class="col-lg-6">
        <div class="card shadow-sm">
            <div class="card-body p-4">
                <p class="text-muted small mb-4">Member: <strong>{{ $payment->user?->name ?? 'Former member' }}</strong> &middot; Period: {{ $payment->period_start->format('d M Y') }} – {{ $payment->period_end->format('d M Y') }}</p>
                @php
                    // Paid with a gateway charge the club absorbed (same rule as FundFigures::chargesQuery): taking it off "Paid" also takes that charge out of the fund figures.
                    $absorbedSen = ($payment->status === 'paid' && (int) $payment->fee_absorbed_sen > 0) ? (int) $payment->fee_absorbed_sen : 0;
                @endphp
                <form action="{{ route('admin.payments.update', $payment) }}" method="POST" id="paymentEditForm"
                      @if($absorbedSen) data-revert-warning="When this fee was paid online the club absorbed a gateway charge of RM {{ number_format($absorbedSen / 100, 2) }}. Changing it from Paid takes that charge out of the fund figures until you mark the fee paid by hand again. Continue?" @endif>
                    @csrf @method('PUT')
                    {{-- The status this form was loaded with: a save over a newer change is refused. --}}
                    <input type="hidden" name="original_status" value="{{ $payment->status }}">
                    <div class="mb-3">
                        <label for="f-amount" class="form-label fw-semibold">Amount (RM) <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text">RM</span>
                            <input id="f-amount" aria-describedby="f-amount-help @error('amount') f-amount-error @enderror" @error('amount') aria-invalid="true" @enderror type="number" step="0.01" min="0" max="100000" name="amount" class="form-control @error('amount') is-invalid @enderror"
                                   value="{{ old('amount', $payment->amount) }}">
                        </div>
                        @error('amount')<div id="f-amount-error" role="alert" class="text-danger small">{{ $message }}</div>@enderror
                        <div id="f-amount-help" class="form-text">Adjust the payable amount if needed (e.g. for partial waivers or corrections).</div>
                    </div>
                    <div class="mb-3">
                        <label for="f-due-date" class="form-label fw-semibold">Due Date <span class="text-danger">*</span></label>
                        <input id="f-due-date" aria-describedby="f-due-date-help" type="date" name="due_date" class="form-control"
                               value="{{ old('due_date', $payment->due_date->format('Y-m-d')) }}">
                        <div id="f-due-date-help" class="form-text">Changing this resets when the payment becomes overdue.</div>
                    </div>
                    <div class="mb-3">
                        <label for="f-status" class="form-label fw-semibold">Status <span class="text-danger">*</span></label>
                        <select id="f-status" aria-describedby="f-status-help @if($absorbedSen) f-status-charge @endif @error('status') f-status-error @enderror" @error('status') aria-invalid="true" @enderror name="status" class="form-select @error('status') is-invalid @enderror">
                            <option value="pending" {{ old('status', $payment->status) == 'pending' ? 'selected' : '' }}>Pending</option>
                            <option value="paid" {{ old('status', $payment->status) == 'paid' ? 'selected' : '' }}>Paid</option>
                            <option value="overdue" {{ old('status', $payment->status) == 'overdue' ? 'selected' : '' }}>Overdue</option>
                            @if($payment->status === 'pending_verification')
                            <option value="pending_verification" selected>Awaiting verification (set by the member's slip)</option>
                            @endif
                        </select>
                        @if($absorbedSen)
                        <div id="f-status-charge" class="alert alert-warning small py-2 mt-2 mb-0" data-testid="revert-charge-warning">
                            <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>
                            Gateway charge: when this fee was paid online the club absorbed <strong>RM {{ number_format($absorbedSen / 100, 2) }}</strong>.
                            Changing the status from Paid takes that charge out of the fund figures; it counts again if you mark the fee paid here by hand.
                        </div>
                        @endif
                        @if($payment->status === 'pending_verification')
                        <div class="form-text text-info-emphasis">A transfer slip is waiting. Approve or reject it in <a href="{{ route('admin.clubs.verifications.index', $payment->club) }}">Verifications</a>.</div>
                        @endif
                        @error('status')<div id="f-status-error" role="alert" class="text-danger small">{{ $message }}</div>@enderror
                        <div id="f-status-help" class="form-text">Pending = awaiting payment. Paid = received. Overdue = past due date without payment.</div>
                    </div>
                    <div class="mb-3">
                        <label for="f-paid-date" class="form-label fw-semibold">Paid Date</label>
                        <input id="f-paid-date" aria-describedby="f-paid-date-help" type="date" name="paid_date" class="form-control"
                               value="{{ old('paid_date', $payment->paid_date?->format('Y-m-d')) }}">
                        <div id="f-paid-date-help" class="form-text">The actual date payment was received. Fill this in when marking status as Paid.</div>
                    </div>
                    <div class="mb-3">
                        <label for="f-reference" class="form-label fw-semibold">Reference</label>
                        <input id="f-reference" aria-describedby="f-reference-help" type="text" name="reference" class="form-control"
                               value="{{ old('reference', $payment->reference) }}" placeholder="Receipt / transaction ref">
                        <div id="f-reference-help" class="form-text">Receipt number, bank transfer ID, or ToyyibPay transaction reference for audit trail.</div>
                    </div>
                    <div class="mb-3">
                        <label for="f-discount-id" class="form-label fw-semibold">Discount</label>
                        <select id="f-discount-id" aria-describedby="f-discount-id-help" name="discount_id" class="form-select">
                            <option value="">No discount</option>
                            @foreach($discounts as $d)
                                <option value="{{ $d->id }}" {{ old('discount_id', $payment->discount_id) == $d->id ? 'selected' : '' }}>
                                    {{ $d->name }}
                                </option>
                            @endforeach
                        </select>
                        <div id="f-discount-id-help" class="form-text">Optional discount applied to reduce the payable amount.</div>
                    </div>
                    <div class="mb-4">
                        <label for="f-notes" class="form-label fw-semibold">Notes</label>
                        <textarea id="f-notes" aria-describedby="f-notes-help" name="notes" class="form-control" rows="2">{{ old('notes', $payment->notes) }}</textarea>
                        <div id="f-notes-help" class="form-text">Internal notes for administrators. Not visible to the member.</div>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">Update</button>
                        <a href="{{ route('admin.payments.show', $payment) }}" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script nonce="{{ $cspNonce }}">
// Reverting an online-paid fee: confirm, since its absorbed gateway charge leaves the fund figures.
document.getElementById('paymentEditForm')?.addEventListener('submit', function (e) {
    const msg = this.dataset.revertWarning;
    const status = this.querySelector('[name="status"]');
    if (msg && status && status.value !== 'paid' && !window.confirm(msg)) e.preventDefault();
});
</script>
@endpush
