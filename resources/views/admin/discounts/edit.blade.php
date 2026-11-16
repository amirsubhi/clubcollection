@extends('layouts.app')
@section('title', 'Edit Discount')
@section('page-title', 'Edit Discount')

@section('content')
<div class="row justify-content-center">
    <div class="col-lg-5">
        <div class="card shadow-sm">
            <div class="card-body p-4">
                <form action="{{ route('admin.discounts.update', $discount) }}" method="POST">
                    @csrf @method('PUT')
                    <div class="mb-3">
                        <label for="f-name" class="form-label fw-semibold">Name <span class="text-danger">*</span></label>
                        <input id="f-name" aria-describedby="f-name-help" type="text" name="name" class="form-control" value="{{ old('name', $discount->name) }}">
                        <div id="f-name-help" class="form-text">A short, descriptive label for this discount.</div>
                    </div>
                    <div class="mb-3">
                        <label for="f-type" class="form-label fw-semibold">Type <span class="text-danger">*</span></label>
                        <select id="f-type" aria-describedby="f-type-help" name="type" class="form-select">
                            <option value="fixed" {{ old('type', $discount->type) == 'fixed' ? 'selected' : '' }}>Fixed Amount (RM)</option>
                            <option value="percentage" {{ old('type', $discount->type) == 'percentage' ? 'selected' : '' }}>Percentage (%)</option>
                        </select>
                        <div id="f-type-help" class="form-text">Fixed deducts a set Ringgit amount; Percentage deducts a proportion of the total.</div>
                    </div>
                    <div class="mb-3">
                        <label for="f-value" class="form-label fw-semibold">Value <span class="text-danger">*</span></label>
                        <input id="f-value" aria-describedby="f-value-help" type="number" step="0.01" name="value" class="form-control" value="{{ old('value', $discount->value) }}">
                        <div id="f-value-help" class="form-text">For Fixed: enter the RM amount (e.g. 5.00). For Percentage: enter the % value (e.g. 10 for 10%).</div>
                    </div>
                    <div class="mb-3">
                        <label for="f-valid-from" class="form-label fw-semibold">Valid From <span class="text-danger">*</span></label>
                        <input id="f-valid-from" aria-describedby="f-valid-from-help" type="date" name="valid_from" class="form-control"
                               value="{{ old('valid_from', $discount->valid_from->format('Y-m-d')) }}">
                        <div id="f-valid-from-help" class="form-text">The date from which this discount can be applied to payments.</div>
                    </div>
                    <div class="mb-3">
                        <label for="f-valid-to" class="form-label fw-semibold">Valid To</label>
                        <input id="f-valid-to" aria-describedby="f-valid-to-help" type="date" name="valid_to" class="form-control"
                               value="{{ old('valid_to', $discount->valid_to?->format('Y-m-d')) }}">
                        <div id="f-valid-to-help" class="form-text">Leave blank for no expiry. After this date the discount can no longer be applied.</div>
                    </div>
                    <div class="mb-4">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="is_active" value="1"
                                   id="isActive" {{ old('is_active', $discount->is_active) ? 'checked' : '' }}>
                            <label class="form-check-label" for="isActive">Active</label>
                        </div>
                        <div class="form-text mt-1">Only active discounts appear when creating or editing payments.</div>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">Update</button>
                        <a href="{{ route('admin.clubs.discounts.index', $discount->club) }}" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
