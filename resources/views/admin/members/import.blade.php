@extends('layouts.app')
@section('title', 'Import Members — ' . $club->name)
@section('page-title', $club->name . ' — Import Members')

@section('content')
<div class="row justify-content-center">
    <div class="col-lg-8">

        {{-- Results after import --}}
        @if(session()->has('import_imported'))
            <div class="alert alert-success d-flex align-items-center gap-2 mb-4">
                <i class="bi bi-check-circle-fill fs-5"></i>
                <div>
                    <strong>{{ session('import_imported') }} member(s) imported successfully.</strong>
                    Temporary passwords have been sent to each new member's email address.
                </div>
            </div>
        @endif

        @if(session('import_errors') && count(session('import_errors')) > 0)
            <div class="card border-danger-subtle mb-4 shadow-sm">
                <div class="card-header bg-danger-subtle text-danger fw-semibold">
                    <i class="bi bi-exclamation-triangle-fill me-1"></i>
                    {{ count(session('import_errors')) }} row(s) could not be imported
                </div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th scope="col" style="width:60px">Row</th>
                                <th scope="col">Email</th>
                                <th scope="col">Reason</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach(session('import_errors') as $err)
                                <tr>
                                    <td class="text-muted small">{{ $err['row'] }}</td>
                                    <td class="small">{{ $err['email'] }}</td>
                                    <td class="text-danger small">{{ $err['reason'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
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

        {{-- Upload form --}}
        <div class="card shadow-sm mb-4">
            <div class="card-header fw-semibold">
                <i class="bi bi-cloud-upload me-1"></i> Upload CSV File
            </div>
            <div class="card-body p-4">
                <form action="{{ route('admin.members.import.process', $club) }}" method="POST" enctype="multipart/form-data">
                    @csrf
                    <div class="mb-3">
                        <label class="form-label fw-semibold">CSV File <span class="text-danger">*</span></label>
                        <input type="file" name="file" accept=".csv,.txt"
                               class="form-control @error('file') is-invalid @enderror">
                        <div class="form-text">Max file size: 2 MB. Must be a valid <code>.csv</code> file.</div>
                        @error('file')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="d-flex gap-2 align-items-center">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-upload me-1"></i>Import Members
                        </button>
                        <a href="{{ route('admin.members.template', $club) }}" class="btn btn-outline-secondary">
                            <i class="bi bi-download me-1"></i>Download Template
                        </a>
                        <a href="{{ route('admin.clubs.members.index', $club) }}" class="btn btn-link text-secondary ms-auto">
                            Cancel
                        </a>
                    </div>
                </form>
            </div>
        </div>

        {{-- Format reference --}}
        <div class="card shadow-sm">
            <div class="card-header fw-semibold">
                <i class="bi bi-info-circle me-1"></i> CSV Format Reference
            </div>
            <div class="card-body p-4">
                <p class="text-muted small mb-3">
                    The first row must be a header row. Each subsequent row represents one member.
                    Columns must appear in the order shown below.
                </p>
                <div class="table-responsive">
                    <table class="table table-bordered table-sm small mb-3">
                        <thead class="table-light">
                            <tr>
                                <th scope="col">Column</th>
                                <th scope="col">Required</th>
                                <th scope="col">Accepted Values</th>
                                <th scope="col">Example</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><code>name</code></td>
                                <td><span class="badge bg-danger-subtle text-danger border border-danger-subtle">Yes</span></td>
                                <td>Any text</td>
                                <td>Ahmad Razif</td>
                            </tr>
                            <tr>
                                <td><code>email</code></td>
                                <td><span class="badge bg-danger-subtle text-danger border border-danger-subtle">Yes</span></td>
                                <td>Valid email, unique across all users</td>
                                <td>razif@company.com</td>
                            </tr>
                            <tr>
                                <td><code>job_level</code></td>
                                <td><span class="badge bg-danger-subtle text-danger border border-danger-subtle">Yes</span></td>
                                <td><code>gm</code>, <code>agm</code>, <code>manager</code>, <code>executive</code>, <code>non_exec</code></td>
                                <td>manager</td>
                            </tr>
                            <tr>
                                <td><code>role</code></td>
                                <td><span class="badge bg-danger-subtle text-danger border border-danger-subtle">Yes</span></td>
                                <td><code>member</code>, <code>admin</code></td>
                                <td>member</td>
                            </tr>
                            <tr>
                                <td><code>joined_date</code></td>
                                <td><span class="badge bg-danger-subtle text-danger border border-danger-subtle">Yes</span></td>
                                <td>Date — <code>YYYY-MM-DD</code> recommended</td>
                                <td>{{ date('Y-m-d') }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="bg-light rounded p-3 font-monospace small text-muted">
                    name,email,job_level,role,joined_date<br>
                    Ahmad Razif,razif@company.com,manager,member,{{ date('Y-m-d') }}<br>
                    Siti Nora,nora@company.com,executive,admin,{{ date('Y-m-d') }}
                </div>
                <p class="text-muted small mt-3 mb-0">
                    <i class="bi bi-envelope me-1"></i>
                    A temporary password will be auto-generated and emailed to each imported member.
                    Rows with duplicate emails will be skipped with an error.
                </p>
            </div>
        </div>

    </div>
</div>
@endsection
