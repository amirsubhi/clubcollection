@extends('layouts.app')
@section('title', 'Add Member')
@section('page-title', $club->name . ' — Add Member')

@section('content')
<div class="row justify-content-center">
    <div class="col-lg-6">
        <div class="card shadow-sm">
            <div class="card-body p-4">
                <form action="{{ route('admin.members.store', $club) }}" method="POST">
                    @csrf
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Full Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control @error('name') is-invalid @enderror"
                               value="{{ old('name') }}">
                        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Email <span class="text-danger">*</span></label>
                        <input type="email" name="email" class="form-control @error('email') is-invalid @enderror"
                               value="{{ old('email') }}">
                        @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Job Level <span class="text-danger">*</span></label>
                        <select name="job_level" class="form-select @error('job_level') is-invalid @enderror">
                            <option value="">Select job level</option>
                            @foreach($jobLevels as $key => $label)
                                <option value="{{ $key }}" {{ old('job_level') == $key ? 'selected' : '' }}>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('job_level')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Club Role <span class="text-danger">*</span></label>
                        <select name="role" class="form-select @error('role') is-invalid @enderror">
                            <option value="member" {{ old('role') == 'member' ? 'selected' : '' }}>Member</option>
                            <option value="admin" {{ old('role') == 'admin' ? 'selected' : '' }}>Admin</option>
                        </select>
                        @error('role')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-4">
                        <label class="form-label fw-semibold">Date Joined <span class="text-danger">*</span></label>
                        <input type="date" name="joined_date" class="form-control @error('joined_date') is-invalid @enderror"
                               value="{{ old('joined_date', date('Y-m-d')) }}">
                        @error('joined_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="alert alert-info small">
                        <i class="bi bi-envelope me-1"></i>
                        A secure temporary password will be generated and sent to the member's email address.
                    </div>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-person-plus me-1"></i>Add Member
                        </button>
                        <a href="{{ route('admin.members.index', $club) }}" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
