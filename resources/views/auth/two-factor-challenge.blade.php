@extends('layouts.auth')
@section('title', 'Two-Factor Authentication')
@section('auth-subtitle', 'Verify your identity')

@section('content')
<div class="text-center mb-4">
    <div class="d-flex justify-content-center mb-2">
        <div style="width:56px;height:56px;background:#e8f4fd;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:26px;color:#0d6efd">
            <i class="bi bi-shield-lock"></i>
        </div>
    </div>
    <p class="text-muted small mb-0">Enter the 6-digit code from your authenticator app to continue.</p>
</div>

@if($errors->any())
<div class="alert alert-danger py-2 small mb-3">
    <i class="bi bi-exclamation-triangle me-1"></i>{{ $errors->first() }}
</div>
@endif

<form method="POST" action="{{ route('two-factor.verify') }}" id="totpForm">
    @csrf

    {{-- TOTP code --}}
    <div id="codeSection">
        <div class="mb-3">
            <label for="code" class="form-label">Authenticator Code</label>
            <input type="text" name="code" id="code"
                   class="form-control text-center font-monospace"
                   placeholder="000000"
                   inputmode="numeric"
                   autocomplete="one-time-code"
                   maxlength="6"
                   style="font-size:1.5rem;letter-spacing:0.4rem"
                   autofocus>
        </div>
        <button type="submit" class="btn btn-primary btn-auth mb-3">
            <i class="bi bi-shield-check me-1"></i>Verify
        </button>
        <div class="text-center">
            <button type="button" class="btn btn-link btn-sm p-0 text-muted" data-toggle-recovery>
                Use a recovery code instead
            </button>
        </div>
    </div>

    {{-- Recovery code --}}
    <div id="recoverySection" style="display:none">
        <div class="mb-3">
            <label for="recovery_code" class="form-label">Recovery Code</label>
            <input type="text" name="recovery_code" id="recovery_code"
                   class="form-control font-monospace text-center"
                   placeholder="XXXX-XXXX"
                   autocomplete="one-time-code"
                   style="letter-spacing:2px">
        </div>
        <button type="submit" class="btn btn-primary btn-auth mb-3">
            <i class="bi bi-key me-1"></i>Use Recovery Code
        </button>
        <div class="text-center">
            <button type="button" class="btn btn-link btn-sm p-0 text-muted" data-toggle-recovery>
                Use authenticator code instead
            </button>
        </div>
    </div>
</form>

<div class="text-center mt-3">
    <form method="POST" action="{{ route('logout') }}" class="d-inline">
        @csrf
        <button type="submit" class="btn btn-link btn-sm p-0 text-muted small">
            <i class="bi bi-arrow-left me-1"></i>Sign in as a different user
        </button>
    </form>
</div>

<script nonce="{{ $cspNonce ?? '' }}">
function toggleRecovery() {
    const code     = document.getElementById('codeSection');
    const recovery = document.getElementById('recoverySection');
    const isCode   = code.style.display !== 'none';
    code.style.display     = isCode ? 'none' : '';
    recovery.style.display = isCode ? '' : 'none';
    // Clear both inputs when switching
    document.getElementById('code').value = '';
    document.getElementById('recovery_code').value = '';
    (isCode ? document.getElementById('recovery_code') : document.getElementById('code')).focus();
}
document.querySelectorAll('[data-toggle-recovery]').forEach(btn => {
    btn.addEventListener('click', toggleRecovery);
});
</script>
@endsection
