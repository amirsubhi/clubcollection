@extends('layouts.app')
@section('title', 'Edit Club')
@section('page-title', 'Edit Club')

@section('content')
<div class="row justify-content-center">
    <div class="col-lg-6">
        <div class="card shadow-sm">
            <div class="card-body p-4">
                <form action="{{ route('admin.clubs.update', $club) }}" method="POST" enctype="multipart/form-data">
                    @csrf @method('PUT')
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Club Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control @error('name') is-invalid @enderror"
                               value="{{ old('name', $club->name) }}">
                        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Club Email</label>
                        <input type="email" name="email" class="form-control @error('email') is-invalid @enderror"
                               value="{{ old('email', $club->email) }}">
                        @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Club Logo</label>
                        @if($club->logo)
                            <div class="mb-2">
                                <img src="{{ asset('storage/'.$club->logo) }}" alt="{{ $club->name }} logo" height="60" class="rounded border">
                                <small class="text-muted ms-2">Current logo</small>
                            </div>
                        @endif
                        <input type="file" name="logo" class="form-control @error('logo') is-invalid @enderror"
                               accept="image/*">
                        <div class="form-text">Upload a new logo to replace existing. JPG, PNG or GIF. Max 2MB.</div>
                        @error('logo')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-4">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="is_active" value="1"
                                   id="isActive" {{ old('is_active', $club->is_active) ? 'checked' : '' }}>
                            <label class="form-check-label" for="isActive">Active</label>
                        </div>
                    </div>

                    {{-- ToyyibPay --}}
                    <div class="border rounded p-3 mb-4 {{ $club->hasToyyibPayCredentials() ? 'border-success bg-success bg-opacity-10' : 'bg-light' }}">
                        <div class="d-flex align-items-center gap-2 mb-3">
                            <i class="bi bi-credit-card-2-front {{ $club->hasToyyibPayCredentials() ? 'text-success' : 'text-secondary' }}"></i>
                            <span class="fw-semibold">ToyyibPay Payment Gateway</span>
                            @if($club->hasToyyibPayCredentials())
                                <span class="badge bg-success ms-auto">
                                    <i class="bi bi-check-circle me-1"></i>Configured
                                </span>
                            @else
                                <span class="badge bg-warning text-dark ms-auto">Not Configured</span>
                            @endif
                        </div>
                        <p class="text-muted small mb-3">
                            Members can only pay online when this club has valid credentials.
                            Leave the secret key blank to keep the existing one.
                        </p>
                        <div class="mb-3">
                            <label class="form-label fw-semibold small">User Secret Key</label>
                            <div class="input-group input-group-sm">
                                <input type="password" name="toyyibpay_secret_key" id="secretKeyInput"
                                       class="form-control @error('toyyibpay_secret_key') is-invalid @enderror"
                                       value="{{ old('toyyibpay_secret_key') }}"
                                       placeholder="{{ $club->hasToyyibPayCredentials() ? '••••••••••••••••  (leave blank to keep current)' : 'Enter User Secret Key' }}"
                                       autocomplete="off">
                                <button type="button" id="toggleSecretBtn" class="btn btn-outline-secondary"
                                        title="Show/hide" aria-label="Show or hide secret key">
                                    <i class="bi bi-eye" id="eyeIcon"></i>
                                </button>
                            </div>
                            @error('toyyibpay_secret_key')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                        </div>
                        <div class="mb-0">
                            <label class="form-label fw-semibold small">Category Code</label>
                            <input type="text" name="toyyibpay_category_code"
                                   class="form-control form-control-sm @error('toyyibpay_category_code') is-invalid @enderror"
                                   value="{{ old('toyyibpay_category_code', $club->toyyibpay_category_code) }}"
                                   placeholder="e.g. abc123xyz">
                            <div class="form-text">From ToyyibPay → My Category → Category Code.</div>
                            @error('toyyibpay_category_code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-lg me-1"></i>Update Club
                        </button>
                        <a href="{{ route('admin.clubs.index') }}" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@push('scripts')
<script nonce="{{ $cspNonce }}">
document.getElementById('toggleSecretBtn')?.addEventListener('click', () => {
    const input = document.getElementById('secretKeyInput');
    const icon  = document.getElementById('eyeIcon');
    if (input.type === 'password') {
        input.type = 'text';
        icon.className = 'bi bi-eye-slash';
    } else {
        input.type = 'password';
        icon.className = 'bi bi-eye';
    }
});
</script>
@endpush
@endsection
