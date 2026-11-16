@extends('layouts.app')
@section('title', 'Expense Categories')
@section('page-title', 'Expense Categories')
@section('page-club', $club->name)

@section('content')
<div class="row g-4">
    <div class="col-md-5">
        <div class="card shadow-sm">
            <div class="card-header fw-semibold">Add Category</div>
            <div class="card-body p-4">
                <form action="{{ route('admin.clubs.expense-categories.store', $club) }}" method="POST">
                    @csrf
                    <div class="mb-3">
                        <label for="f-name" class="form-label fw-semibold">Category Name <span class="text-danger">*</span></label>
                        <input id="f-name" @error('name') aria-invalid="true" aria-describedby="f-name-error" @enderror type="text" name="name" class="form-control @error('name') is-invalid @enderror"
                               value="{{ old('name') }}" placeholder="e.g. Event">
                        @error('name')<div id="f-name-error" role="alert" class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <button type="submit" class="btn btn-primary">Add Category</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-md-7">
        <div class="card shadow-sm">
            <div class="card-header fw-semibold">Categories ({{ $categories->count() }})</div>
            <div class="list-group list-group-flush">
                @forelse($categories as $cat)
                <div class="list-group-item d-flex align-items-center justify-content-between gap-3 py-2">
                    <form action="{{ route('admin.expense-categories.update', $cat) }}" method="POST" class="d-flex align-items-center gap-2 flex-grow-1">
                        @csrf @method('PUT')
                        <input type="text" name="name" value="{{ $cat->name }}" class="form-control form-control-sm" aria-label="Name of category {{ $cat->name }}">
                        <span class="text-muted small text-nowrap">{{ $cat->expenses_count }} expense(s)</span>
                        <button type="submit" class="btn btn-sm btn-outline-primary text-nowrap">Save</button>
                    </form>
                    @if($cat->expenses_count == 0)
                    <form action="{{ route('admin.expense-categories.destroy', $cat) }}" method="POST"
                          data-confirm="Delete this category?">
                        @csrf @method('DELETE')
                        <button class="btn btn-sm btn-outline-danger" aria-label="Delete category {{ $cat->name }}"><i class="bi bi-trash"></i></button>
                    </form>
                    @endif
                </div>
                @empty
                <div class="list-group-item text-muted text-center py-4">No categories yet.</div>
                @endforelse
            </div>
        </div>
    </div>
</div>
@endsection
