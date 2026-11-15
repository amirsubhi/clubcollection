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
        <div class="mb-1.5 flex flex-wrap items-baseline justify-between gap-x-3">
            <label for="password" class="auth-label mb-0">{{ __('Password') }}</label>
            @if (Route::has('password.request'))
                <a href="{{ route('password.request') }}" class="auth-link text-sm">{{ __('Forgot password?') }}</a>
            @endif
        </div>
        <input id="password" type="password" name="password" class="auth-input"
               required autocomplete="current-password"
               @error('password') aria-invalid="true" aria-describedby="auth-error" @enderror>
    </div>

    <label class="flex min-h-11 cursor-pointer items-center gap-3 text-base">
        <input type="checkbox" name="remember" id="remember" class="auth-check" {{ old('remember') ? 'checked' : '' }}>
        {{ __('Keep me signed in on this device') }}
    </label>

    <button type="submit" class="auth-btn">{{ __('Sign in') }}</button>
</form>
@endsection
