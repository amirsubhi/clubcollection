@extends('layouts.auth')
@section('title', 'Reset Password')
@section('auth-subtitle', 'We\'ll send you a reset link')

@section('content')

@if (session('status'))
<div class="alert alert-success py-2 small mb-3">
    <i class="bi bi-check-circle me-1"></i>{{ session('status') }}
</div>
@endif

<form method="POST" action="{{ route('password.email') }}">
    @csrf

    <div class="mb-4">
        <label for="email" class="form-label">Email Address</label>
        <div class="input-icon-wrap">
            <i class="bi bi-envelope"></i>
            <input id="email" type="email"
                   class="form-control @error('email') is-invalid @enderror"
                   name="email" value="{{ old('email') }}"
                   required autocomplete="email" autofocus placeholder="you@example.com">
            @error('email')
            <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>
    </div>

    <button type="submit" class="btn btn-primary btn-auth mb-3">
        <i class="bi bi-send me-2"></i>Send Reset Link
    </button>

    <div class="text-center">
        <a href="{{ route('login') }}" class="small text-muted text-decoration-none">
            <i class="bi bi-arrow-left me-1"></i>Back to Sign In
        </a>
    </div>
</form>
@endsection
