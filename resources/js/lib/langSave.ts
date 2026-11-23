import { normaliseLang, storedLang, type Lang } from "@/lib/i18n";
import { readPref, removePref, writePref } from "@/lib/storage";

/*
 * Saving a member's language on their account (POST langSaveUrl, Member\LanguageController), so it follows them to
 * another device and their emails use it. The switch changes the page at once; the save runs in the background.
 * Until the server has it, the choice is kept here as "<user id>:<code>" (UNSAVED_KEY): the next page load prefers
 * it over the server's answer and sends it again, so a choice made offline or during a failed request is not lost
 * (and one member's unsent choice never applies to another member signing in on the same browser).
 */

const UNSAVED_KEY = "cp-lang-unsaved";

/** A choice made in this browser by this user that the server has not confirmed yet. */
export function unsavedLang(userId: number): Lang | null {
  const value = readPref(UNSAVED_KEY);
  if (!value) return null;
  const sep = value.indexOf(":");
  return value.slice(0, sep) === String(userId) ? normaliseLang(value.slice(sep + 1)) : null;
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

/** Remember the choice as unsaved and send it; cleared once the server has it (unless a newer choice came since). */
export function saveLang(url: string, userId: number, lang: Lang) {
  const marker = `${userId}:${storedLang(lang)}`;
  writePref(UNSAVED_KEY, marker);
  queue = queue.then(async () => {
    if ((await post(url, lang)) && readPref(UNSAVED_KEY) === marker) removePref(UNSAVED_KEY);
  });
}
