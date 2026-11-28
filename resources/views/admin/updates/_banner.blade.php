{{-- Dashboard strip for the super admin (spec J1, J2). Hidden until the status answer says an update is waiting. --}}
<div id="update-banner" class="alert mb-4" role="status" hidden data-status-url="{{ route('admin.updates.status') }}" data-updates-url="{{ route('admin.updates.index') }}"></div>
<script nonce="{{ $cspNonce }}">
(function () {
    var el = document.getElementById('update-banner');
    if (!el || !window.fetch) { return; }
    fetch(el.dataset.statusUrl, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (s) {
            if (!s || !s.available || !s.latest) { return; }
            var strong = document.createElement('strong');
            var link = document.createElement('a');
            link.href = el.dataset.updatesUrl;
            link.className = 'alert-link';
            if (s.latest.security) {
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
