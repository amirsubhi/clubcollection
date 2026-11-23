<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Install — {{ config('app.name', 'Club Portal') }}</title>
    @if ($classicBundled = \App\Support\FrontendAssets::classicBundled())
        {{-- Bootstrap and Bootstrap Icons bundled by Vite; the pinned jsDelivr copies only when public/build is missing. --}}
        @vite(['resources/css/classic.css', 'resources/js/classic.ts'])
    @else
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.2/font/bootstrap-icons.min.css" integrity="sha384-nWN3KqaaVcbEsCjLSV+2jMjh62Iny/+mN3srM8TZeM8N6aj7eDl5wwYggjAJ4/sJ" crossorigin="anonymous">
    @endif
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
            padding: 0.5rem 0;
            border-bottom: 1px solid #f1f3f5;
            font-size: 0.875rem;
        }
        .req-item:last-child { border-bottom: none; }
        /* A failing check stands out from the passing ones (design critique): tinted, with a red edge. */
        .req-item.req-fail { background: #fff5f5; border-left: 3px solid #b02a37; margin: 0 -1rem; padding: 0.625rem calc(1rem - 3px); }
        .req-label { color: #495057; }
        .req-value { font-family: monospace; font-size: 0.8rem; }
    </style>
</head>
<body>
<div class="install-card">
    <div class="install-header">
        <div class="brand-icon"><i class="bi bi-gear-fill"></i></div>
        <h4>Club Portal Setup</h4>
        <p>{{ $requirements['all_pass'] ? 'Complete the form below to install the application' : 'Fix the checks marked Fail, then complete the form' }}</p>
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

        @unless($installerEnabled)
            <div class="alert alert-warning mb-4">
                <strong><i class="bi bi-lock-fill me-1"></i>The installer is disabled.</strong>
                Add a random <code>INSTALL_TOKEN</code> (at least 16 characters) to <code>.env</code>, or put it in
                <code>storage/app/.install-token</code>, then reload this page. The token is cleared once the install is done.
            </div>
        @endunless

        {{-- ── System Requirements ─────────────────────────────── --}}
        <p class="section-title"><i class="bi bi-cpu me-1"></i>System Requirements</p>
        @php
            $failing = count(array_filter($requirements['rows'], fn ($r) => ! $r['pass']));
        @endphp
        @if($failing > 0)
            {{-- Above the list (not under it), so it is the first thing seen. --}}
            <div class="alert alert-warning d-flex align-items-start gap-2 mb-3" role="status" data-testid="requirements-failing">
                <i class="bi bi-exclamation-triangle-fill mt-1"></i>
                <div>
                    <strong>{{ $failing === 1 ? '1 check needs fixing.' : $failing.' checks need fixing.' }}</strong>
                    Fix each row marked Fail (the red line under it says how), then reload this page. You can install once every row passes.
                </div>
            </div>
        @endif
        <div class="card border-0 bg-light rounded-3 mb-4 px-3">
            @foreach($requirements['rows'] as $req)
                <div class="req-item {{ $req['pass'] ? '' : 'req-fail' }}">
                    <div class="d-flex align-items-center justify-content-between gap-2">
                        <span class="req-label">{{ $req['label'] }}</span>
                        <span class="d-flex align-items-center gap-2">
                            <span class="req-value text-muted">{{ $req['value'] }}</span>
                            @if($req['pass'])
                                <span class="badge bg-success-subtle text-success-emphasis border border-success-subtle">
                                    <i class="bi bi-check-lg"></i> Pass
                                </span>
                            @else
                                <span class="badge bg-danger-subtle text-danger-emphasis border border-danger-subtle">
                                    <i class="bi bi-x-lg"></i> Fail
                                </span>
                            @endif
                        </span>
                    </div>
                    @unless($req['pass'])
                        <div class="req-fix text-danger-emphasis small mt-1">{{ $req['fix'] }}</div>
                    @endunless
                </div>
            @endforeach
        </div>

        @if($installerEnabled)
        <form method="POST" action="{{ route('install.process') }}">
            @csrf
            {{-- Dimmed and not fillable while a check fails, so nobody fills eight fields for a button that can't work. --}}
            <fieldset @disabled(! $requirements['all_pass'])>

            <p class="section-title mt-2"><i class="bi bi-key me-1"></i>Install token</p>
            <div class="mb-3">
                <label for="installToken" class="form-label">INSTALL_TOKEN from your .env</label>
                <input type="password" id="installToken" name="install_token" autocomplete="off" required
                       class="form-control @error('install_token') is-invalid @enderror">
            </div>

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
                <label for="adminPassword" class="form-label">Password</label>
                <input type="password" id="adminPassword" name="admin_password"
                       class="form-control @error('admin_password') is-invalid @enderror"
                       autocomplete="new-password" required minlength="12">
                <div class="form-text small">
                    Must be at least 12 characters with upper-case, lower-case, a number and a symbol, and not a password known from a data leak.
                </div>
                @error('admin_password')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="mb-4">
                <label for="adminPasswordConfirmation" class="form-label">Confirm Password</label>
                <input type="password" id="adminPasswordConfirmation" name="admin_password_confirmation"
                       class="form-control" autocomplete="new-password" required minlength="12">
            </div>

            </fieldset>

            @unless($requirements['all_pass'])
                <p class="small text-danger-emphasis mb-2" id="install-blocked" data-testid="install-blocked">
                    <i class="bi bi-lock-fill me-1"></i>Installing is blocked until every check above passes.
                </p>
            @endunless
            <button type="submit" class="btn btn-primary w-100 py-2 fw-semibold"
                    @unless($requirements['all_pass']) disabled aria-describedby="install-blocked" @endunless>
                <i class="bi bi-rocket-takeoff me-2"></i>Install Application
            </button>
        </form>
        @endif
    </div>
</div>

@unless ($classicBundled)
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js" integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI" crossorigin="anonymous"></script>
@endunless
</body>
</html>
