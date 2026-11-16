@extends('layouts.auth')
@section('title', __('Reset password'))
@section('auth-subtitle', __('We will email you a link to set a new password.'))

@section('content')
@include('auth.partials.alerts')

<form method="POST" action="{{ route('password.email') }}" class="flex flex-col gap-5">
    @csrf

    <div>
        <label for="email" class="auth-label">{{ __('Email') }}</label>
        <input id="email" type="email" name="email" value="{{ old('email') }}" class="auth-input"
               required autocomplete="email" autocapitalize="none" spellcheck="false" autofocus
               @error('email') aria-invalid="true" aria-describedby="auth-error" @enderror>
    </div>

    <button type="submit" class="auth-btn">{{ __('Send reset link') }}</button>

    <a href="{{ route('login') }}" class="auth-link self-center">{{ __('Back to sign in') }}</a>
</form>
@endsection
