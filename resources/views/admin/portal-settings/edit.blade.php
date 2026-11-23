@extends('layouts.app')
@section('title', 'Portal settings')
@section('page-title', 'Portal settings')

@section('content')
<p class="text-muted" style="max-width:70ch">
    The portal's name, logo and brand colour, shown on the sign-in pages, in the admin sidebar, in page titles and in emails,
    and used for the installable app's name and icon.
    When exactly one club is active, the sign-in pages show that club's name and logo instead (set on its Club profile page).
</p>

{{-- Field errors show (and are announced) once, under their field; only an error with no field here goes on top. --}}
@php
    $otherError = collect($errors->getMessages())->except(['name', 'logo', 'remove_logo', 'brand', 'breach_check'])->flatten()->first();
@endphp
@if($otherError)
<div class="alert alert-danger" role="alert">{{ $otherError }}</div>
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

                    <fieldset class="mb-4" aria-describedby="f-brand-help @error('brand') f-brand-error @enderror">
                        <legend class="form-label fw-semibold fs-6 mb-1">Brand colour</legend>
                        <div id="f-brand-help" class="form-text mt-0 mb-2">Buttons, links and highlights on every page, in light and dark mode. Status colours (paid, overdue) and charts don't change.</div>
                        @php
                            $chosenBrand = old('brand', $brand);
                        @endphp
                        <div class="d-flex flex-column gap-2" data-testid="brand-presets">
                            @foreach($presets as $key)
                                @php
                                    $light = \App\Support\Portal\BrandPalette::tokens($key, 'light');
                                    $dark = \App\Support\Portal\BrandPalette::tokens($key, 'dark');
                                    $lightBg = \App\Support\Portal\BrandPalette::SURFACES['light']['card'];
                                    $darkBg = \App\Support\Portal\BrandPalette::SURFACES['dark']['card'];
                                @endphp
                                <div class="form-check brand-choice d-flex align-items-start gap-3 border rounded px-3 py-2 m-0">
                                    <input class="form-check-input m-0 flex-shrink-0" type="radio" name="brand" value="{{ $key }}" id="f-brand-{{ $key }}" @checked($chosenBrand === $key)>
                                    <label class="form-check-label d-flex flex-wrap align-items-center gap-2 gap-sm-3 flex-grow-1" style="min-width:0" for="f-brand-{{ $key }}">
                                        <span class="fw-semibold brand-choice-name">{{ \App\Support\Portal\BrandPalette::label($key) }}</span>
                                        {{-- Static previews (no script): a button and a link, in light and in dark. --}}
                                        <span class="d-inline-flex align-items-center gap-2 rounded border px-2 py-1" style="background:{{ $lightBg }}" aria-hidden="true" data-swatch="{{ $key }}-light">
                                            <span class="rounded-pill px-2 small fw-bold" style="background:{{ $light['primary'] }};color:{{ $light['primary-foreground'] }}">Pay</span>
                                            <span class="small fw-semibold text-decoration-underline" style="color:{{ $light['primary'] }}">Link</span>
                                            <span class="rounded px-2 small" style="background:{{ $light['accent'] }};color:{{ $light['accent-foreground'] }}">Tag</span>
                                        </span>
                                        <span class="d-inline-flex align-items-center gap-2 rounded border px-2 py-1" style="background:{{ $darkBg }}" aria-hidden="true" data-swatch="{{ $key }}-dark">
                                            <span class="rounded-pill px-2 small fw-bold" style="background:{{ $dark['primary'] }};color:{{ $dark['primary-foreground'] }}">Pay</span>
                                            <span class="small fw-semibold text-decoration-underline" style="color:{{ $dark['primary'] }}">Link</span>
                                            <span class="rounded px-2 small" style="background:{{ $dark['accent'] }};color:{{ $dark['accent-foreground'] }}">Tag</span>
                                        </span>
                                    </label>
                                </div>
                            @endforeach
                        </div>
                        @error('brand')<div id="f-brand-error" role="alert" class="invalid-feedback d-block">{{ $message }}</div>@enderror
                    </fieldset>

                    <div class="mb-4" data-testid="app-icon-note">
                        <span class="form-label fw-semibold d-block">App icon</span>
                        <p class="form-text mt-0 mb-0">
                            @if($appIcons)
                                The installed app (Add to Home Screen) and the browser tab use icons made from the portal logo, on the brand colour.
                            @elseif(! $gdAvailable)
                                The built-in icon is used: this server can't make icons from the logo without the PHP GD extension.
                            @else
                                The built-in icon is used. Upload a portal logo to make the app icon from it.
                            @endif
                            Phones that already installed the app may keep the old icon and name until the app is removed and added again.
                        </p>
                    </div>

                    <fieldset class="mb-4" data-testid="breach-check">
                        <legend class="form-label fw-semibold fs-6 mb-1">Breached-password check</legend>
                        {{-- Right after the legend (Bootstrap clears the floated legend with "legend + *"); help above the
                             choice, as for the brand colour. --}}
                        <div id="f-breach-check-help" class="form-text mt-0 mb-2">
                            When anyone sets a new password, it is checked against Have I Been Pwned, a public list of leaked passwords. The password itself is never sent.
                        </div>
                        <div class="form-check breach-check">
                            <input type="hidden" name="breach_check" value="0">
                            <input class="form-check-input" type="checkbox" name="breach_check" value="1" id="f-breach-check"
                                   aria-describedby="f-breach-check-help" @checked(old('breach_check', $breachCheckOn ? '1' : '0') === '1')>
                            <label class="form-check-label" for="f-breach-check">Refuse new passwords that appear in known data breaches <span class="fw-normal text-body-secondary">(recommended)</span></label>
                            <div class="breach-check-off form-text text-warning-emphasis" data-testid="breach-check-off">
                                <i class="bi bi-exclamation-triangle" aria-hidden="true"></i> Off: any password of 12 or more characters is accepted, even one known to have leaked.
                            </div>
                        </div>
                        <details class="form-text mt-2">
                            <summary>How it works</summary>
                            <p class="mt-1 mb-0">
                                Only the first 5 characters of the password's SHA-1 hash go to pwnedpasswords.com; the service answers
                                with matching hashes and the server compares them itself. If the service can't be reached, the password
                                is accepted. Turn this off only if your host blocks outgoing connections or your club doesn't want the
                                lookup. The installer always checks the first admin's password.
                            </p>
                        </details>
                        @error('breach_check')<div role="alert" class="invalid-feedback d-block">{{ $message }}</div>@enderror
                    </fieldset>

                    <button type="submit" class="btn btn-primary">Save settings</button>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
