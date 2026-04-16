@extends('layouts.app')
@section('title', 'Dashboard')
@section('page-title', 'Dashboard')

@section('content')
<p class="text-muted mb-4">Welcome back, <strong>{{ auth()->user()->name }}</strong>.</p>

@if(auth()->user()->isSuperAdmin())
<div class="row g-4 mb-4">
    <div class="col-md-4">
        <div class="card shadow-sm border-0">
            <div class="card-body d-flex align-items-center gap-3 p-4">
                <div class="bg-primary bg-opacity-10 rounded p-3">
                    <i class="bi bi-building fs-3 text-primary"></i>
                </div>
                <div>
                    <div class="fs-2 fw-bold">{{ $totalClubs }}</div>
                    <div class="text-muted small">Total Clubs</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card shadow-sm border-0">
            <div class="card-body d-flex align-items-center gap-3 p-4">
                <div class="bg-success bg-opacity-10 rounded p-3">
                    <i class="bi bi-people fs-3 text-success"></i>
                </div>
                <div>
                    <div class="fs-2 fw-bold">{{ $totalMembers }}</div>
                    <div class="text-muted small">Total Members</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card shadow-sm border-0">
            <div class="card-body d-flex align-items-center gap-3 p-4">
                <div class="bg-warning bg-opacity-10 rounded p-3">
                    <i class="bi bi-person-gear fs-3 text-warning"></i>
                </div>
                <div>
                    <div class="fs-2 fw-bold">{{ $totalAdmins }}</div>
                    <div class="text-muted small">Administrators</div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    <div class="col-md-6">
        <div class="card shadow-sm h-100">
            <div class="card-header fw-semibold">Quick Links</div>
            <div class="list-group list-group-flush">
                <a href="{{ route('admin.clubs.index') }}" class="list-group-item list-group-item-action d-flex align-items-center gap-2">
                    <i class="bi bi-building text-primary"></i> Manage Clubs
                </a>
                <a href="{{ route('admin.admins.index') }}" class="list-group-item list-group-item-action d-flex align-items-center gap-2">
                    <i class="bi bi-person-gear text-warning"></i> Manage Administrators
                </a>
            </div>
        </div>
    </div>
</div>

@else
<div class="row g-4">
    @foreach($clubs as $club)
    <div class="col-md-6 col-lg-4">
        <div class="card shadow-sm h-100">
            <div class="card-body text-center p-4">
                @if($club->logo)
                    <img src="{{ asset('storage/'.$club->logo) }}" alt="{{ $club->name }} logo" class="rounded-circle mb-3 border" width="70" height="70" style="object-fit:cover">
                @else
                    <div class="bg-secondary rounded-circle mx-auto mb-3 d-flex align-items-center justify-content-center" style="width:70px;height:70px">
                        <i class="bi bi-building fs-3 text-white"></i>
                    </div>
                @endif
                <h6 class="fw-bold">{{ $club->name }}</h6>
                <p class="text-muted small">{{ ucfirst($club->pivot->role) }} &middot; {{ \App\Models\FeeRate::jobLevelLabels()[$club->pivot->job_level] ?? $club->pivot->job_level }}</p>
                @if(auth()->user()->isAdmin())
                <a href="{{ route('admin.clubs.members.index', $club) }}" class="btn btn-sm btn-outline-primary mt-2">
                    <i class="bi bi-people me-1"></i>Manage
                </a>
                @endif
            </div>
        </div>
    </div>
    @endforeach
</div>
@endif
@endsection
