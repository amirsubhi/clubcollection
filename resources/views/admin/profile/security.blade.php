@extends('layouts.app')
@section('title', 'Security & 2FA')
@section('page-title', 'Security Settings')

@section('content')
<div class="row justify-content-center">
    <div class="col-lg-7 col-xl-6">

        <h5 class="fw-bold mb-1">Security Settings</h5>
        <p class="text-muted small mb-4">Manage your account security and two-factor authentication.</p>

        {{-- Recovery codes shown once after enabling --}}
        @if(isset($recoveryCodes) && count($recoveryCodes))
        <div class="alert alert-warning border-warning mb-4">
            <div class="fw-semibold mb-2"><i class="bi bi-exclamation-triangle me-1"></i>Save your recovery codes now</div>
            <p class="small mb-2">These codes will not be shown again. Store them somewhere safe (e.g. a password manager). Each code can only be used once.</p>
            <div class="bg-white rounded border p-3 font-monospace small d-grid" style="gap:4px;grid-template-columns:1fr 1fr">
                @foreach($recoveryCodes as $code)
                <span>{{ $code }}</span>
                @endforeach
            </div>
        </div>
        @endif

        {{-- 2FA card --}}
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-body">
                <div class="d-flex align-items-start justify-content-between mb-3">
                    <div>
                        <h6 class="fw-semibold mb-1">
                            <i class="bi bi-shield-lock me-1"></i>Two-Factor Authentication
                        </h6>
                        <p class="text-muted small mb-0">
                            Adds a second layer of security via a time-based one-time password (TOTP) from an authenticator app (Google Authenticator, Authy, etc.).
                        </p>
                    </div>
                    @if($user->hasEnabledTwoFactor())
                    <span class="badge bg-success ms-3 mt-1 flex-shrink-0">Enabled</span>
                    @else
                    <span class="badge bg-secondary ms-3 mt-1 flex-shrink-0">Disabled</span>
                    @endif
                </div>

                @if($user->hasEnabledTwoFactor())
                <div class="alert alert-success py-2 small mb-3">
                    <i class="bi bi-check-circle me-1"></i>
                    2FA is active. Your account is protected by your authenticator app.
                </div>
                <form method="POST" action="{{ route('profile.2fa.disable') }}">
                    @csrf
                    @method('DELETE')
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Confirm your password to disable 2FA</label>
                        <input type="password" name="password" class="form-control form-control-sm @error('password') is-invalid @enderror"
                               placeholder="Current password" autocomplete="current-password" required>
                        @error('password')
                        <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <button type="submit" class="btn btn-sm btn-outline-danger"
                            onclick="return confirm('Disable 2FA? Your account will only require email + password to sign in.')">
                        <i class="bi bi-shield-x me-1"></i>Disable 2FA
                    </button>
                </form>
                @else
                <form method="POST" action="{{ route('profile.2fa.enable') }}">
                    @csrf
                    <p class="small text-muted mb-3">
                        You will need an authenticator app on your phone. After enabling, you will be asked to scan a QR code and confirm with a code from the app.
                    </p>
                    <button type="submit" class="btn btn-sm btn-primary">
                        <i class="bi bi-shield-check me-1"></i>Enable 2FA
                    </button>
                </form>
                @endif
            </div>
        </div>

    </div>
</div>
@endsection
