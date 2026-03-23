@extends('layouts.app')
@section('title', 'Edit Payment')
@section('page-title', 'Edit Payment')

@section('content')
<div class="row justify-content-center">
    <div class="col-lg-6">
        <div class="card shadow-sm">
            <div class="card-body p-4">
                <p class="text-muted small mb-4">Member: <strong>{{ $payment->user->name }}</strong> &middot; Period: {{ $payment->period_start->format('d M Y') }} – {{ $payment->period_end->format('d M Y') }}</p>
                <form action="{{ route('admin.payments.update', $payment) }}" method="POST">
                    @csrf @method('PUT')
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Amount (RM) <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text">RM</span>
                            <input type="number" step="0.01" name="amount" class="form-control"
                                   value="{{ old('amount', $payment->amount) }}">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Due Date <span class="text-danger">*</span></label>
                        <input type="date" name="due_date" class="form-control"
                               value="{{ old('due_date', $payment->due_date->format('Y-m-d')) }}">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Status <span class="text-danger">*</span></label>
                        <select name="status" class="form-select">
                            <option value="pending" {{ old('status', $payment->status) == 'pending' ? 'selected' : '' }}>Pending</option>
                            <option value="paid" {{ old('status', $payment->status) == 'paid' ? 'selected' : '' }}>Paid</option>
                            <option value="overdue" {{ old('status', $payment->status) == 'overdue' ? 'selected' : '' }}>Overdue</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Paid Date</label>
                        <input type="date" name="paid_date" class="form-control"
                               value="{{ old('paid_date', $payment->paid_date?->format('Y-m-d')) }}">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Reference</label>
                        <input type="text" name="reference" class="form-control"
                               value="{{ old('reference', $payment->reference) }}" placeholder="Receipt / transaction ref">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Discount</label>
                        <select name="discount_id" class="form-select">
                            <option value="">No discount</option>
                            @foreach($discounts as $d)
                                <option value="{{ $d->id }}" {{ old('discount_id', $payment->discount_id) == $d->id ? 'selected' : '' }}>
                                    {{ $d->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-4">
                        <label class="form-label fw-semibold">Notes</label>
                        <textarea name="notes" class="form-control" rows="2">{{ old('notes', $payment->notes) }}</textarea>
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
