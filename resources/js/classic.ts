// Bootstrap's JavaScript for the classic Blade pages (layouts/app.blade.php, the installer), bundled from npm
// instead of the jsDelivr CDN. The data-bs-* attributes work on their own; inline page scripts that call
// window.bootstrap run from window.cpWhenReady("bootstrap", ...), since a module script runs after them.
import * as bootstrap from "bootstrap";

window.bootstrap = bootstrap;
// Inline scripts waiting in window.cpWhenReady (layouts/app.blade.php) run now.
document.dispatchEvent(new Event("cp:bootstrap-ready"));
