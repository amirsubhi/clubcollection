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
        <input id="password" type="password" name="password" class="auth-input"
               required autocomplete="current-password"
               @error('password') aria-invalid="true" aria-describedby="auth-error" @enderror>
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
@endsection
