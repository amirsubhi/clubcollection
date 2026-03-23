<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', config('app.name', 'Club Portal'))</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { background-color: #f8f9fa; }
        .sidebar { min-height: 100vh; background: #212529; width: 250px; position: fixed; top: 0; left: 0; z-index: 100; overflow-y: auto; }
        .sidebar .nav-link { color: #adb5bd; padding: 0.55rem 1.25rem; font-size: 0.9rem; }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { color: #fff; background: rgba(255,255,255,0.08); border-radius: 4px; }
        .sidebar .nav-section { color: #6c757d; font-size: 0.68rem; text-transform: uppercase; letter-spacing: 1px; padding: 1rem 1.25rem 0.25rem; }
        .sidebar-brand { padding: 1.1rem 1.25rem; border-bottom: 1px solid #343a40; }
        .main-content { margin-left: 250px; padding: 1.5rem 2rem; }
        .topbar { background: #fff; border-bottom: 1px solid #dee2e6; padding: 0.6rem 2rem; margin-left: 250px; position: sticky; top: 0; z-index: 99; }
        .club-nav-item { font-size: 0.82rem; }
    </style>
    @stack('styles')
</head>
<body>

<div class="sidebar d-flex flex-column">
    <div class="sidebar-brand">
        <span class="text-white fw-bold"><i class="bi bi-building-fill me-2 text-primary"></i>Club Portal</span>
    </div>
    <nav class="flex-grow-1 py-2">
        <div class="nav-section">General</div>
        <a href="{{ route('home') }}" class="nav-link {{ request()->routeIs('home') ? 'active' : '' }}">
            <i class="bi bi-speedometer2 me-2"></i>Dashboard
        </a>

        @if(auth()->user()->isSuperAdmin())
        <div class="nav-section mt-2">Super Admin</div>
        <a href="{{ route('admin.clubs.index') }}" class="nav-link {{ request()->routeIs('admin.clubs.*') ? 'active' : '' }}">
            <i class="bi bi-building me-2"></i>Clubs
        </a>
        <a href="{{ route('admin.admins.index') }}" class="nav-link {{ request()->routeIs('admin.admins.*') ? 'active' : '' }}">
            <i class="bi bi-person-gear me-2"></i>Administrators
        </a>
        @endif

        @if(auth()->user()->isAdmin())
        @php
            $adminClubs = auth()->user()->isSuperAdmin()
                ? \App\Models\Club::where('is_active', true)->get()
                : auth()->user()->clubs()->wherePivot('role', 'admin')->where('clubs.is_active', true)->get();
        @endphp
        @foreach($adminClubs as $club)
        <div class="nav-section mt-2">{{ $club->name }}</div>
        <a href="{{ route('admin.clubs.dashboard', $club) }}" class="nav-link club-nav-item {{ request()->routeIs('admin.clubs.dashboard') && request()->route('club')?->id == $club->id ? 'active' : '' }}">
            <i class="bi bi-graph-up me-2"></i>Dashboard
        </a>
        <a href="{{ route('admin.payments.index', $club) }}" class="nav-link club-nav-item {{ request()->routeIs('admin.payments.*') && request()->route('club')?->id == $club->id ? 'active' : '' }}">
            <i class="bi bi-wallet2 me-2"></i>Payments
        </a>
        <a href="{{ route('admin.expenses.index', $club) }}" class="nav-link club-nav-item {{ request()->routeIs('admin.expenses.*') && request()->route('club')?->id == $club->id ? 'active' : '' }}">
            <i class="bi bi-receipt me-2"></i>Expenses
        </a>
        <a href="{{ route('admin.discounts.index', $club) }}" class="nav-link club-nav-item {{ request()->routeIs('admin.discounts.*') && request()->route('club')?->id == $club->id ? 'active' : '' }}">
            <i class="bi bi-tag me-2"></i>Discounts
        </a>
        <a href="{{ route('admin.members.index', $club) }}" class="nav-link club-nav-item {{ request()->routeIs('admin.members.*') && request()->route('club')?->id == $club->id ? 'active' : '' }}">
            <i class="bi bi-people me-2"></i>Members
        </a>
        <a href="{{ route('admin.fee-rates.index', $club) }}" class="nav-link club-nav-item {{ request()->routeIs('admin.fee-rates.*') && request()->route('club')?->id == $club->id ? 'active' : '' }}">
            <i class="bi bi-cash-stack me-2"></i>Fee Rates
        </a>
        @endforeach
        @endif
    </nav>
    <div class="p-3 border-top border-secondary">
        <small class="text-light d-block">{{ auth()->user()->name }}</small>
        <small class="text-secondary">{{ ucfirst(str_replace('_', ' ', auth()->user()->role)) }}</small>
    </div>
</div>

<div class="topbar d-flex align-items-center justify-content-between">
    <h6 class="mb-0 fw-semibold text-dark">@yield('page-title', 'Dashboard')</h6>
    <div class="d-flex align-items-center gap-3">
        <form action="{{ route('logout') }}" method="POST" class="m-0">
            @csrf
            <button type="submit" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-box-arrow-right me-1"></i>Logout
            </button>
        </form>
    </div>
</div>

<div class="main-content">
    @if(session('success'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle me-2"></i>{{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle me-2"></i>{{ session('error') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    @yield('content')
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
@stack('scripts')
</body>
</html>
