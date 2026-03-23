<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Member Portal')</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { background: #f0f2f5; font-size: 0.9rem; }

        /* ── Topnav ── */
        .member-nav {
            background: #16213e;
            padding: 0 1.5rem;
            height: 58px;
            display: flex; align-items: center; justify-content: space-between;
            position: sticky; top: 0; z-index: 100;
        }
        .member-nav .brand {
            display: flex; align-items: center; gap: 10px;
            color: #fff; text-decoration: none;
        }
        .member-nav .brand-icon {
            width: 34px; height: 34px;
            background: #0d6efd; border-radius: 9px;
            display: flex; align-items: center; justify-content: center;
            font-size: 17px; color: #fff;
        }
        .member-nav .brand-text { font-weight: 700; font-size: 0.95rem; }
        .member-nav .brand-sub { font-size: 0.7rem; color: rgba(255,255,255,0.45); display: block; line-height: 1; }
        .member-nav .nav-actions { display: flex; align-items: center; gap: 10px; }
        .member-nav .user-pill {
            display: flex; align-items: center; gap: 8px;
            background: rgba(255,255,255,0.08);
            border-radius: 30px;
            padding: 4px 12px 4px 4px;
        }
        .user-initials {
            width: 28px; height: 28px;
            background: #0d6efd; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 11px; font-weight: 700; color: #fff;
        }
        .member-nav .user-pill .uname { color: #fff; font-size: 0.8rem; font-weight: 600; }

        /* ── Subtitle bar ── */
        .page-subtitle-bar {
            background: #fff;
            border-bottom: 1px solid #e9ecef;
            padding: 0.55rem 1.5rem;
            display: flex; align-items: center; justify-content: space-between;
        }
        .page-subtitle-bar .page-heading { font-size: 0.85rem; font-weight: 600; color: #212529; margin: 0; }

        /* ── Content ── */
        .member-main { max-width: 920px; margin: 1.75rem auto; padding: 0 1rem 3rem; }
        .alert { font-size: 0.875rem; }
    </style>
    @stack('styles')
</head>
<body>

<nav class="member-nav">
    <a href="{{ route('member.dashboard') }}" class="brand">
        <div class="brand-icon"><i class="bi bi-building-fill"></i></div>
        <div>
            <span class="brand-text">{{ config('app.name', 'Club Portal') }}</span>
            <span class="brand-sub">Member Portal</span>
        </div>
    </a>
    <div class="nav-actions">
        @if(auth()->user()->isAdmin())
        <a href="{{ route('home') }}" class="btn btn-sm btn-outline-light d-none d-sm-inline-flex align-items-center gap-1">
            <i class="bi bi-shield-check"></i><span>Admin</span>
        </a>
        @endif
        <div class="user-pill">
            <div class="user-initials">{{ strtoupper(substr(auth()->user()->name, 0, 2)) }}</div>
            <span class="uname d-none d-sm-inline">{{ auth()->user()->name }}</span>
        </div>
        <form action="{{ route('logout') }}" method="POST" class="m-0">
            @csrf
            <button type="submit" class="btn btn-sm btn-outline-light" title="Logout">
                <i class="bi bi-box-arrow-right"></i>
            </button>
        </form>
    </div>
</nav>

<div class="page-subtitle-bar">
    <h6 class="page-heading">@yield('page-heading', 'My Dashboard')</h6>
    @hasSection('breadcrumb')
    @yield('breadcrumb')
    @endhasSection
</div>

<div class="member-main">
    @if(session('success'))
    <div class="alert alert-success alert-dismissible fade show py-2">
        <i class="bi bi-check-circle me-2"></i>{{ session('success') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    @endif
    @if(session('error'))
    <div class="alert alert-danger alert-dismissible fade show py-2">
        <i class="bi bi-exclamation-triangle me-2"></i>{{ session('error') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    @endif
    @if($errors->any())
    <div class="alert alert-danger alert-dismissible fade show py-2">
        <i class="bi bi-exclamation-triangle me-2"></i>{{ $errors->first() }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    @endif

    @yield('content')
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
@stack('scripts')
</body>
</html>
