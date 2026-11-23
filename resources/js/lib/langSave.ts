import { normaliseLang, storedLang, type Lang } from "@/lib/i18n";
import { readPref, removePref, writePref } from "@/lib/storage";

/*
 * Saving a member's language on their account (POST langSaveUrl, Member\LanguageController), so it follows them to
 * another device and their emails use it. This POST, made by the signed-in member's own switch, is the only way an
 * account's language is written. The switch changes the page at once; the save runs in the background.
 *
 * Until the server has it, the choice is kept here as "<user id>:<code>:<time chosen, ms>" (UNSAVED_KEY): the next
 * page load prefers it over the server's answer and sends it again (also when the connection comes back), so a
 * choice made offline or during a failed request is not lost. It is keyed by user id, so one member's unsent choice
 * never applies to another member signing in on the same browser, and it expires UNSAVED_TTL_MS (24 hours) after it
 * was made: by then the account's own value (perhaps changed on another device since) wins. Resending keeps the
 * original time, so a choice that never gets through is not renewed by every page load. A marker without a time
 * (from before the expiry) counts as expired.
 */

const UNSAVED_KEY = "cp-lang-unsaved";

/** How long an unsent choice is kept and retried: 24 hours. */
export const UNSAVED_TTL_MS = 24 * 60 * 60 * 1000;

type Unsaved = { userId: string; lang: Lang; at: number; raw: string };

function readUnsaved(now: number): Unsaved | null {
  const raw = readPref(UNSAVED_KEY);
  if (!raw) return null;
  const [userId, code, at] = raw.split(":");
  const lang = normaliseLang(code);
  const time = Number(at);
  // Expired, from the future (clock changed), unreadable or in the old format: dropped.
  if (!lang || !userId || !at || !Number.isFinite(time) || now - time >= UNSAVED_TTL_MS || time - now > UNSAVED_TTL_MS) {
    removePref(UNSAVED_KEY);
    return null;
  }
  return { userId, lang, at: time, raw };
}

/** A choice made in this browser by this user, less than 24 hours ago, that the server has not confirmed yet. */
export function unsavedLang(userId: number, now: number = Date.now()): Lang | null {
  const pending = readUnsaved(now);
  return pending && pending.userId === String(userId) ? pending.lang : null;
}

function csrfHeaders(): Record<string, string> {
  // Laravel's XSRF-TOKEN cookie follows the session; the page's meta tag is the fallback.
  const cookie = document.cookie.split("; ").find((c) => c.startsWith("XSRF-TOKEN="));
  if (cookie) return { "X-XSRF-TOKEN": decodeURIComponent(cookie.slice("XSRF-TOKEN=".length)) };
  const meta = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;
  return meta ? { "X-CSRF-TOKEN": meta } : {};
}

/** True once the server has answered for good (saved, or refused the value: retrying would not help). */
async function post(url: string, lang: Lang): Promise<boolean> {
  try {
    const res = await fetch(url, {
      method: "POST",
      credentials: "same-origin",
      // A signed-out session answers with a redirect to the sign-in page: not a save, so not followed.
      redirect: "manual",
      headers: { "Content-Type": "application/json", Accept: "application/json", "X-Requested-With": "XMLHttpRequest", ...csrfHeaders() },
      body: JSON.stringify({ lang }),
    });
    return res.ok || res.status === 422;
  } catch {
    return false; // offline or the request failed: kept for the next try
  }
}

// One save at a time, in order, so a quick BM-EN-BM ends with the last choice saved.
let queue: Promise<void> = Promise.resolve();

/** Send the kept choice; cleared once the server has it (unless a newer choice came since). */
function send(url: string, lang: Lang, marker: string) {
  queue = queue.then(async () => {
    if ((await post(url, lang)) && readPref(UNSAVED_KEY) === marker) removePref(UNSAVED_KEY);
  });
}

/** The switch: remember the new choice as unsaved (timed now) and send it. */
export function saveLang(url: string, userId: number, lang: Lang) {
  const marker = `${userId}:${storedLang(lang)}:${Date.now()}`;
  writePref(UNSAVED_KEY, marker);
  send(url, lang, marker);
}

/** Send again this user's choice that did not get through, keeping the time it was made (so it still expires). */
export function resendUnsaved(url: string, userId: number) {
  const pending = readUnsaved(Date.now());
  if (pending && pending.userId === String(userId)) send(url, pending.lang, pending.raw);
}
