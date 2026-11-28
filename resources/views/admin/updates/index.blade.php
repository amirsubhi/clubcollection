@extends('layouts.app')
@section('title', 'Updates')
@section('page-title', 'Updates')

@section('content')
@if (session('status'))
    <div class="alert alert-success" role="status">{{ session('status') }}</div>
@endif

@if ($blockedByFailure && ! $running)
    <div class="card border-danger shadow-sm mb-4" data-testid="update-blocked">
        <div class="card-body">
            <p class="fw-semibold text-danger mb-1"><i class="bi bi-x-circle me-1" aria-hidden="true"></i>One change to your club's records didn't finish.</p>
            <p class="mb-2">Your members can still use the portal. The update button is switched off until this is sorted. Send the report to whoever looks after the portal: they may need to restore the last saved copy of your data.</p>
            @if ($lastAttempt && $lastAttempt['report'] !== '')
                <textarea id="blocked-report" class="form-control mb-2" rows="6" readonly aria-label="Report">{{ $lastAttempt['report'] }}</textarea>
                <button type="button" class="btn btn-outline-primary btn-sm mb-3" data-copy-from="blocked-report" hidden>Copy report</button>
                <span class="small text-success ms-2" data-copy-done hidden>Copied</span>
            @endif
            <form method="POST" action="{{ route('admin.updates.unblock') }}" id="update-unblock-form" class="border rounded p-3" data-testid="update-unblock">
                @csrf
                <p class="mb-2">Once the copy of your data has been restored or checked, confirm it is you to switch the update button back on.</p>
                @if ($errors->has('update'))
                    <div class="alert alert-danger" role="alert">{{ $errors->first('update') }}</div>
                @endif
                <div class="mb-3">
                    <label for="unblock-password" class="form-label">Your password</label>
                    <input type="password" class="form-control" id="unblock-password" name="password" required autocomplete="current-password">
                </div>
                @if ($twoFactorOn)
                    <div class="mb-3">
                        <label for="unblock-code" class="form-label">The 6-digit code from your authenticator app</label>
                        <input type="text" class="form-control" id="unblock-code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required>
                    </div>
                @endif
                <button type="submit" class="btn btn-danger">The database was restored or checked</button>
            </form>
        </div>
    </div>
@elseif ($lastAttempt && $lastAttempt['outcome'] !== 'finished' && ! $running)
    <div class="card shadow-sm mb-4" data-testid="update-last-attempt-card">
        <div class="card-body">
            <p class="h5">{{ $lastAttempt['title'] }}</p>
            @foreach ($lastAttempt['lines'] as $line)
                <p class="mb-2">{{ $line }}</p>
            @endforeach
            @if ($lastAttempt['what_happened'])
                <p class="mb-2"><strong>What happened:</strong> {{ $lastAttempt['what_happened'] }}</p>
            @endif
            @if ($lastAttempt['what_to_do'])
                <p class="mb-2"><strong>What to do:</strong> {{ $lastAttempt['what_to_do'] }}</p>
            @endif
        </div>
    </div>
