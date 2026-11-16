@extends('layouts.app')
@section('title', 'Enable Two-Factor Authentication')
@section('page-title', 'Enable 2FA')

@section('content')
<div class="row justify-content-center">
    <div class="col-lg-6 col-xl-5">

        <p class="text-muted mb-4">Scan the QR code with your authenticator app, then enter the 6-digit code to confirm.</p>

        <div class="card shadow-sm border-0 mb-4">
            <div class="card-body">

                {{-- Step 1: QR code --}}
                <div class="mb-4">
                    <h2 class="h6 fw-semibold mb-2"><span class="badge bg-primary me-1">1</span> Scan with your authenticator app</h2>
                    <div class="d-flex justify-content-center my-3">
                        {!! $qrSvg !!}
                    </div>
                    <div class="text-center">
                        <p class="text-muted small mb-1">Or enter this key manually:</p>
                        <code class="bg-light px-3 py-1 rounded d-inline-block" style="letter-spacing:2px;font-size:0.9375rem;overflow-wrap:anywhere">{{ $secret }}</code>
                    </div>
                </div>

                <hr>

                {{-- Step 2: Confirm --}}
                <h2 class="h6 fw-semibold mb-3"><span class="badge bg-primary me-1">2</span> <label for="code" class="m-0">Enter the 6-digit code from your app</label></h2>

                @if($errors->any())
                <div class="alert alert-danger py-2 small mb-3" role="alert" id="twofa-errors">
                    <i class="bi bi-exclamation-triangle me-1"></i>{{ $errors->first() }}
                </div>
                @endif

                <form method="POST" action="{{ route('profile.2fa.confirm') }}">
                    @csrf
                    <div class="mb-3">
                        <input type="text" name="code" id="code" @if($errors->any()) aria-invalid="true" aria-describedby="twofa-errors" @endif
                               class="form-control text-center font-monospace @error('code') is-invalid @enderror"
                               placeholder="000000"
                               inputmode="numeric"
                               autocomplete="one-time-code"
                               maxlength="6"
                               style="font-size:1.5rem;letter-spacing:0.5rem"
                               autofocus required>
                        
                    </div>
                    <div class="mb-3">
                        <label for="confirm2faPassword" class="form-label small fw-semibold">Your password</label>
                        <input type="password" id="confirm2faPassword" name="current_password" @error('current_password') aria-invalid="true" aria-describedby="twofa-errors" @enderror autocomplete="current-password" required
                               class="form-control @error('current_password') is-invalid @enderror">
                    </div>
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-shield-check me-1"></i>Confirm &amp; Enable 2FA
                    </button>
                </form>

                <div class="text-center mt-3">
                    <a href="{{ route('profile.security') }}" class="small text-muted">Cancel</a>
                </div>
            </div>
        </div>

    </div>
</div>
@endsection
