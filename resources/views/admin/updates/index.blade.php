@extends('layouts.app')
@section('title', 'Updates')
@section('page-title', 'Updates')

@section('content')
@if ($latest === null || ! $status->available)
    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <p class="h5 mb-1">You're on {{ \App\Support\Updates\Version::isRelease($status->installed) ? 'v' : '' }}{{ $status->installed }}</p>
            @if ($status->error)
                <p class="mb-0">{{ $status->error }}</p>
                @if ($status->manualSteps)
                    <p class="mt-2 mb-0"><a href="{{ $manualStepsUrl }}" target="_blank" rel="noopener noreferrer">Follow the upgrade steps</a></p>
                @endif
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
                    @if ($hostMessage !== '')
                        <p class="fw-semibold text-danger mb-1"><i class="bi bi-x-circle me-1" aria-hidden="true"></i>Your site can't update itself on this host yet.</p>
                    @else
                        <p class="fw-semibold text-danger mb-1"><i class="bi bi-x-circle me-1" aria-hidden="true"></i>Your site isn't ready to update right now.</p>
                    @endif
                    @foreach ($blockers as $c)
                        <p class="mb-2">{{ $c->message }}@if ($c->fix)<br><span class="text-muted">{{ $c->fix }}</span>@endif</p>
                    @endforeach
                    @if ($hostMessage !== '')
                        <p class="mt-3 mb-1"><strong>What to do:</strong> send this message to your hosting company's support.</p>
                        <textarea id="host-message" class="form-control mb-2" rows="5" readonly aria-label="Message for my host">{{ $hostMessage }}</textarea>
                        <button type="button" class="btn btn-outline-primary btn-sm" data-copy-from="host-message" hidden>Copy message for my host</button>
                        <span class="small text-success ms-2" data-copy-done hidden>Copied</span>
                    @endif
                    <p class="mt-3 mb-0">Or ask whoever installed the portal to update it by hand (<a href="{{ $manualStepsUrl }}" target="_blank" rel="noopener noreferrer">instructions</a>).</p>
                </div>
            @endif

            @if ($before !== [])
                <div class="border rounded p-3 mb-3" data-testid="before-you-update">
                    <h3 class="h6">Before you update</h3>
                    @foreach ($before as $c)
                        <div class="mb-2">
                            <p class="mb-1">{{ $c->message }}@if ($c->fix)<br><span class="text-muted">{{ $c->fix }}</span>@endif</p>
                            @if ($c->hostMessage)
                                <textarea id="before-{{ $c->key }}" class="form-control mb-2" rows="4" readonly aria-label="Message to send">{{ $c->hostMessage }}</textarea>
                                <button type="button" class="btn btn-outline-primary btn-sm" data-copy-from="before-{{ $c->key }}" hidden>Copy message</button>
                                <span class="small text-success ms-2" data-copy-done hidden>Copied</span>
                            @endif
                        </div>
                    @endforeach
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
                @if ($installedCommit !== null)
                    <p class="mt-2 mb-1">Installed commit: <code>{{ $installedCommit }}</code></p>
                @endif
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

<script nonce="{{ $cspNonce }}">
(function () {
    if (!navigator.clipboard) { return; }
    document.querySelectorAll('[data-copy-from]').forEach(function (btn) {
        var box = document.getElementById(btn.dataset.copyFrom);
        var done = btn.parentNode.querySelector('[data-copy-done]');
        if (!box) { return; }
        btn.hidden = false;
        btn.addEventListener('click', function () {
            navigator.clipboard.writeText(box.value).then(function () { if (done) { done.hidden = false; } }, function () { box.select(); });
        });
    });
})();
</script>
@endsection