@endif

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

            @if ($running)
                <div class="border border-warning rounded p-3 mb-3" data-testid="update-running">
                    @if ($running['who'] !== null && $running['to'] !== null)
                        <p class="mb-0">{{ $running['who'] }} is updating the portal to v{{ $running['to'] }}@if ($running['started'] !== null) (started {{ $running['started'] }})@endif.</p>
                    @else
                        <p class="mb-0">An update is already running. Wait for it to finish, then reload this page.</p>
                    @endif
                    @if ($running['mine'])
                        <p class="mt-2 mb-0"><a href="/update/">Go back to the update screen</a></p>
                    @endif
                </div>
            @elseif ($ready)
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

            @if ($before !== [] && ! $running)
                <div class="border rounded p-3 mb-3" data-testid="before-you-update">
                    <h3 class="h6">Before you update</h3>
                    @foreach ($before as $c)
                        <div class="mb-3">
                            <p class="mb-1">{{ $c->message }}@if ($c->fix)<br><span class="text-muted">{{ $c->fix }}</span>@endif</p>
                            @if ($c->hostMessage)
                                <textarea id="before-{{ $c->key }}" class="form-control mb-2" rows="4" readonly aria-label="Message to send">{{ $c->hostMessage }}</textarea>
                                <button type="button" class="btn btn-outline-primary btn-sm" data-copy-from="before-{{ $c->key }}" hidden>Copy message</button>
                                <span class="small text-success ms-2" data-copy-done hidden>Copied</span>
                            @endif
                            @if ($c->isAdvice() && $canUpdate)
                                <div class="form-check mt-2">
                                    <input class="form-check-input" type="checkbox" form="update-start-form" data-update-tick
                                           id="tick-{{ $c->key }}" name="{{ $c->key === 'backup_database' ? 'db_backup_ticked' : 'manual_ticked' }}" value="1"
                                           @checked(old($c->key === 'backup_database' ? 'db_backup_ticked' : 'manual_ticked'))>
                                    <label class="form-check-label" for="tick-{{ $c->key }}">It's been done (ticked by the person who did it)</label>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif

            @if (! $running)
                <details class="mb-3" data-testid="show-checks">
                    <summary>Show checks</summary>
                    <ul class="list-unstyled mt-2 mb-0">
                        @foreach ($checks as $c)
                            <li class="mb-2">
                                @if ($c->ok)
                                    <i class="bi bi-check-circle text-success me-1" aria-hidden="true"></i><span class="visually-hidden">Passed:</span>{{ $c->label }}
                                    @if ($c->message !== '')<div class="small text-muted ms-4">{{ $c->message }}</div>@endif
                                    @if ($c->message !== '' && $c->fix)<div class="small text-muted ms-4">{{ $c->fix }}</div>@endif
                                @elseif ($c->isAdvice())
                                    <i class="bi bi-info-circle text-primary me-1" aria-hidden="true"></i><span class="visually-hidden">To do first:</span>{{ $c->label }}
                                    <div class="small ms-4">{{ $c->message }}</div>
                                    @if ($c->fix)<div class="small text-muted ms-4">{{ $c->fix }}</div>@endif
                                @else
                                    <i class="bi bi-x-circle text-danger me-1" aria-hidden="true"></i><span class="visually-hidden">Failed:</span>{{ $c->label }}
                                    <div class="small ms-4">{{ $c->message }}</div>
                                    @if ($c->fix)<div class="small text-muted ms-4">{{ $c->fix }}</div>@endif
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </details>
            @endif

            @if ($canUpdate && ! $running)
                <form method="POST" action="{{ route('admin.updates.start') }}" id="update-start-form" class="border rounded p-3 mb-3" data-testid="update-start">
                    @csrf
                    <input type="hidden" name="version" value="{{ $latest->version }}">
                    @if ($errors->has('update'))
                        <div class="alert alert-danger" role="alert">{{ $errors->first('update') }}</div>
                    @endif
                    <p class="mb-2">To start the update, confirm it is you.</p>
                    <div class="mb-3">
                        <label for="update-password" class="form-label">Your password</label>
                        <input type="password" class="form-control" id="update-password" name="password" required autocomplete="current-password">
                        @error('password')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                    </div>
                    @if ($twoFactorOn)
                        <div class="mb-3">
                            <label for="update-code" class="form-label">The 6-digit code from your authenticator app</label>
                            <input type="text" class="form-control" id="update-code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required>
                        </div>
                    @else
                        <p class="small text-muted">Two-step sign-in is not turned on for your account, so your password is all that confirms this update. You can turn it on under <a href="{{ route('profile.security') }}">your security settings</a>.</p>
                    @endif
                    <button type="submit" class="btn btn-primary" data-update-button>Update to v{{ $latest->version }}</button>
                </form>
            @endif

            @if ($ready && ! $running)
                <p class="mb-3 text-muted small">Or ask whoever installed the portal to update it by hand (<a href="{{ $manualStepsUrl }}" target="_blank" rel="noopener noreferrer">instructions</a>).</p>
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

@if ($lastAttempt)
    <div class="mb-4" data-testid="update-last-attempt">
        <p class="mb-1">Last update attempt: {{ $lastAttempt['when'] }}, {{ $lastAttempt['label'] }}</p>
        @if ($lastAttempt['report'] !== '')
            <details id="last-report">
                <summary>View report</summary>
                <textarea id="last-report-text" class="form-control my-2" rows="8" readonly aria-label="Report">{{ $lastAttempt['report'] }}</textarea>
                <button type="button" class="btn btn-outline-primary btn-sm" data-copy-from="last-report-text" hidden>Copy report</button>
                <span class="small text-success ms-2" data-copy-done hidden>Copied</span>
            </details>
        @endif
    </div>
@endif

<form method="POST" action="{{ route('admin.updates.check') }}" class="d-flex flex-wrap align-items-center gap-3">
    @csrf
    <button type="submit" class="btn btn-outline-secondary">Check now</button>
    <span class="text-muted small">Last checked {{ $checkedAt->diffForHumans() }}</span>
</form>

<script nonce="{{ $cspNonce }}">
(function () {
    var button = document.querySelector('[data-update-button]');
    var ticks = document.querySelectorAll('[data-update-tick]');
    if (button && ticks.length) {
        var sync = function () { button.disabled = Array.prototype.some.call(ticks, function (t) { return !t.checked; }); };
        ticks.forEach(function (t) { t.addEventListener('change', sync); });
        sync();
    }
})();
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
