/**
 * PWA helpers (Option A, step A4): service-worker registration and the "Add to home screen" prompt.
 * public/sw.js caches static assets only; pages and money data always come from the network.
 */

/** Chrome/Android's install event (not yet in lib.dom). */
export interface BeforeInstallPromptEvent extends Event {
  prompt: () => Promise<void>;
  userChoice: Promise<{ outcome: "accepted" | "dismissed" }>;
}

let deferred: BeforeInstallPromptEvent | null = null;
const listeners = new Set<() => void>();
const notify = () => listeners.forEach((l) => l());

/** Register /sw.js in production builds on secure origins (https or localhost) only. */
export function registerServiceWorker() {
  if (!import.meta.env.PROD || !window.isSecureContext || !("serviceWorker" in navigator)) return;
  window.addEventListener("load", () => {
    navigator.serviceWorker.register("/sw.js", { scope: "/" }).catch(() => {
      /* Not fatal: the site works without it. */
    });
  });
}

/** Start listening before React mounts: the event can fire before the member home renders. */
export function captureInstallPrompt() {
  window.addEventListener("beforeinstallprompt", (e) => {
    e.preventDefault(); // keep Chrome's mini-infobar quiet; we show our own prompt
    deferred = e as BeforeInstallPromptEvent;
    notify();
  });
  window.addEventListener("appinstalled", () => {
    deferred = null;
    notify();
  });
}

export function getInstallPrompt() {
  return deferred;
}

export function subscribeInstallPrompt(cb: () => void) {
  listeners.add(cb);
  return () => {
    listeners.delete(cb);
  };
}

/** Show Chrome's install dialog; the event can only be used once. */
export async function promptInstall(): Promise<boolean> {
  const e = deferred;
  if (!e) return false;
  deferred = null;
  notify();
  await e.prompt();
  const { outcome } = await e.userChoice;
  return outcome === "accepted";
}

export function isStandalone(): boolean {
  const nav = navigator as Navigator & { standalone?: boolean };
  return window.matchMedia?.("(display-mode: standalone)").matches === true || nav.standalone === true;
}

/** iPhone/iPad (iPadOS reports as Mac with touch). Safari, and Chrome/Edge on iOS 16.4+, add to the home screen via the Share menu. */
export function isIosSafari(): boolean {
  const ua = navigator.userAgent;
  const ios = /iPad|iPhone|iPod/.test(ua) || (navigator.platform === "MacIntel" && navigator.maxTouchPoints > 1);
  return ios && /Safari/.test(ua) && !/FxiOS|OPiOS/.test(ua);
}
