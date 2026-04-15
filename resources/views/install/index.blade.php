<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Install — {{ config('app.name', 'Club Portal') }}</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style nonce="{{ $cspNonce ?? '' }}">
        html, body { min-height: 100%; }
        body {
            background: linear-gradient(135deg, #1a1f36 0%, #0d6efd 100%);
            min-height: 100vh;
            display: flex;
            align-items: flex-start;
            justify-content: center;
            padding: 2rem 1rem;
        }
        .install-card {
            width: 100%;
            max-width: 680px;
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            overflow: hidden;
            margin: auto;
        }
        .install-header {
            background: linear-gradient(135deg, #0d6efd, #0a58ca);
            padding: 2rem;
            text-align: center;
            color: #fff;
        }
        .install-header .brand-icon {
            width: 60px; height: 60px;
            background: rgba(255,255,255,0.2);
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 1rem;
            font-size: 28px;
        }
        .install-header h4 { margin: 0; font-weight: 700; }
        .install-header p  { margin: 4px 0 0; opacity: 0.85; font-size: 14px; }
        .install-body { padding: 2rem; }
        .install-body .form-label { font-size: 0.85rem; font-weight: 600; color: #495057; }
        .install-body .form-control {
            border-radius: 8px;
            padding: 0.6rem 0.85rem;
            border-color: #dee2e6;
        }
        .install-body .form-control:focus {
            border-color: #0d6efd;
            box-shadow: 0 0 0 3px rgba(13,110,253,0.1);
        }
        .section-title {
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #6c757d;
            margin-bottom: 0.75rem;
        }
        .req-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.5rem 0;
            border-bottom: 1px solid #f1f3f5;
            font-size: 0.875rem;
        }
        .req-item:last-child { border-bottom: none; }
        .req-label { color: #495057; }
        .req-value { font-family: monospace; font-size: 0.8rem; }
    </style>
</head>
<body>
<div class="install-card">
    <div class="install-header">
        <div class="brand-icon"><i class="bi bi-gear-fill"></i></div>
        <h4>Club Portal Setup</h4>
        <p>Complete the form below to install the application</p>
    </div>

    <div class="install-body">

        @if(session('error'))
            <div class="alert alert-danger d-flex align-items-center gap-2 mb-4">
                <i class="bi bi-exclamation-triangle-fill"></i>
                {{ session('error') }}
            </div>
        @endif

        @if($errors->any())
            <div class="alert alert-danger mb-4">
                <ul class="mb-0 ps-3">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{-- ── System Requirements ─────────────────────────────── --}}
        <p class="section-title"><i class="bi bi-cpu me-1"></i>System Requirements</p>
        <div class="card border-0 bg-light rounded-3 mb-4 px-3">
            @foreach(['php', 'sqlite', 'storage', 'env'] as $key)
                <div class="req-item">
                    <span class="req-label">{{ $requirements[$key]['label'] }}</span>
                    <span class="d-flex align-items-center gap-2">
                        <span class="req-value text-muted">{{ $requirements[$key]['value'] }}</span>
                        @if($requirements[$key]['pass'])
                            <span class="badge bg-success-subtle text-success border border-success-subtle">
                                <i class="bi bi-check-lg"></i> Pass
                            </span>
                        @else
                            <span class="badge bg-danger-subtle text-danger border border-danger-subtle">
                                <i class="bi bi-x-lg"></i> Fail
                            </span>
                        @endif
                    </span>
                </div>
            @endforeach
        </div>

        @if(! $requirements['all_pass'])
            <div class="alert alert-warning d-flex align-items-center gap-2 mb-4">
                <i class="bi bi-exclamation-triangle-fill"></i>
                Please fix the failing requirements before continuing.
            </div>
        @endif

        <form method="POST" action="{{ route('install.process') }}">
            @csrf

            {{-- ── Application Settings ────────────────────────── --}}
            <p class="section-title mt-2"><i class="bi bi-sliders me-1"></i>Application Settings</p>

            <div class="mb-3">
                <label class="form-label">Application Name</label>
                <input type="text" name="app_name" class="form-control @error('app_name') is-invalid @enderror"
                       value="{{ old('app_name', 'Club Portal') }}" placeholder="e.g. Recreation Club Portal" required>
                @error('app_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="mb-4">
                <label class="form-label">Application URL</label>
                <input type="url" name="app_url" class="form-control @error('app_url') is-invalid @enderror"
                       value="{{ old('app_url', request()->getSchemeAndHttpHost()) }}" placeholder="https://portal.example.com" required>
                @error('app_url')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            {{-- ── Administrator Account ───────────────────────── --}}
            <p class="section-title"><i class="bi bi-person-fill-lock me-1"></i>Super Administrator Account</p>

            <div class="mb-3">
                <label class="form-label">Full Name</label>
                <input type="text" name="admin_name" class="form-control @error('admin_name') is-invalid @enderror"
                       value="{{ old('admin_name') }}" placeholder="e.g. Ahmad Razif" required>
                @error('admin_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="mb-3">
                <label class="form-label">Email Address</label>
                <input type="email" name="admin_email" class="form-control @error('admin_email') is-invalid @enderror"
                       value="{{ old('admin_email') }}" placeholder="admin@yourcompany.com" required>
                @error('admin_email')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="mb-3">
                <label class="form-label">Password</label>
                <input type="password" name="admin_password" class="form-control @error('admin_password') is-invalid @enderror"
                       placeholder="Minimum 8 characters" required>
                @error('admin_password')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="mb-4">
                <label class="form-label">Confirm Password</label>
                <input type="password" name="admin_password_confirmation" class="form-control"
                       placeholder="Repeat your password" required>
            </div>

            <button type="submit" class="btn btn-primary w-100 py-2 fw-semibold"
                    {{ ! $requirements['all_pass'] ? 'disabled' : '' }}>
                <i class="bi bi-rocket-takeoff me-2"></i>Install Application
            </button>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
