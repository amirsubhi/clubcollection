@extends('layouts.app')
@section('title', 'New Discount')
@section('page-title', $club->name . ' — New Discount')

@section('content')
<div class="row justify-content-center">
    <div class="col-lg-5">
        <div class="card shadow-sm">
            <div class="card-body p-4">
                <form action="{{ route('admin.clubs.discounts.store', $club) }}" method="POST">
                    @csrf
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control @error('name') is-invalid @enderror"
                               value="{{ old('name') }}" placeholder="e.g. Hari Raya Special">
                        <div class="form-text">A short, descriptive label for this discount (e.g. "Staff Welfare Rebate").</div>
                        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Type <span class="text-danger">*</span></label>
                        <select name="type" class="form-select">
                            <option value="fixed" {{ old('type') == 'fixed' ? 'selected' : '' }}>Fixed Amount (RM)</option>
                            <option value="percentage" {{ old('type') == 'percentage' ? 'selected' : '' }}>Percentage (%)</option>
                        </select>
                        <div class="form-text">Fixed deducts a set Ringgit amount; Percentage deducts a proportion of the total.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Value <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" name="value" class="form-control @error('value') is-invalid @enderror"
                               value="{{ old('value') }}" placeholder="e.g. 5.00 or 10">
                        <div class="form-text">For Fixed: enter the RM amount (e.g. 5.00). For Percentage: enter the % value (e.g. 10 for 10%).</div>
                        @error('value')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Valid From <span class="text-danger">*</span></label>
                        <input type="date" name="valid_from" class="form-control"
                               value="{{ old('valid_from', date('Y-m-d')) }}">
                        <div class="form-text">The date from which this discount can be applied to payments.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Valid To</label>
                        <input type="date" name="valid_to" class="form-control" value="{{ old('valid_to') }}">
                        <div class="form-text">Leave blank for no expiry. After this date the discount can no longer be applied.</div>
                    </div>
                    <div class="mb-4">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="is_active" value="1" id="isActive" checked>
                            <label class="form-check-label" for="isActive">Active</label>
                        </div>
                        <div class="form-text mt-1">Only active discounts appear when creating or editing payments.</div>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">Create Discount</button>
                        <a href="{{ route('admin.clubs.discounts.index', $club) }}" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
