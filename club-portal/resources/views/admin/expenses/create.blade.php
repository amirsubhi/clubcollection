@extends('layouts.app')
@section('title', 'Record Expense')
@section('page-title', $club->name . ' — Record Expense')

@section('content')
<div class="row justify-content-center">
    <div class="col-lg-6">
        <div class="card shadow-sm">
            <div class="card-body p-4">
                <form action="{{ route('admin.expenses.store', $club) }}" method="POST" enctype="multipart/form-data">
                    @csrf
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Category <span class="text-danger">*</span></label>
                        <select name="expense_category_id" class="form-select @error('expense_category_id') is-invalid @enderror">
                            <option value="">Select category</option>
                            @foreach($categories as $cat)
                                <option value="{{ $cat->id }}" {{ old('expense_category_id') == $cat->id ? 'selected' : '' }}>
                                    {{ $cat->name }}
                                </option>
                            @endforeach
                        </select>
                        <div class="form-text">Choose the expense category that best describes this spending.</div>
                        @error('expense_category_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Description <span class="text-danger">*</span></label>
                        <textarea name="description" class="form-control @error('description') is-invalid @enderror"
                                  rows="2">{{ old('description') }}</textarea>
                        <div class="form-text">Briefly describe what this expense was for (e.g. "Annual dinner venue booking").</div>
                        @error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Amount (RM) <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text">RM</span>
                            <input type="number" step="0.01" min="0.01" name="amount"
                                   class="form-control @error('amount') is-invalid @enderror"
                                   value="{{ old('amount') }}">
                        </div>
                        <div class="form-text">Enter the actual amount paid in Ringgit Malaysia.</div>
                        @error('amount')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Date <span class="text-danger">*</span></label>
                        <input type="date" name="expense_date" class="form-control @error('expense_date') is-invalid @enderror"
                               value="{{ old('expense_date', date('Y-m-d')) }}">
                        <div class="form-text">The date the expense was incurred or payment was made. Defaults to today.</div>
                        @error('expense_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-4">
                        <label class="form-label fw-semibold">Receipt (optional)</label>
                        <input type="file" name="receipt" class="form-control @error('receipt') is-invalid @enderror"
                               accept="image/*,.pdf">
                        <div class="form-text">Upload a photo or scan of the receipt for record-keeping. JPG, PNG or PDF. Max 5MB.</div>
                        @error('receipt')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Save Expense</button>
                        <a href="{{ route('admin.expenses.index', $club) }}" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
