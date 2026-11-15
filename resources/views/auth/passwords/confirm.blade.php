@extends('layouts.auth')
@section('title', __('Confirm your password'))
@section('auth-subtitle', __('Please enter your password again before you continue.'))

@section('content')
@include('auth.partials.alerts')

<form method="POST" action="{{ route('password.confirm') }}" class="flex flex-col gap-5">
    @csrf

    <div>
        <div class="mb-1.5 flex flex-wrap items-baseline justify-between gap-x-3">
            <label for="password" class="auth-label mb-0">{{ __('Password') }}</label>
            @if (Route::has('password.request'))
                <a href="{{ route('password.request') }}" class="auth-link text-sm">{{ __('Forgot password?') }}</a>
            @endif
        </div>
        <input id="password" type="password" name="password" class="auth-input"
               required autocomplete="current-password" autofocus
               @error('password') aria-invalid="true" aria-describedby="auth-error" @enderror>
    </div>

    <button type="submit" class="auth-btn">{{ __('Confirm') }}</button>
</form>
@endsection
