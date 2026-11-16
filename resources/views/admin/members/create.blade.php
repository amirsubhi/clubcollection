@extends('layouts.app')
@section('title', 'Add Member')
@section('page-title', 'Add Member')
@section('page-club', $club->name)

@section('content')
<div class="row justify-content-center">
    <div class="col-lg-6">
        <div class="card shadow-sm">
            <div class="card-body p-4">
                <form action="{{ route('admin.clubs.members.store', $club) }}" method="POST">
                    @csrf
                    <div class="mb-3">
                        <label for="f-name" class="form-label fw-semibold">Full Name <span class="text-danger">*</span></label>
                        <input id="f-name" aria-describedby="f-name-help @error('name') f-name-error @enderror" @error('name') aria-invalid="true" @enderror type="text" name="name" class="form-control @error('name') is-invalid @enderror"
                               value="{{ old('name') }}">
                        <div id="f-name-help" class="form-text">Enter the member's full name as per official records.</div>
                        @error('name')<div id="f-name-error" role="alert" class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-3">
                        <label for="f-email" class="form-label fw-semibold">Email <span class="text-danger">*</span></label>
                        <input id="f-email" aria-describedby="f-email-help @error('email') f-email-error @enderror" @error('email') aria-invalid="true" @enderror type="email" name="email" class="form-control @error('email') is-invalid @enderror"
                               value="{{ old('email') }}">
                        <div id="f-email-help" class="form-text">Used for login and system notifications. Must be unique across all users.</div>
                        @error('email')<div id="f-email-error" role="alert" class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-3">
                        <label for="f-job-level" class="form-label fw-semibold">Job Level <span class="text-danger">*</span></label>
                        <select id="f-job-level" aria-describedby="f-job-level-help @error('job_level') f-job-level-error @enderror" @error('job_level') aria-invalid="true" @enderror name="job_level" class="form-select @error('job_level') is-invalid @enderror">
                            <option value="">Select job level</option>
                            @foreach($jobLevels as $key => $label)
                                <option value="{{ $key }}" {{ old('job_level') == $key ? 'selected' : '' }}>{{ $label }}</option>
                            @endforeach
                        </select>
                        <div id="f-job-level-help" class="form-text">Determines the applicable monthly fee rate for this member.</div>
                        @error('job_level')<div id="f-job-level-error" role="alert" class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    {{-- Only the super admin grants the club-admin role (S1). --}}
                    @if(auth()->user()->isSuperAdmin())
                    <div class="mb-3">
                        <label for="f-role" class="form-label fw-semibold">Club Role <span class="text-danger">*</span></label>
                        <select id="f-role" aria-describedby="f-role-help @error('role') f-role-error @enderror" @error('role') aria-invalid="true" @enderror name="role" class="form-select @error('role') is-invalid @enderror">
                            <option value="member" {{ old('role') == 'member' ? 'selected' : '' }}>Member</option>
                            <option value="admin" {{ old('role') == 'admin' ? 'selected' : '' }}>Admin</option>
                        </select>
                        <div id="f-role-help" class="form-text">Member = standard access. Admin = can manage this club's payments and members.</div>
                        @error('role')<div id="f-role-error" role="alert" class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    @else
                    <p class="small text-muted mb-3"><i class="bi bi-shield-lock me-1"></i>New people join as members. Only the super admin can make someone a club admin.</p>
                    @endif
                    <div class="mb-4">
                        <label for="f-joined-date" class="form-label fw-semibold">Date Joined <span class="text-danger">*</span></label>
                        <input id="f-joined-date" aria-describedby="f-joined-date-help @error('joined_date') f-joined-date-error @enderror" @error('joined_date') aria-invalid="true" @enderror type="date" name="joined_date" class="form-control @error('joined_date') is-invalid @enderror"
                               value="{{ old('joined_date', date('Y-m-d')) }}">
                        <div id="f-joined-date-help" class="form-text">The official date this person joined the club. Defaults to today.</div>
                        @error('joined_date')<div id="f-joined-date-error" role="alert" class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="alert alert-info small">
                        <i class="bi bi-envelope me-1"></i>
                        A secure temporary password will be generated and sent to the member's email address.
                    </div>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-person-plus me-1"></i>Add Member
                        </button>
                        <a href="{{ route('admin.clubs.members.index', $club) }}" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
