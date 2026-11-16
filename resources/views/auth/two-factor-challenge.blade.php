@extends('layouts.auth')
@section('title', __('Two-step check'))
@section('auth-subtitle', __('Enter the 6-digit code from your authenticator app.'))

@section('content')
@include('auth.partials.alerts')

<form method="POST" action="{{ route('two-factor.verify') }}" class="flex flex-col gap-5">
    @csrf

    {{-- Authenticator code --}}
    <div id="codeSection" class="flex flex-col gap-5">
        <div>
            <label for="code" class="auth-label">{{ __('Authenticator code') }}</label>
            <input type="text" name="code" id="code" class="auth-input auth-code"
                   inputmode="numeric" pattern="[0-9]*" autocomplete="one-time-code" maxlength="6" autofocus
                   @error('code') aria-invalid="true" aria-describedby="auth-error" @enderror>
        </div>
        <button type="submit" class="auth-btn">{{ __('Verify') }}</button>
        <button type="button" class="auth-link self-center" data-toggle-recovery>{{ __('Use a recovery code instead') }}</button>
    </div>

    {{-- Recovery code --}}
    <div id="recoverySection" class="flex flex-col gap-5" hidden>
        <div>
            <label for="recovery_code" class="auth-label">{{ __('Recovery code') }}</label>
            <input type="text" name="recovery_code" id="recovery_code" class="auth-input auth-code"
                   autocomplete="one-time-code" autocapitalize="characters" spellcheck="false"
                   @error('recovery_code') aria-invalid="true" aria-describedby="auth-error" @enderror>
        </div>
        <button type="submit" class="auth-btn">{{ __('Verify') }}</button>
        <button type="button" class="auth-link self-center" data-toggle-recovery>{{ __('Use the authenticator code instead') }}</button>
    </div>
</form>

<form method="POST" action="{{ route('logout') }}" class="mt-4 flex justify-center border-t pt-4">
    @csrf
    <button type="submit" class="auth-link text-muted-foreground">{{ __('Sign in as someone else') }}</button>
</form>

<script nonce="{{ $cspNonce ?? '' }}">
(function () {
    // The hidden attribute, not inline styles: the CSP blocks inline style blocks without a nonce.
    var code = document.getElementById('codeSection');
    var recovery = document.getElementById('recoverySection');
    function show(useRecovery) {
        code.hidden = useRecovery;
        recovery.hidden = ! useRecovery;
        document.getElementById('code').value = '';
        document.getElementById('recovery_code').value = '';
        document.getElementById(useRecovery ? 'recovery_code' : 'code').focus();
    }
    document.querySelectorAll('[data-toggle-recovery]').forEach(function (btn) {
        btn.addEventListener('click', function () { show(! code.hidden); });
    });
    @if ($errors->has('recovery_code'))
    show(true);
    @endif
})();
</script>
@endsection
