@extends('layouts.auth')
@section('title', 'Set New Password')
@section('auth-subtitle', 'Choose a new password')

@section('content')
<form method="POST" action="{{ route('password.update') }}">
    @csrf
    <input type="hidden" name="token" value="{{ $token }}">

    <div class="mb-3">
        <label for="email" class="form-label">Email Address</label>
        <div class="input-icon-wrap">
            <i class="bi bi-envelope"></i>
            <input id="email" type="email"
                   class="form-control @error('email') is-invalid @enderror"
                   name="email" value="{{ $email ?? old('email') }}"
                   required autocomplete="email" autofocus>
            @error('email')
            <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>
    </div>

    <div class="mb-3">
        <label for="password" class="form-label">New Password</label>
        <div class="input-icon-wrap">
            <i class="bi bi-lock"></i>
            <input id="password" type="password"
                   class="form-control @error('password') is-invalid @enderror"
                   name="password" required autocomplete="new-password" placeholder="Min 8 characters">
            @error('password')
            <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>
    </div>

    <div class="mb-4">
        <label for="password-confirm" class="form-label">Confirm New Password</label>
        <div class="input-icon-wrap">
            <i class="bi bi-lock-fill"></i>
            <input id="password-confirm" type="password"
                   class="form-control"
                   name="password_confirmation" required autocomplete="new-password" placeholder="Repeat password">
        </div>
    </div>

    <button type="submit" class="btn btn-primary btn-auth">
        <i class="bi bi-check-lg me-2"></i>Reset Password
    </button>
</form>
@endsection
