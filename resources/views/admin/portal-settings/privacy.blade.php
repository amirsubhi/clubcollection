@extends('layouts.app')
@section('title', 'Privacy notice')
@section('page-title', 'Privacy notice')

@section('content')
<p class="text-muted">
    The portal's privacy notice, shown to anyone on the public <a href="{{ route('privacy') }}" target="_blank" rel="noopener">privacy page</a>
    (linked from the sign-in pages and the member app). Write it in Bahasa Melayu and English; a page in one language
    shows the other when only one is filled in. Plain text only: leave a blank line between paragraphs. Nothing is
    filled in for you: until a notice is saved, the page says the notice has not been published yet.
</p>

@if($errors->any())
<div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
@endif

<div class="row">
    <div class="col-xl-9">
        <div class="card shadow-sm">
            <div class="card-body p-4">
                <form action="{{ route('admin.privacy-notice.update') }}" method="POST">
                    @csrf @method('PUT')
                    @foreach(['privacy_notice_ms' => ['ms', 'Notice in Bahasa Melayu'], 'privacy_notice_en' => ['en', 'Notice in English']] as $field => [$code, $label])
                    <div class="mb-3">
                        <label for="f-{{ $code }}" class="form-label fw-semibold">{{ $label }} <span class="fw-normal text-body-secondary">(optional)</span></label>
                        <textarea id="f-{{ $code }}" name="{{ $field }}" rows="12" maxlength="{{ $maxText }}" lang="{{ $code }}"
                                  aria-describedby="f-{{ $code }}-help @error($field) f-{{ $code }}-error @enderror" @error($field) aria-invalid="true" @enderror
                                  class="form-control @error($field) is-invalid @enderror">{{ old($field, $settings[$field]) }}</textarea>
                        <div id="f-{{ $code }}-help" class="form-text">Up to {{ number_format($maxText) }} characters. A blank line starts a new paragraph.</div>
                        @error($field)<div id="f-{{ $code }}-error" role="alert" class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    @endforeach

                    <fieldset class="mb-4">
                        <legend class="form-label fw-semibold fs-6">Data protection contact <span class="fw-normal text-body-secondary">(optional)</span></legend>
                        <p class="form-text mt-0">Who members can write to about their personal data. Shown under the notice.</p>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="f-contact-name" class="form-label">Name or role</label>
                                <input id="f-contact-name" type="text" name="privacy_contact_name" maxlength="100"
                                       @error('privacy_contact_name') aria-invalid="true" aria-describedby="f-contact-name-error" @enderror
                                       class="form-control @error('privacy_contact_name') is-invalid @enderror" value="{{ old('privacy_contact_name', $settings['privacy_contact_name']) }}">
                                @error('privacy_contact_name')<div id="f-contact-name-error" role="alert" class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-6">
                                <label for="f-contact-email" class="form-label">Email</label>
                                <input id="f-contact-email" type="email" name="privacy_contact_email" maxlength="255"
                                       @error('privacy_contact_email') aria-invalid="true" aria-describedby="f-contact-email-error" @enderror
                                       class="form-control @error('privacy_contact_email') is-invalid @enderror" value="{{ old('privacy_contact_email', $settings['privacy_contact_email']) }}">
                                @error('privacy_contact_email')<div id="f-contact-email-error" role="alert" class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                    </fieldset>

                    <button type="submit" class="btn btn-primary">Save privacy notice</button>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
