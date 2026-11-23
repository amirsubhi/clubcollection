/** Browser storage is optional: every access is wrapped so the app still works when storage is blocked (private mode, previews). */
export function readPref(key: string): string | null {
  try {
    return window.localStorage.getItem(key);
  } catch {
    return null;
  }
}

export function writePref(key: string, value: string) {
  try {
    window.localStorage.setItem(key, value);
  } catch {
    /* ignore */
  }
}

export function removePref(key: string) {
  try {
    window.localStorage.removeItem(key);
  } catch {
    /* ignore */
  }
}
