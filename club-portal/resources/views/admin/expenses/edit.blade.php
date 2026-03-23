@extends('layouts.app')
@section('title', 'Edit Expense')
@section('page-title', $club->name . ' — Edit Expense')

@section('content')
<div class="row justify-content-center">
    <div class="col-lg-6">
        <div class="card shadow-sm">
            <div class="card-body p-4">
                <form action="{{ route('admin.expenses.update', $expense) }}" method="POST" enctype="multipart/form-data">
                    @csrf @method('PUT')
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Category <span class="text-danger">*</span></label>
                        <select name="expense_category_id" class="form-select">
                            @foreach($categories as $cat)
                                <option value="{{ $cat->id }}" {{ old('expense_category_id', $expense->expense_category_id) == $cat->id ? 'selected' : '' }}>
                                    {{ $cat->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Description <span class="text-danger">*</span></label>
                        <textarea name="description" class="form-control" rows="2">{{ old('description', $expense->description) }}</textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Amount (RM) <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text">RM</span>
                            <input type="number" step="0.01" name="amount" class="form-control"
                                   value="{{ old('amount', $expense->amount) }}">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Date <span class="text-danger">*</span></label>
                        <input type="date" name="expense_date" class="form-control"
                               value="{{ old('expense_date', $expense->expense_date->format('Y-m-d')) }}">
                    </div>
                    <div class="mb-4">
                        <label class="form-label fw-semibold">Receipt</label>
                        @if($expense->receipt)
                            <div class="mb-2">
                                <a href="{{ asset('storage/'.$expense->receipt) }}" target="_blank" class="btn btn-sm btn-outline-secondary">
                                    <i class="bi bi-file-earmark me-1"></i>Current Receipt
                                </a>
                            </div>
                        @endif
                        <input type="file" name="receipt" class="form-control" accept="image/*,.pdf">
                        <div class="form-text">Upload new file to replace existing. JPG, PNG or PDF. Max 5MB.</div>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">Update</button>
                        <a href="{{ route('admin.expenses.show', $expense) }}" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
