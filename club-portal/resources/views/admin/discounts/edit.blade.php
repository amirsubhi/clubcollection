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
                        <label class="form-label fw-semibold">Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" value="{{ old('name', $discount->name) }}">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Type <span class="text-danger">*</span></label>
                        <select name="type" class="form-select">
                            <option value="fixed" {{ old('type', $discount->type) == 'fixed' ? 'selected' : '' }}>Fixed Amount (RM)</option>
                            <option value="percentage" {{ old('type', $discount->type) == 'percentage' ? 'selected' : '' }}>Percentage (%)</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Value <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" name="value" class="form-control" value="{{ old('value', $discount->value) }}">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Valid From <span class="text-danger">*</span></label>
                        <input type="date" name="valid_from" class="form-control"
                               value="{{ old('valid_from', $discount->valid_from->format('Y-m-d')) }}">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Valid To</label>
                        <input type="date" name="valid_to" class="form-control"
                               value="{{ old('valid_to', $discount->valid_to?->format('Y-m-d')) }}">
                    </div>
                    <div class="mb-4">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="is_active" value="1"
                                   id="isActive" {{ old('is_active', $discount->is_active) ? 'checked' : '' }}>
                            <label class="form-check-label" for="isActive">Active</label>
                        </div>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">Update</button>
                        <a href="{{ route('admin.discounts.index', $discount->club) }}" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
