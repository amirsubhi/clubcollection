@extends('layouts.app')
@section('title', $club->name . ' - Club profile')
@section('page-title', 'Club profile')
@section('page-club', $club->name)

@section('content')
<p class="text-muted">
    Members see these details on their invoices and receipts. A receipt keeps the details it was issued with, so
    changes here only show on receipts issued from now on.
</p>

@if($errors->any())
<div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
@endif

@unless($gdAvailable)
<div class="alert alert-warning" role="status" data-testid="gd-missing">
    This server can't process images (the PHP GD extension is missing), so a logo can't be uploaded. The other
    details can still be saved. Ask your host to enable GD.
</div>
@endunless

<div class="row">
    <div class="col-lg-7">
        <div class="card shadow-sm">
            <div class="card-body p-4">
                <form action="{{ route('admin.clubs.profile.update', $club) }}" method="POST" enctype="multipart/form-data">
                    @csrf @method('PUT')
                    <div class="mb-3">
                        <label for="f-name" class="form-label fw-semibold">Club name <span class="text-danger">*</span></label>
                        <input id="f-name" @error('name') aria-invalid="true" aria-describedby="f-name-error" @enderror type="text" name="name" maxlength="255" required
                               class="form-control @error('name') is-invalid @enderror" value="{{ old('name', $club->name) }}">
                        @error('name')<div id="f-name-error" role="alert" class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="mb-3">
                        <span class="form-label fw-semibold d-block" id="f-logo-label">Logo</span>
                        @if($club->logo_url)
                            <div class="d-flex flex-wrap align-items-center gap-3 mb-2">
                                <span class="d-inline-flex align-items-center justify-content-center rounded border bg-white p-1" style="width:4.5rem;height:4.5rem">
                                    <img src="{{ $club->logo_url }}" alt="{{ $club->name }}" style="max-width:100%;max-height:100%;object-fit:contain" data-testid="club-logo-preview">
                                </span>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="remove_logo" value="1" id="f-remove-logo" @checked(old('remove_logo'))>
                                    <label class="form-check-label" for="f-remove-logo">Remove the logo</label>
                                </div>
                            </div>
                        @endif
                        <input id="f-logo" aria-labelledby="f-logo-label" aria-describedby="f-logo-help @error('logo') f-logo-error @enderror" @error('logo') aria-invalid="true" @enderror
                               type="file" name="logo" accept="image/png,image/jpeg,image/webp" @disabled(! $gdAvailable)
                               class="form-control @error('logo') is-invalid @enderror">
                        <div id="f-logo-help" class="form-text">
                            {{ $club->logo_url ? 'Choose a file to replace the logo.' : 'Optional.' }}
                            PNG, JPEG or WebP, up to 2 MB and 4000 × 4000 pixels. It is resized to 512 pixels and saved as a PNG.
                        </div>
                        @error('logo')<div id="f-logo-error" role="alert" class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="mb-3">
                        <label for="f-email" class="form-label fw-semibold">Contact email <span class="fw-normal text-body-secondary">(optional)</span></label>
                        <input id="f-email" @error('email') aria-invalid="true" aria-describedby="f-email-error" @enderror type="email" name="email" maxlength="255"
                               class="form-control @error('email') is-invalid @enderror" value="{{ old('email', $club->email) }}">
                        @error('email')<div id="f-email-error" role="alert" class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="mb-3">
                        <label for="f-reg" class="form-label fw-semibold">Registration no. (ROS/PPM), optional</label>
                        <input id="f-reg" aria-describedby="f-reg-help @error('registration_no') f-reg-error @enderror" @error('registration_no') aria-invalid="true" @enderror type="text" name="registration_no" maxlength="60"
                               class="form-control @error('registration_no') is-invalid @enderror" value="{{ old('registration_no', $club->registration_no) }}">
                        <div id="f-reg-help" class="form-text">Shown on invoices, receipts and the ledger PDF as you type it.</div>
                        @error('registration_no')<div id="f-reg-error" role="alert" class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="mb-3">
                        <label for="f-address" class="form-label fw-semibold">Address <span class="fw-normal text-body-secondary">(optional)</span></label>
                        <textarea id="f-address" @error('address') aria-invalid="true" aria-describedby="f-address-error" @enderror name="address" rows="3" maxlength="500"
                                  class="form-control @error('address') is-invalid @enderror">{{ old('address', $club->address) }}</textarea>
                        @error('address')<div id="f-address-error" role="alert" class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="mb-3">
                        <label for="f-fy" class="form-label fw-semibold">Financial year starts in</label>
                        <select id="f-fy" name="financial_year_start_month" aria-describedby="f-fy-help @error('financial_year_start_month') f-fy-error @enderror" @error('financial_year_start_month') aria-invalid="true" @enderror
                                class="form-select @error('financial_year_start_month') is-invalid @enderror">
                            @php($fy = (int) old('financial_year_start_month', $club->financialYearStartMonth()))
                            @foreach($months as $num => $label)
                                <option value="{{ $num }}" @selected($fy === $num)>{{ $label }}</option>
                            @endforeach
                        </select>
                        <div id="f-fy-help" class="form-text">"This year" on the dashboard, Fund Usage, the ledger and the members' "where the money went" page starts in this month. January = the calendar year.</div>
                        @error('financial_year_start_month')<div id="f-fy-error" role="alert" class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="mb-3">
                        <label for="f-prefix" class="form-label fw-semibold">Receipt number prefix</label>
                        <input id="f-prefix" type="text" name="receipt_prefix" minlength="2" maxlength="6" pattern="[A-Za-z0-9]{2,6}" autocapitalize="characters" spellcheck="false"
                               aria-describedby="f-prefix-help @error('receipt_prefix') f-prefix-error @enderror" @error('receipt_prefix') aria-invalid="true" @enderror
                               class="form-control font-monospace @error('receipt_prefix') is-invalid @enderror" style="max-width:10rem"
                               value="{{ old('receipt_prefix', \App\Support\Payments\ReceiptNumbers::prefix($club)) }}">
                        <div id="f-prefix-help" class="form-text">
                            2 to 6 capital letters or digits. Receipts are numbered <span class="font-monospace">PREFIX-YEAR-0001</span>
                            per financial year (the year it starts in), starting again at 0001 each year. Next receipt:
                            <span class="font-monospace" data-testid="next-receipt-no">{{ $nextReceiptNo }}</span>.
                            A new prefix applies only to receipts issued from now on; a number is never reused.
                        </div>
                        @error('receipt_prefix')<div id="f-prefix-error" role="alert" class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="mb-4">
                        <label for="f-footer" class="form-label fw-semibold">Receipt footer <span class="fw-normal text-body-secondary">(optional)</span></label>
                        <textarea id="f-footer" name="receipt_footer" rows="3" maxlength="{{ $footerMax }}" aria-describedby="f-footer-help @error('receipt_footer') f-footer-error @enderror" @error('receipt_footer') aria-invalid="true" @enderror
                                  class="form-control @error('receipt_footer') is-invalid @enderror">{{ old('receipt_footer', $club->receipt_footer) }}</textarea>
                        <div id="f-footer-help" class="form-text">
                            Plain text, up to {{ $footerMax }} characters, printed under "This is a computer-generated receipt" on
                            receipts and receipt emails (for example a thank-you note or the treasurer's contact). Line breaks are kept.
                            Receipts already issued keep the footer they were issued with.
                        </div>
                        @error('receipt_footer')<div id="f-footer-error" role="alert" class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <button type="submit" class="btn btn-primary">Save profile</button>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
