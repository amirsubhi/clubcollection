{{-- Quiet note for club admins when a security update is waiting (spec J2). No link, no button. --}}
<div id="update-note" class="alert alert-warning mb-4" role="status" hidden data-status-url="{{ route('admin.updates.status') }}" data-portal="{{ \App\Support\Portal\PortalSettings::name() }}"></div>
<script nonce="{{ $cspNonce }}">
(function () {
    var el = document.getElementById('update-note');
    if (!el || !window.fetch) { return; }
    fetch(el.dataset.statusUrl, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (s) {
            if (!s || !s.security_waiting) { return; }
            var names = (s.super_admins || []).join(', ');
            el.textContent = 'A security update for ' + el.dataset.portal + ' is waiting.' + (names ? ' Your super admin (' + names + ') can install it.' : '');
            el.hidden = false;
        })
        .catch(function () {});
})();
</script>
