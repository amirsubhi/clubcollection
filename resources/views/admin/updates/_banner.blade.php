{{-- Dashboard strip for the super admin (spec J1, J2). Hidden until the status answer says an update is waiting. --}}
<div id="update-banner" class="alert mb-4" role="status" hidden data-status-url="{{ route('admin.updates.status') }}" data-updates-url="{{ route('admin.updates.index') }}"></div>
<script nonce="{{ $cspNonce }}">
(function () {
    var el = document.getElementById('update-banner');
    if (!el || !window.fetch) { return; }
    fetch(el.dataset.statusUrl, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (s) {
            if (!s) { return; }
            var strong = document.createElement('strong');
            var link = document.createElement('a');
            link.className = 'alert-link';
            if (s.running) {
                // J4/J8: an update is running; the bypass cookie lets this admin watch it.
                link.href = '/update/';
                el.className = 'alert alert-warning mb-4';
                strong.textContent = 'Update in progress.';
                link.textContent = 'Watch';
                el.append(strong, ' ', link);
                el.hidden = false;
                return;
            }
            if (!s.available || !s.latest) { return; }
            link.href = el.dataset.updatesUrl;
            if (s.latest.security && s.last_outcome && s.last_outcome !== 'finished') {
                // J5: the last attempt did not go through, so the alarm must come with something to do.
                el.className = 'alert alert-danger mb-4';
                strong.textContent = 'The security update couldn’t be installed on this host.';
                link.textContent = 'What to do';
                el.append(strong, ' Your portal still works. ', link);
            } else if (s.latest.security) {
                el.className = 'alert alert-danger mb-4';
                strong.textContent = 'Important security fix: Club Portal v' + s.latest.version + '.';
                link.textContent = 'Update now';
                el.append(strong, ' Please update today. It takes about 2 minutes. ', link);
            } else {
                el.className = 'alert alert-info mb-4';
                strong.textContent = 'Club Portal v' + s.latest.version + ' is available.';
                link.textContent = 'See what’s new';
                el.append(strong, ' You’re on v' + s.installed + '. ', link);
            }
            el.hidden = false;
        })
        .catch(function () {});
})();
</script>
