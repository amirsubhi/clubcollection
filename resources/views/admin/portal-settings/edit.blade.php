@extends('layouts.app')
@section('title', 'Portal settings')
@section('page-title', 'Portal settings')

@section('content')
<p class="text-muted">
    The portal's name and logo, shown on the sign-in pages, in the admin sidebar, in page titles and in emails.
    When exactly one club is active, the sign-in pages show that club's name and logo instead (set on its Club profile page).
</p>

@if($errors->any())
<div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
@endif

@unless($gdAvailable)
<div class="alert alert-warning" role="status" data-testid="gd-missing">
    This server can't process images (the PHP GD extension is missing), so a logo can't be uploaded. Ask your host to enable GD.
</div>
@endunless

<div class="row">
    <div class="col-lg-7">
        <div class="card shadow-sm">
            <div class="card-body p-4">
                <form action="{{ route('admin.portal-settings.update') }}" method="POST" enctype="multipart/form-data">
                    @csrf @method('PUT')
                    <div class="mb-3">
                        <label for="f-name" class="form-label fw-semibold">Portal name <span class="fw-normal text-body-secondary">(optional)</span></label>
                        <input id="f-name" aria-describedby="f-name-help @error('name') f-name-error @enderror" @error('name') aria-invalid="true" @enderror type="text" name="name" maxlength="100"
                               class="form-control @error('name') is-invalid @enderror" value="{{ old('name', $name) }}" placeholder="{{ $defaultName }}">
                        <div id="f-name-help" class="form-text">Left empty, the portal is called "{{ $defaultName }}" (APP_NAME).</div>
                        @error('name')<div id="f-name-error" role="alert" class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="mb-4">
                        <span class="form-label fw-semibold d-block" id="f-logo-label">Portal logo <span class="fw-normal text-body-secondary">(optional)</span></span>
                        @if($logoUrl)
                            <div class="d-flex flex-wrap align-items-center gap-3 mb-2">
                                <span class="d-inline-flex align-items-center justify-content-center rounded border bg-white p-1" style="width:4.5rem;height:4.5rem">
                                    <img src="{{ $logoUrl }}" alt="{{ $name ?? $defaultName }}" style="max-width:100%;max-height:100%;object-fit:contain" data-testid="portal-logo-preview">
                                </span>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="remove_logo" value="1" id="f-remove-logo" @checked(old('remove_logo'))>
                                    <label class="form-check-label" for="f-remove-logo">Remove the logo (use the built-in mark)</label>
                                </div>
                            </div>
                        @endif
                        <input id="f-logo" aria-labelledby="f-logo-label" aria-describedby="f-logo-help @error('logo') f-logo-error @enderror" @error('logo') aria-invalid="true" @enderror
                               type="file" name="logo" accept="image/png,image/jpeg,image/webp" @disabled(! $gdAvailable)
                               class="form-control @error('logo') is-invalid @enderror">
                        <div id="f-logo-help" class="form-text">PNG, JPEG or WebP, up to 2 MB and 4000 × 4000 pixels. It is resized to 512 pixels and saved as a PNG.</div>
                        @error('logo')<div id="f-logo-error" role="alert" class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <button type="submit" class="btn btn-primary">Save settings</button>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
