/*
 * Club Portal service worker (Option A, step A4).
 *
 * Deliberately minimal:
 *  - cache-first ONLY for immutable static assets: /build/assets/* (Vite, content-hashed), /icons/* and the
 *    app icons made from the portal logo, /storage/portal/icons/* (hashed names: a new logo or colour = new URL);
 *  - the manifest (/app.webmanifest, portal name and colour) is never cached here: the browser fetches it;
 *  - everything else (HTML, Inertia JSON, attachments, payments, webhooks, cross-origin) goes to the
 *    network and is never stored — no money data is ever kept offline;
 *  - a failed top-level navigation shows /offline.html (BM + EN), cached at install.
 *
 * Bump CACHE_VERSION on every release (docs/RELEASE.md); activate deletes the old caches.
 */
const CACHE_VERSION = "v4";
const STATIC_CACHE = `cp-static-${CACHE_VERSION}`;
const OFFLINE_CACHE = `cp-offline-${CACHE_VERSION}`;
const OFFLINE_URL = "/offline.html";
const OFFLINE_ICON = "/icons/icon-192.png";

self.addEventListener("install", (event) => {
  event.waitUntil(
    caches
      .open(OFFLINE_CACHE)
      // The offline page shows the app icon, so store both; the device may never have loaded the icon.
      .then((cache) =>
        cache.addAll([new Request(OFFLINE_URL, { cache: "reload" }), new Request(OFFLINE_ICON, { cache: "reload" })]),
      )
      .then(() => self.skipWaiting()),
  );
});

self.addEventListener("activate", (event) => {
  const keep = new Set([STATIC_CACHE, OFFLINE_CACHE]);
  event.waitUntil(
    caches
      .keys()
      .then((keys) => Promise.all(keys.filter((k) => k.startsWith("cp-") && !keep.has(k)).map((k) => caches.delete(k))))
      .then(() => self.clients.claim()),
  );
});

function isStaticAsset(url) {
  return (
    url.origin === self.location.origin &&
    (url.pathname.startsWith("/build/assets/") || url.pathname.startsWith("/icons/") || url.pathname.startsWith("/storage/portal/icons/"))
  );
}

async function cacheFirst(event, request) {
  const cache = await caches.open(STATIC_CACHE);
  const hit = (await cache.match(request)) ?? (await caches.match(request, { cacheName: OFFLINE_CACHE }));
  if (hit) return hit;
  const response = await fetch(request);
  // Only store complete, same-origin, successful responses.
  if (response.ok && response.status === 200 && response.type === "basic") {
    // Keep the worker alive until the write finishes; a failed write must not surface as an error.
    event.waitUntil(cache.put(request, response.clone()).catch(() => {}));
  }
  return response;
}

self.addEventListener("fetch", (event) => {
  const request = event.request;
  if (request.method !== "GET") return; // POST/PUT etc. (payments, forms): untouched.

  const url = new URL(request.url);

  if (isStaticAsset(url)) {
    event.respondWith(cacheFirst(event, request));
    return;
  }

  // Top-level page loads: network only; the offline page only when the network fails.
  // Inertia visits are XHR (mode "cors"/"same-origin"), not navigations, so they never get it.
  if (request.mode === "navigate") {
    event.respondWith(
      fetch(request).catch(async () => (await caches.match(OFFLINE_URL, { cacheName: OFFLINE_CACHE })) ?? Response.error()),
    );
  }
  // Anything else: no respondWith, so the browser fetches from the network as usual.
});
