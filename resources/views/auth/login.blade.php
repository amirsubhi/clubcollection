@extends('layouts.auth')
@section('title', __('Sign in'))
@section('auth-subtitle', __('Sign in to pay your fees and see your receipts.'))

@section('content')
@include('auth.partials.alerts')

<form method="POST" action="{{ route('login') }}" class="flex flex-col gap-5">
    @csrf

    <div>
        <label for="email" class="auth-label">{{ __('Email') }}</label>
        <input id="email" type="email" name="email" value="{{ old('email') }}" class="auth-input"
               required autocomplete="email" autocapitalize="none" spellcheck="false" autofocus
               @error('email') aria-invalid="true" aria-describedby="auth-error" @enderror>
    </div>

    <div>
        <label for="password" class="auth-label">{{ __('Password') }}</label>
        {{-- Show/hide: the button stays hidden until the script below runs (no JS or blocked JS = a plain field). --}}
        <div class="relative">
            <input id="password" type="password" name="password" class="auth-input pr-24"
                   required autocomplete="current-password"
                   @error('password') aria-invalid="true" aria-describedby="auth-error" @enderror>
            <button type="button" id="password-toggle" class="auth-link absolute inset-y-0 right-1 px-3" hidden
                    aria-controls="password" data-testid="password-toggle"
                    data-show="{{ __('Show') }}" data-hide="{{ __('Hide') }}">
                <span data-label>{{ __('Show') }}</span><span class="sr-only"> {{ __('password') }}</span>
            </button>
        </div>
        {{-- After the password field, so Tab goes email → password without a stop in between. --}}
        @if (Route::has('password.request'))
            <p class="mt-2 text-sm"><a href="{{ route('password.request') }}" class="auth-link inline-flex min-h-11 items-center">{{ __('Forgot password?') }}</a></p>
        @endif
    </div>

    <label class="flex min-h-11 cursor-pointer items-center gap-3 text-base">
        <input type="checkbox" name="remember" id="remember" class="auth-check" {{ old('remember') ? 'checked' : '' }}>
        {{ __('Keep me signed in on this device') }}
    </label>

    <button type="submit" class="auth-btn">{{ __('Sign in') }}</button>
</form>

<script nonce="{{ $cspNonce ?? '' }}">
(function () {
    var input = document.getElementById('password');
    var btn = document.getElementById('password-toggle');
    if (! input || ! btn) return;
    var label = btn.querySelector('[data-label]');
    btn.hidden = false;
    btn.addEventListener('click', function () {
        var show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        label.textContent = show ? btn.dataset.hide : btn.dataset.show;
        input.focus();
    });
    // Never submit the password as a visible field left on screen.
    input.form.addEventListener('submit', function () { input.type = 'password'; });
})();
</script>
@endsection

{{-- Front page: how to join. There is no self sign-up; the club admin adds members. --}}
@section('after-card')
<section class="auth-card mt-4" aria-labelledby="join-heading" data-testid="auth-join">
    <h2 id="join-heading" class="font-display text-xl font-bold leading-tight">{{ __('New member? How to join') }}</h2>
    <p class="mt-2 text-base text-muted-foreground">{{ __('There is no sign-up form. Your club admin adds you as a member, then you get an email with your sign-in details and a temporary password.') }}</p>
    <p class="mt-2 text-base text-muted-foreground">{{ __('To join, or if you cannot sign in, contact your club admin.') }}</p>
    @if ($authHelpClub ?? null)
        <p class="mt-1">
            @if ($authHelpClub['whatsapp'])
                <a href="https://wa.me/{{ $authHelpClub['whatsapp'] }}" target="_blank" rel="noopener noreferrer" class="auth-link" data-testid="auth-join-whatsapp">{{ __('WhatsApp :club', ['club' => $authHelpClub['name']]) }}</a>
            @else
                <a href="mailto:{{ $authHelpClub['email'] }}" class="auth-link" data-testid="auth-join-email">{{ __('Email :club', ['club' => $authHelpClub['name']]) }}</a>
            @endif
        </p>
    @endif
</section>
@endsection
