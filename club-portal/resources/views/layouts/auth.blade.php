<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', config('app.name', 'Club Portal'))</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        html, body { height: 100%; }
        body {
            background: linear-gradient(135deg, #1a1f36 0%, #0d6efd 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 1rem;
        }
        .auth-card {
            width: 100%;
            max-width: 420px;
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            overflow: hidden;
        }
        .auth-header {
            background: linear-gradient(135deg, #0d6efd, #0a58ca);
            padding: 2rem;
            text-align: center;
            color: #fff;
        }
        .auth-header .brand-icon {
            width: 60px; height: 60px;
            background: rgba(255,255,255,0.2);
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 1rem;
            font-size: 28px;
        }
        .auth-header h4 { margin: 0; font-weight: 700; }
        .auth-header p { margin: 4px 0 0; opacity: 0.85; font-size: 14px; }
        .auth-body { padding: 2rem; }
        .auth-body .form-label { font-size: 0.85rem; font-weight: 600; color: #495057; }
        .auth-body .form-control {
            border-radius: 8px;
            padding: 0.6rem 0.85rem;
            border-color: #dee2e6;
        }
        .auth-body .form-control:focus {
            border-color: #0d6efd;
            box-shadow: 0 0 0 3px rgba(13,110,253,0.1);
        }
        .btn-auth {
            width: 100%;
            padding: 0.7rem;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.95rem;
        }
        .input-icon-wrap { position: relative; }
        .input-icon-wrap .bi {
            position: absolute; left: 12px; top: 50%; transform: translateY(-50%);
            color: #adb5bd; font-size: 16px; pointer-events: none;
        }
        .input-icon-wrap .form-control { padding-left: 2.4rem; }
        .auth-disclaimer {
            max-width: 420px;
            margin: 1.25rem auto 0;
            text-align: center;
            color: rgba(255,255,255,0.55);
            font-size: 0.72rem;
            line-height: 1.6;
            padding: 0 1rem;
        }
        .auth-disclaimer a { color: rgba(255,255,255,0.7); text-decoration: underline; }
    </style>
</head>
<body>
    <div class="auth-card">
        <div class="auth-header">
            <div class="brand-icon"><i class="bi bi-building-fill"></i></div>
            <h4>{{ config('app.name', 'Club Portal') }}</h4>
            <p>@yield('auth-subtitle', 'Member Management System')</p>
        </div>
        <div class="auth-body">
            @yield('content')
        </div>
    </div>

    <div class="auth-disclaimer">
        <p>
            <strong>Authorized Users Only.</strong>
            This system is intended solely for registered members and authorized personnel of
            {{ config('app.name', 'Club Portal') }}. Unauthorized access or misuse is strictly
            prohibited and may be subject to legal action.
        </p>
        <p>
            All login attempts and activities on this platform are logged and monitored.
            By signing in, you agree to the terms of use and confidentiality obligations of your organization.
        </p>
        <p>&copy; {{ date('Y') }} {{ config('app.name', 'Club Portal') }}. All rights reserved.</p>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
