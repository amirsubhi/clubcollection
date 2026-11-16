@extends('layouts.app')
@section('title', 'Add Payment')
@section('page-title', 'Add Payment')
@section('page-club', $club->name)

@section('content')
<div class="row justify-content-center">
    <div class="col-lg-7">
        <div class="card shadow-sm">
            <div class="card-body p-4">
                <form action="{{ route('admin.clubs.payments.store', $club) }}" method="POST" id="paymentForm">
                    @csrf
                    <div class="mb-3">
                        <label for="memberSelect" class="form-label fw-semibold">Member <span class="text-danger">*</span></label>
                        <select aria-describedby="memberSelect-help @error('user_id') memberSelect-error @enderror" @error('user_id') aria-invalid="true" @enderror name="user_id" id="memberSelect" class="form-select @error('user_id') is-invalid @enderror">
                            <option value="">Select member</option>
                            @foreach($members as $member)
                                <option value="{{ $member->id }}"
                                    data-level="{{ $member->pivot->job_level }}"
                                    {{ old('user_id') == $member->id ? 'selected' : '' }}>
                                    {{ $member->name }} ({{ $jobLevels[$member->pivot->job_level] ?? $member->pivot->job_level }})
                                </option>
                            @endforeach
                        </select>
                        <div id="memberSelect-help" class="form-text">The member this payment record belongs to. Job level shown in brackets.</div>
                        @error('user_id')<div id="memberSelect-error" role="alert" class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="mb-3">
                        <label for="frequencySelect" class="form-label fw-semibold">Frequency <span class="text-danger">*</span></label>
                        <select aria-describedby="frequencySelect-help @error('frequency') frequencySelect-error @enderror" @error('frequency') aria-invalid="true" @enderror name="frequency" id="frequencySelect" class="form-select @error('frequency') is-invalid @enderror">
                            <option value="monthly" {{ old('frequency','monthly') == 'monthly' ? 'selected' : '' }}>Monthly</option>
                            <option value="quarterly" {{ old('frequency') == 'quarterly' ? 'selected' : '' }}>Quarterly (3 months)</option>
                            <option value="yearly" {{ old('frequency') == 'yearly' ? 'selected' : '' }}>Yearly (12 months)</option>
                        </select>
                        <div id="frequencySelect-help" class="form-text">How many months this payment covers. The amount will be calculated accordingly.</div>
                        @error('frequency')<div id="frequencySelect-error" role="alert" class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="mb-3">
                        <label for="periodStart" class="form-label fw-semibold">Period Start <span class="text-danger">*</span></label>
                        <input aria-describedby="periodStart-help @error('period_start') periodStart-error @enderror" @error('period_start') aria-invalid="true" @enderror type="date" name="period_start" id="periodStart" class="form-control @error('period_start') is-invalid @enderror"
                               value="{{ old('period_start', now()->startOfMonth()->format('Y-m-d')) }}">
                        <div id="periodStart-help" class="form-text">First day of the coverage period. Defaults to the 1st of the current month.</div>
                        @error('period_start')<div id="periodStart-error" role="alert" class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="mb-3">
                        <label for="f-due-date" class="form-label fw-semibold">Due Date <span class="text-danger">*</span></label>
                        <input id="f-due-date" aria-describedby="f-due-date-help @error('due_date') f-due-date-error @enderror" @error('due_date') aria-invalid="true" @enderror type="date" name="due_date" class="form-control @error('due_date') is-invalid @enderror"
                               value="{{ old('due_date', now()->endOfMonth()->format('Y-m-d')) }}">
                        <div id="f-due-date-help" class="form-text">Deadline by which payment must be received. Payments past this date are marked overdue.</div>
                        @error('due_date')<div id="f-due-date-error" role="alert" class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="mb-3">
                        <label for="amountField" class="form-label fw-semibold">Amount (RM) <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text">RM</span>
                            <input @error('amount') aria-invalid="true" aria-describedby="amountField-error" @enderror type="number" step="0.01" name="amount" id="amountField"
                                   class="form-control @error('amount') is-invalid @enderror"
                                   value="{{ old('amount') }}" placeholder="Auto-calculated or override">
                        </div>
                        <div class="form-text" id="amountHint">Select member and frequency to auto-fill amount.</div>
                        @error('amount')<div id="amountField-error" role="alert" class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="mb-3">
                        <label for="f-discount-id" class="form-label fw-semibold">Discount (optional)</label>
                        <select id="f-discount-id" aria-describedby="f-discount-id-help" name="discount_id" class="form-select">
                            <option value="">No discount</option>
                            @foreach($discounts as $d)
                                <option value="{{ $d->id }}" {{ old('discount_id') == $d->id ? 'selected' : '' }}>
                                    {{ $d->name }} ({{ $d->type === 'fixed' ? 'RM '.number_format($d->value,2) : $d->value.'%' }})
                                </option>
                            @endforeach
                        </select>
                        <div id="f-discount-id-help" class="form-text">Optional promotional or welfare discount to apply to this payment.</div>
                    </div>

                    <div class="mb-4">
                        <label for="f-notes" class="form-label fw-semibold">Notes</label>
                        <textarea id="f-notes" aria-describedby="f-notes-help" name="notes" class="form-control" rows="2">{{ old('notes') }}</textarea>
                        <div id="f-notes-help" class="form-text">Internal notes visible only to administrators. Not shown to the member.</div>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Create Payment</button>
                        <a href="{{ route('admin.clubs.payments.index', $club) }}" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script nonce="{{ $cspNonce }}">
const feeRates = @json($feeRates);
const multipliers = { monthly: 1, quarterly: 3, yearly: 12 };

function recalcAmount() {
    const level = document.getElementById('memberSelect').selectedOptions[0]?.dataset.level;
    const freq  = document.getElementById('frequencySelect').value;
    if (level && feeRates[level]) {
        const base = parseFloat(feeRates[level].monthly_amount);
        const total = (base * multipliers[freq]).toFixed(2);
        document.getElementById('amountField').value = total;
        document.getElementById('amountHint').textContent = `RM ${feeRates[level].monthly_amount}/month × ${multipliers[freq]} = RM ${total}`;
    }
}
document.getElementById('memberSelect').addEventListener('change', recalcAmount);
document.getElementById('frequencySelect').addEventListener('change', recalcAmount);
</script>
@endpush
