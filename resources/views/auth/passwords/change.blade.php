@extends('layouts.auth')
@section('title', $forced ? __('Choose your own password') : __('Change password'))
@section('auth-subtitle', $forced
    ? __('You signed in with a temporary password from your email. Please choose your own password to continue.')
    : __(\App\Support\Auth\NewPassword::HINT))

@section('content')
@include('auth.partials.alerts')

<form method="POST" action="{{ route('password.change.update') }}" class="flex flex-col gap-5" data-testid="change-password-form">
    @csrf

    <div>
        <label for="current_password" class="auth-label">{{ $forced ? __('Temporary password (from your email)') : __('Current password') }}</label>
        <input id="current_password" type="password" name="current_password" class="auth-input"
               required autocomplete="current-password" autofocus
               @error('current_password') aria-invalid="true" aria-describedby="auth-error" @enderror>
    </div>

    <div>
        <label for="password" class="auth-label">{{ __('New password') }}</label>
        <input id="password" type="password" name="password" class="auth-input"
               required minlength="12" autocomplete="new-password" aria-describedby="password-hint @error('password') auth-error @enderror"
               @error('password') aria-invalid="true" @enderror>
        <p id="password-hint" class="mt-1.5 text-sm text-muted-foreground">{{ __(\App\Support\Auth\NewPassword::HINT) }}</p>
    </div>

    <div>
        <label for="password-confirm" class="auth-label">{{ __('Type the new password again') }}</label>
        <input id="password-confirm" type="password" name="password_confirmation" class="auth-input"
               required minlength="12" autocomplete="new-password">
    </div>

    <button type="submit" class="auth-btn">{{ __('Save new password') }}</button>
</form>

<form method="POST" action="{{ route('logout') }}" class="mt-4 text-center">
    @csrf
    <button type="submit" class="auth-link inline-flex min-h-11 items-center underline" data-testid="change-password-logout">{{ __('Sign out') }}</button>
</form>
@endsection
