@extends('layouts.app')
@section('title', 'Edit Member')
@section('page-title', $club->name . ' — Edit Member')

@section('content')
<div class="row justify-content-center">
    <div class="col-lg-6">
        <div class="card shadow-sm">
            <div class="card-body p-4">
                <div class="mb-4 p-3 bg-light rounded">
                    <div class="fw-semibold">{{ $member->name }}</div>
                    <div class="text-muted small">{{ $member->email }}</div>
                </div>
                <form action="{{ route('admin.members.update', [$club, $member]) }}" method="POST">
                    @csrf @method('PUT')
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Job Level <span class="text-danger">*</span></label>
                        <select name="job_level" class="form-select @error('job_level') is-invalid @enderror">
                            @foreach($jobLevels as $key => $label)
                                <option value="{{ $key }}" {{ old('job_level', $pivot?->job_level) == $key ? 'selected' : '' }}>{{ $label }}</option>
                            @endforeach
                        </select>
                        <div class="form-text">Changing this will apply the new fee rate to future payment records.</div>
                        @error('job_level')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Club Role <span class="text-danger">*</span></label>
                        <select name="role" class="form-select @error('role') is-invalid @enderror">
                            <option value="member" {{ old('role', $pivot?->role) == 'member' ? 'selected' : '' }}>Member</option>
                            <option value="admin" {{ old('role', $pivot?->role) == 'admin' ? 'selected' : '' }}>Admin</option>
                        </select>
                        <div class="form-text">Admin role grants ability to manage payments, expenses, and members for this club.</div>
                        @error('role')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Date Joined <span class="text-danger">*</span></label>
                        <input type="date" name="joined_date" class="form-control @error('joined_date') is-invalid @enderror"
                               value="{{ old('joined_date', $pivot?->joined_date) }}">
                        <div class="form-text">The official membership start date for this club.</div>
                        @error('joined_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-4">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="is_active" value="1"
                                   id="isActive" {{ old('is_active', $pivot?->is_active) ? 'checked' : '' }}>
                            <label class="form-check-label" for="isActive">Active in this club</label>
                        </div>
                        <div class="form-text mt-1">Inactive members cannot log in to the member portal.</div>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-lg me-1"></i>Update Member
                        </button>
                        <a href="{{ route('admin.clubs.members.index', $club) }}" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
