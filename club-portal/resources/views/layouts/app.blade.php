<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', config('app.name', 'Club Portal'))</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style nonce="{{ $cspNonce }}">
        :root { --sidebar-w: 250px; }
        body { background: #f0f2f5; font-size: 0.9rem; }

        /* ── Sidebar ── */
        .sidebar {
            width: var(--sidebar-w); min-height: 100vh;
            background: #16213e;
            position: fixed; top: 0; left: 0; z-index: 1040;
            display: flex; flex-direction: column;
            overflow-y: auto; overflow-x: hidden;
            transition: transform 0.25s ease;
        }
        .sidebar-brand {
            padding: 1.1rem 1.25rem;
            border-bottom: 1px solid rgba(255,255,255,0.07);
            display: flex; align-items: center; gap: 10px;
        }
        .sidebar-brand .brand-icon {
            width: 36px; height: 36px;
            background: #0d6efd;
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-size: 18px; color: #fff; flex-shrink: 0;
        }
        .sidebar-brand .brand-text { color: #fff; font-weight: 700; font-size: 1rem; }
        .sidebar nav { flex-grow: 1; padding: 0.5rem 0.75rem; }
        .sidebar .nav-section {
            color: rgba(255,255,255,0.3);
            font-size: 0.67rem; text-transform: uppercase;
            letter-spacing: 1.2px; padding: 1rem 0.5rem 0.3rem;
        }
        .sidebar .nav-link {
            color: rgba(255,255,255,0.6);
            padding: 0.5rem 0.75rem;
            border-radius: 8px;
            font-size: 0.875rem;
            display: flex; align-items: center; gap: 8px;
            transition: background 0.15s, color 0.15s;
            margin-bottom: 2px;
        }
        .sidebar .nav-link:hover { color: #fff; background: rgba(255,255,255,0.08); }
        .sidebar .nav-link.active { color: #fff; background: #0d6efd; }
        .sidebar .nav-link .bi { font-size: 15px; flex-shrink: 0; }
        .sidebar-footer {
            padding: 0.85rem 1rem;
            border-top: 1px solid rgba(255,255,255,0.07);
        }
        .user-chip {
            display: flex; align-items: center; gap: 10px;
        }
        .user-avatar {
            width: 34px; height: 34px;
            background: #0d6efd;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            color: #fff; font-weight: 700; font-size: 13px;
            flex-shrink: 0;
        }
        .user-chip .name { color: #fff; font-size: 0.82rem; font-weight: 600; line-height: 1.2; }
        .user-chip .role { color: rgba(255,255,255,0.4); font-size: 0.72rem; }

        /* ── Topbar ── */
        .topbar {
            margin-left: var(--sidebar-w);
            background: #fff;
            border-bottom: 1px solid #e9ecef;
            padding: 0 1.75rem;
            height: 56px;
            position: sticky; top: 0; z-index: 1030;
            display: flex; align-items: center; justify-content: space-between;
            transition: margin-left 0.25s ease;
        }
        .topbar .page-title { font-weight: 600; font-size: 1rem; color: #212529; margin: 0; }
        .topbar .topbar-right { display: flex; align-items: center; gap: 12px; }

        /* ── Main content ── */
        .main-content {
            margin-left: var(--sidebar-w);
            padding: 1.5rem 1.75rem 2rem;
            min-height: calc(100vh - 56px);
            transition: margin-left 0.25s ease;
        }

        /* ── Mobile ── */
        .sidebar-overlay {
            display: none;
            position: fixed; inset: 0;
            background: rgba(0,0,0,0.45);
            z-index: 1039;
        }
        @media (max-width: 991.98px) {
            .sidebar { transform: translateX(-100%); }
            .topbar, .main-content { margin-left: 0 !important; }
            .sidebar.show { transform: translateX(0); }
            .sidebar-overlay.show { display: block; }
            .sidebar-toggle { display: flex !important; }
        }
        .sidebar-toggle {
            display: none;
            align-items: center; justify-content: center;
            width: 36px; height: 36px;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            background: #fff;
            cursor: pointer;
            color: #495057;
            font-size: 18px;
        }
    </style>
    @stack('styles')
</head>
<body>

{{-- Mobile overlay --}}
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<div class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <div class="brand-icon"><i class="bi bi-building-fill"></i></div>
        <span class="brand-text">{{ config('app.name', 'Club Portal') }}</span>
    </div>

    <nav>
        <div class="nav-section">General</div>
        <a href="{{ route('home') }}" class="nav-link {{ request()->routeIs('home') ? 'active' : '' }}">
            <i class="bi bi-speedometer2"></i> Dashboard
        </a>
        <a href="{{ route('member.dashboard') }}" class="nav-link {{ request()->routeIs('member.*') ? 'active' : '' }}">
            <i class="bi bi-person-badge"></i> My Member Portal
        </a>

        @if(auth()->user()->isSuperAdmin())
        <div class="nav-section">Super Admin</div>
        <a href="{{ route('admin.clubs.index') }}" class="nav-link {{ request()->routeIs('admin.clubs.index') || request()->routeIs('admin.clubs.create') || request()->routeIs('admin.clubs.edit') ? 'active' : '' }}">
            <i class="bi bi-building"></i> Clubs
        </a>
        <a href="{{ route('admin.admins.index') }}" class="nav-link {{ request()->routeIs('admin.admins.*') ? 'active' : '' }}">
            <i class="bi bi-person-gear"></i> Administrators
        </a>
        <a href="{{ route('admin.statistics') }}" class="nav-link {{ request()->routeIs('admin.statistics') ? 'active' : '' }}">
            <i class="bi bi-bar-chart-line"></i> Statistics
        </a>
        <a href="{{ route('admin.audit-logs.index') }}" class="nav-link {{ request()->routeIs('admin.audit-logs.*') ? 'active' : '' }}">
            <i class="bi bi-journal-check"></i> Audit Log
        </a>
        @endif

        @if(auth()->user()->isAdmin())
        @php
            $adminClubs = auth()->user()->isSuperAdmin()
                ? \App\Models\Club::where('is_active', true)->get()
                : auth()->user()->clubs()->wherePivot('role', 'admin')->where('clubs.is_active', true)->get();
        @endphp
        @foreach($adminClubs as $club)
        <div class="nav-section">{{ $club->name }}</div>
        <a href="{{ route('admin.clubs.dashboard', $club) }}" class="nav-link {{ request()->routeIs('admin.clubs.dashboard') && request()->route('club')?->id == $club->id ? 'active' : '' }}">
            <i class="bi bi-graph-up"></i> Dashboard
        </a>
        <a href="{{ route('admin.payments.index', $club) }}" class="nav-link {{ request()->routeIs('admin.payments.*') && request()->route('club')?->id == $club->id ? 'active' : '' }}">
            <i class="bi bi-wallet2"></i> Payments
        </a>
        <a href="{{ route('admin.expenses.index', $club) }}" class="nav-link {{ request()->routeIs('admin.expenses.*') && request()->route('club')?->id == $club->id ? 'active' : '' }}">
            <i class="bi bi-receipt"></i> Expenses
        </a>
        <a href="{{ route('admin.discounts.index', $club) }}" class="nav-link {{ request()->routeIs('admin.discounts.*') && request()->route('club')?->id == $club->id ? 'active' : '' }}">
            <i class="bi bi-tag"></i> Discounts
        </a>
        <a href="{{ route('admin.members.index', $club) }}" class="nav-link {{ request()->routeIs('admin.members.*') && request()->route('club')?->id == $club->id ? 'active' : '' }}">
            <i class="bi bi-people"></i> Members
        </a>
        <a href="{{ route('admin.fee-rates.index', $club) }}" class="nav-link {{ request()->routeIs('admin.fee-rates.*') && request()->route('club')?->id == $club->id ? 'active' : '' }}">
            <i class="bi bi-cash-stack"></i> Fee Rates
        </a>
        <a href="{{ route('admin.clubs.ledger', $club) }}" class="nav-link {{ request()->routeIs('admin.clubs.ledger*') && request()->route('club')?->id == $club->id ? 'active' : '' }}">
            <i class="bi bi-journal-bookmark"></i> Ledger
        </a>
        <a href="{{ route('admin.clubs.audit-logs', $club) }}" class="nav-link {{ request()->routeIs('admin.clubs.audit-logs') && request()->route('club')?->id == $club->id ? 'active' : '' }}">
            <i class="bi bi-journal-check"></i> Audit Log
        </a>
        @endforeach
        @endif
    </nav>

    <div class="sidebar-footer">
        <div class="user-chip">
            <div class="user-avatar">{{ strtoupper(substr(auth()->user()->name, 0, 2)) }}</div>
            <div>
                <div class="name">{{ auth()->user()->name }}</div>
                <div class="role">{{ ucfirst(str_replace('_', ' ', auth()->user()->role)) }}</div>
            </div>
        </div>
    </div>
</div>

<div class="topbar">
    <div class="d-flex align-items-center gap-3">
        <button class="sidebar-toggle" id="sidebarToggle" aria-label="Toggle sidebar">
            <i class="bi bi-list"></i>
        </button>
        <h6 class="page-title">@yield('page-title', 'Dashboard')</h6>
    </div>
    <div class="topbar-right">
        @stack('topbar-actions')
        <div class="dropdown">
            <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                <i class="bi bi-person-circle me-1"></i><span class="d-none d-sm-inline">{{ auth()->user()->name }}</span>
            </button>
            <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                <li>
                    <a class="dropdown-item small" href="{{ route('profile.security') }}">
                        <i class="bi bi-shield-lock me-2"></i>Security &amp; 2FA
                        @if(auth()->user()->hasEnabledTwoFactor())
                        <span class="badge bg-success ms-1" style="font-size:0.65rem">ON</span>
                        @endif
                    </a>
                </li>
                <li><hr class="dropdown-divider my-1"></li>
                <li>
                    <form action="{{ route('logout') }}" method="POST" class="m-0">
                        @csrf
                        <button type="submit" class="dropdown-item small text-danger">
                            <i class="bi bi-box-arrow-right me-2"></i>Logout
                        </button>
                    </form>
                </li>
            </ul>
        </div>
    </div>
</div>

<div class="main-content">
    @if(session('success'))
    <div class="alert alert-success alert-dismissible fade show py-2" role="alert">
        <i class="bi bi-check-circle me-2"></i>{{ session('success') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    @endif
    @if(session('error'))
    <div class="alert alert-danger alert-dismissible fade show py-2" role="alert">
        <i class="bi bi-exclamation-triangle me-2"></i>{{ session('error') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    @endif

    @yield('content')

    <footer class="mt-5 pt-4 border-top" style="font-size:0.75rem;color:#adb5bd;line-height:1.7">
        <div class="d-flex flex-wrap align-items-start justify-content-between gap-3">
            <div style="max-width:520px">
                <p class="mb-1">
                    <i class="bi bi-shield-lock me-1"></i>
                    <strong>Confidentiality Notice:</strong>
                    The information contained in this system is confidential and intended solely for
                    authorized personnel. Unauthorized access, disclosure, copying, or distribution
                    of any data from this system is strictly prohibited and may be subject to
                    disciplinary or legal action.
                </p>
                <p class="mb-0">
                    All user actions, financial records, and system events are logged and may be
                    audited. By using this system you acknowledge and accept these terms.
                </p>
            </div>
            <div class="text-end" style="white-space:nowrap">
                <div>&copy; {{ date('Y') }} {{ config('app.name', 'Club Portal') }}</div>
                <div class="text-secondary">All rights reserved</div>
            </div>
        </div>
    </footer>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script nonce="{{ $cspNonce }}">
const sidebar  = document.getElementById('sidebar');
const overlay  = document.getElementById('sidebarOverlay');
const toggle   = document.getElementById('sidebarToggle');

function openSidebar()  { sidebar.classList.add('show'); overlay.classList.add('show'); }
function closeSidebar() { sidebar.classList.remove('show'); overlay.classList.remove('show'); }

toggle?.addEventListener('click', () => sidebar.classList.contains('show') ? closeSidebar() : openSidebar());
overlay.addEventListener('click', closeSidebar);
</script>
@stack('scripts')
</body>
</html>
