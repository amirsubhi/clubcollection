@extends('layouts.app')
@section('title', 'Edit Club')
@section('page-title', 'Edit Club')

@section('content')
<div class="row justify-content-center">
    <div class="col-lg-6">
        <div class="card shadow-sm">
            <div class="card-body p-4">
                <form action="{{ route('admin.clubs.update', $club) }}" method="POST" enctype="multipart/form-data">
                    @csrf @method('PUT')
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Club Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control @error('name') is-invalid @enderror"
                               value="{{ old('name', $club->name) }}">
                        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Club Email</label>
                        <input type="email" name="email" class="form-control @error('email') is-invalid @enderror"
                               value="{{ old('email', $club->email) }}">
                        @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Club Logo</label>
                        @if($club->logo)
                            <div class="mb-2">
                                <img src="{{ asset('storage/'.$club->logo) }}" height="60" class="rounded border">
                                <small class="text-muted ms-2">Current logo</small>
                            </div>
                        @endif
                        <input type="file" name="logo" class="form-control @error('logo') is-invalid @enderror"
                               accept="image/*">
                        <div class="form-text">Upload a new logo to replace existing. JPG, PNG or GIF. Max 2MB.</div>
                        @error('logo')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-4">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="is_active" value="1"
                                   id="isActive" {{ old('is_active', $club->is_active) ? 'checked' : '' }}>
                            <label class="form-check-label" for="isActive">Active</label>
                        </div>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-lg me-1"></i>Update Club
                        </button>
                        <a href="{{ route('admin.clubs.index') }}" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
