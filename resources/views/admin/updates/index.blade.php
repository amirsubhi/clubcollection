@extends('layouts.app')
@section('title', 'Updates')
@section('page-title', 'Updates')

@section('content')
@if ($latest === null || ! $status->available)
    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <p class="h5 mb-1">You're on v{{ $status->installed }}</p>
            @if ($status->error)
                <p class="mb-0">{{ $status->error }}</p>
            @else
                <p class="mb-0">You're up to date.</p>
            @endif
        </div>
    </div>
@else
    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <div class="d-flex flex-wrap justify-content-between gap-2">
                <h2 class="h4 mb-0">Club Portal v{{ $latest->version }}</h2>
                <span class="text-muted">released {{ $releasedOn }}</span>
            </div>
            <p class="mb-3">You're on v{{ $status->installed }}</p>

            @if ($security)
                <div class="alert alert-danger" role="alert">This update fixes a security problem. Updating soon keeps your members' details safe.</div>
            @endif

            <h3 class="h6">What's new</h3>
            <ul class="mb-3">
                @foreach ($latest->whatsNew as $line)
                    <li>{{ $line }}</li>
                @endforeach
            </ul>

            <p>Updating takes about 2 minutes. During that time members see a 'back in a few minutes' page. Pick a quiet time, such as late evening.</p>

            @if ($ready)
                <p class="fw-semibold text-success mb-2"><i class="bi bi-check-circle me-1" aria-hidden="true"></i>Your site is ready to update</p>
            @else
                <div class="border border-danger rounded p-3 mb-3" data-testid="cannot-update">
                    <p class="fw-semibold text-danger mb-1"><i class="bi bi-x-circle me-1" aria-hidden="true"></i>Your site can't update itself on this host yet.</p>
                    @foreach ($failing as $c)
                        <p class="mb-1">{{ $c->message }}</p>
                    @endforeach
                    @if ($hostMessage !== '')
                        <p class="mt-3 mb-1"><strong>What to do:</strong> send this message to your hosting company's support.</p>
                        <textarea id="host-message" class="form-control mb-2" rows="5" readonly aria-label="Message for my host">{{ $hostMessage }}</textarea>
                        <button type="button" id="copy-host-message" class="btn btn-outline-primary btn-sm" hidden>Copy message for my host</button>
                        <span id="copy-host-done" class="small text-success ms-2" hidden>Copied</span>
                    @endif
                    <p class="mt-3 mb-0">Or ask whoever installed the portal to update it by hand (<a href="{{ $manualStepsUrl }}" target="_blank" rel="noopener noreferrer">instructions</a>).</p>
                </div>
            @endif

            <details class="mb-3" data-testid="show-checks">
                <summary>Show checks</summary>
                <ul class="list-unstyled mt-2 mb-0">
                    @foreach ($checks as $c)
                        <li class="mb-2">
                            @if ($c->ok)
                                <i class="bi bi-check-circle text-success me-1" aria-hidden="true"></i><span class="visually-hidden">Passed:</span>{{ $c->label }}
                                @if ($c->message !== '')<div class="small text-muted ms-4">{{ $c->message }}</div>@endif
                            @else
                                <i class="bi bi-x-circle text-danger me-1" aria-hidden="true"></i><span class="visually-hidden">Failed:</span>{{ $c->label }}
                                <div class="small ms-4">{{ $c->message }}</div>
                                @if ($c->fix)<div class="small text-muted ms-4">{{ $c->fix }}</div>@endif
                            @endif
                        </li>
                    @endforeach
                </ul>
            </details>

            @if ($ready)
                {{-- Stage 1: the update button is not here yet, so the manual steps are the way to update. --}}
                <p class="mb-3"><a href="{{ $manualStepsUrl }}" target="_blank" rel="noopener noreferrer">To update, follow the upgrade steps</a></p>
            @endif

            <details data-technical>
                <summary>Technical details</summary>
                <pre class="mt-2 mb-2" style="white-space: pre-wrap">{{ $latest->notesMarkdown }}</pre>
                <a href="{{ $latest->notesUrl }}" target="_blank" rel="noopener noreferrer">Release notes on GitHub</a>
            </details>
        </div>
    </div>
@endif

<form method="POST" action="{{ route('admin.updates.check') }}" class="d-flex flex-wrap align-items-center gap-3">
    @csrf
    <button type="submit" class="btn btn-outline-secondary">Check now</button>
    <span class="text-muted small">Last checked {{ $checkedAt->diffForHumans() }}</span>
</form>

@if (($hostMessage ?? '') !== '' && ! $ready)
<script nonce="{{ $cspNonce }}">
(function () {
    var btn = document.getElementById('copy-host-message');
    var box = document.getElementById('host-message');
    var done = document.getElementById('copy-host-done');
    if (!btn || !box || !navigator.clipboard) { return; }
    btn.hidden = false;
    btn.addEventListener('click', function () {
        navigator.clipboard.writeText(box.value).then(function () { done.hidden = false; }, function () { box.select(); });
    });
})();
</script>
@endif
@endsection
