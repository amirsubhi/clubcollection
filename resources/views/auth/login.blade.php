@extends('layouts.auth')
@section('title', 'Sign In')
@section('auth-subtitle', 'Sign in to your account')

@section('content')
<form method="POST" action="{{ route('login') }}">
    @csrf

    @if($errors->any())
    <div class="alert alert-danger py-2 small mb-3">
        <i class="bi bi-exclamation-triangle me-1"></i>{{ $errors->first() }}
    </div>
    @endif

    <div class="mb-3">
        <label for="email" class="form-label">Email Address</label>
        <div class="input-icon-wrap">
            <i class="bi bi-envelope"></i>
            <input id="email" type="email"
                   class="form-control @error('email') is-invalid @enderror"
                   name="email" value="{{ old('email') }}"
                   required autocomplete="email" autofocus placeholder="you@example.com">
        </div>
    </div>

    <div class="mb-3">
        <label for="password" class="form-label">Password</label>
        <div class="input-icon-wrap">
            <i class="bi bi-lock"></i>
            <input id="password" type="password"
                   class="form-control @error('password') is-invalid @enderror"
                   name="password" required autocomplete="current-password" placeholder="••••••••">
        </div>
    </div>

    <div class="d-flex align-items-center justify-content-between mb-4">
        <div class="form-check mb-0">
            <input class="form-check-input" type="checkbox" name="remember" id="remember" {{ old('remember') ? 'checked' : '' }}>
            <label class="form-check-label small" for="remember">Remember me</label>
        </div>
        @if (Route::has('password.request'))
        <a href="{{ route('password.request') }}" class="small text-primary text-decoration-none">
            Forgot password?
        </a>
        @endif
    </div>

    <button type="submit" class="btn btn-primary btn-auth">
        <i class="bi bi-box-arrow-in-right me-2"></i>Sign In
    </button>
</form>
@endsection
