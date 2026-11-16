@extends('layouts.auth')
@section('title', __('Set a new password'))
@section('auth-subtitle', __('Use at least 8 characters.'))

@section('content')
@include('auth.partials.alerts')

<form method="POST" action="{{ route('password.update') }}" class="flex flex-col gap-5">
    @csrf
    <input type="hidden" name="token" value="{{ $token }}">

    <div>
        <label for="email" class="auth-label">{{ __('Email') }}</label>
        <input id="email" type="email" name="email" value="{{ $email ?? old('email') }}" class="auth-input"
               required autocomplete="email" autocapitalize="none" spellcheck="false"
               @error('email') aria-invalid="true" aria-describedby="auth-error" @enderror>
    </div>

    <div>
        <label for="password" class="auth-label">{{ __('New password') }}</label>
        <input id="password" type="password" name="password" class="auth-input"
               required minlength="8" autocomplete="new-password" autofocus
               @error('password') aria-invalid="true" aria-describedby="auth-error" @enderror>
    </div>

    <div>
        <label for="password-confirm" class="auth-label">{{ __('Type the new password again') }}</label>
        <input id="password-confirm" type="password" name="password_confirmation" class="auth-input"
               required minlength="8" autocomplete="new-password">
    </div>

    <button type="submit" class="auth-btn">{{ __('Save new password') }}</button>
</form>
@endsection
