@extends('layouts.app')
@section('title', 'Enable Two-Factor Authentication')
@section('page-title', 'Enable 2FA')

@section('content')
<div class="row justify-content-center">
    <div class="col-lg-6 col-xl-5">

        <h5 class="fw-bold mb-1">Set Up Two-Factor Authentication</h5>
        <p class="text-muted small mb-4">Scan the QR code with your authenticator app, then enter the 6-digit code to confirm.</p>

        <div class="card shadow-sm border-0 mb-4">
            <div class="card-body">

                {{-- Step 1: QR code --}}
                <div class="mb-4">
                    <div class="fw-semibold small mb-2"><span class="badge bg-primary me-1">1</span> Scan with your authenticator app</div>
                    <div class="d-flex justify-content-center my-3">
                        {!! $qrSvg !!}
                    </div>
                    <div class="text-center">
                        <p class="text-muted small mb-1">Or enter this key manually:</p>
                        <code class="bg-light px-3 py-1 rounded d-inline-block" style="letter-spacing:2px;font-size:0.85rem">{{ $secret }}</code>
                    </div>
                </div>

                <hr>

                {{-- Step 2: Confirm --}}
                <div class="fw-semibold small mb-3"><span class="badge bg-primary me-1">2</span> Enter the 6-digit code from your app</div>

                @if($errors->any())
                <div class="alert alert-danger py-2 small mb-3">
                    <i class="bi bi-exclamation-triangle me-1"></i>{{ $errors->first() }}
                </div>
                @endif

                <form method="POST" action="{{ route('profile.2fa.confirm') }}">
                    @csrf
                    <div class="mb-3">
                        <input type="text" name="code" id="code"
                               class="form-control text-center font-monospace @error('code') is-invalid @enderror"
                               placeholder="000000"
                               inputmode="numeric"
                               autocomplete="one-time-code"
                               maxlength="6"
                               style="font-size:1.5rem;letter-spacing:0.5rem"
                               autofocus required>
                        @error('code')
                        <div class="invalid-feedback text-center">{{ $message }}</div>
                        @enderror
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
