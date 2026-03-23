@extends('layouts.app')
@section('title', 'Create Club')
@section('page-title', 'Create Club')

@section('content')
<div class="row justify-content-center">
    <div class="col-lg-6">
        <div class="card shadow-sm">
            <div class="card-body p-4">
                <form action="{{ route('admin.clubs.store') }}" method="POST" enctype="multipart/form-data">
                    @csrf
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Club Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control @error('name') is-invalid @enderror"
                               value="{{ old('name') }}" placeholder="e.g. Recreation Club">
                        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Club Email</label>
                        <input type="email" name="email" class="form-control @error('email') is-invalid @enderror"
                               value="{{ old('email') }}" placeholder="club@company.com">
                        @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-4">
                        <label class="form-label fw-semibold">Club Logo</label>
                        <input type="file" name="logo" class="form-control @error('logo') is-invalid @enderror"
                               accept="image/*">
                        <div class="form-text">JPG, PNG or GIF. Max 2MB.</div>
                        @error('logo')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    {{-- ToyyibPay --}}
                    <div class="border rounded p-3 mb-4 bg-light">
                        <div class="d-flex align-items-center gap-2 mb-3">
                            <i class="bi bi-credit-card-2-front text-primary"></i>
                            <span class="fw-semibold">ToyyibPay Payment Gateway</span>
                            <span class="badge bg-secondary ms-auto">Optional</span>
                        </div>
                        <p class="text-muted small mb-3">
                            Enter the credentials for this club's own ToyyibPay account so payments
                            go directly to their bank account. Leave blank to configure later.
                        </p>
                        <div class="mb-3">
                            <label class="form-label fw-semibold small">User Secret Key</label>
                            <input type="password" name="toyyibpay_secret_key"
                                   class="form-control form-control-sm @error('toyyibpay_secret_key') is-invalid @enderror"
                                   value="{{ old('toyyibpay_secret_key') }}"
                                   placeholder="From ToyyibPay → My Profile → User Secret Key"
                                   autocomplete="off">
                            @error('toyyibpay_secret_key')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="mb-0">
                            <label class="form-label fw-semibold small">Category Code</label>
                            <input type="text" name="toyyibpay_category_code"
                                   class="form-control form-control-sm @error('toyyibpay_category_code') is-invalid @enderror"
                                   value="{{ old('toyyibpay_category_code') }}"
                                   placeholder="e.g. abc123xyz">
                            <div class="form-text">From ToyyibPay → My Category → Category Code.</div>
                            @error('toyyibpay_category_code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-lg me-1"></i>Create Club
                        </button>
                        <a href="{{ route('admin.clubs.index') }}" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
