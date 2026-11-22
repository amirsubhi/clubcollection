import { createContext, useContext, useEffect, useState, type ReactNode } from "react";
import { readPref, writePref } from "./storage";

export type ThemePref = "system" | "light" | "dark";
const KEY = "cp-theme";

function initial(): ThemePref {
  const v = readPref(KEY);
  return v === "light" || v === "dark" ? v : "system";
}

/**
 * The PWA theme-color metas follow a forced theme; with "system" each keeps its own media query's colour.
 * The colours (the portal's brand colour in light) come from the server: partials/pwa-head.blade.php defines
 * window.cpThemeColor, which reads data-theme, so this needs no rebuild when the brand colour changes.
 */
function applyThemeColor() {
  window.cpThemeColor?.();
}

/** "system" removes data-theme so the prefers-color-scheme media query decides. */
export function applyTheme(pref: ThemePref) {
  const el = document.documentElement;
  if (pref === "system") el.removeAttribute("data-theme");
  else el.setAttribute("data-theme", pref);
  applyThemeColor();
}

/** Call before first render so the page never flashes the wrong theme. */
export function bootTheme() {
  applyTheme(initial());
}

const ThemeContext = createContext<{ theme: ThemePref; setTheme: (t: ThemePref) => void }>({ theme: "system", setTheme: () => undefined });

export function ThemeProvider({ children }: { children: ReactNode }) {
  const [theme, setThemeState] = useState<ThemePref>(initial);
  useEffect(() => applyTheme(theme), [theme]);
  const setTheme = (t: ThemePref) => {
    setThemeState(t);
    writePref(KEY, t);
  };
  return <ThemeContext.Provider value={{ theme, setTheme }}>{children}</ThemeContext.Provider>;
}

export function useTheme() {
  return useContext(ThemeContext);
}
