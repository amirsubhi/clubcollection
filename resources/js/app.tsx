import "../css/progress.css";
import { StrictMode, useEffect, useState, type ReactNode } from "react";
import { createRoot } from "react-dom/client";
import { createInertiaApp, router } from "@inertiajs/react";
import { setNonce } from "get-nonce";
import { TooltipProvider } from "@/components/ui/tooltip";
import { LangContext, PageLangContext, type Lang } from "@/lib/i18n";
import { captureInstallPrompt, registerServiceWorker } from "@/lib/pwa";
import { readPref, writePref } from "@/lib/storage";
import { bootTheme, ThemeProvider } from "@/lib/theme";

// Radix (react-remove-scroll) injects a <style> while dialogs and menus are open; give it the CSP nonce.
// Browsers hide the nonce attribute value from getAttribute, so read the .nonce property.
const nonce = document.querySelector<HTMLMetaElement>('meta[property="csp-nonce"]')?.nonce;
if (nonce) setNonce(nonce);

// Apply the saved theme (system / light / dark via data-theme on <html>) before the first render.
bootTheme();

// PWA: service worker (production + secure origin only) and the Chrome install event for the member home prompt.
registerServiceWorker();
captureInstallPrompt();

/** Laravel locale ("ms", "en", ...) to the UI language; a saved choice wins. */
function initialLang(locale: unknown): Lang {
  const saved = readPref("cp-lang");
  if (saved === "bm" || saved === "en") return saved;
  return typeof locale === "string" && locale.toLowerCase().startsWith("en") ? "en" : "bm";
}

/** Member pages follow the BM/EN toggle; admin (English) and other pages follow the server locale. */
function htmlLang(component: string, locale: unknown, lang: Lang): "ms" | "en" {
  if (component.startsWith("Member/")) return lang === "en" ? "en" : "ms";
  return typeof locale === "string" && locale.toLowerCase().startsWith("en") ? "en" : "ms";
}

/** Shared with the sign-in pages (AuthPageLocale reads it), so they open in the language last chosen here. */
function writeLangCookie(l: Lang) {
  const secure = window.location.protocol === "https:" ? "; Secure" : "";
  document.cookie = `cp_lang=${l}; Path=/; Max-Age=31536000; SameSite=Lax${secure}`;
}

function Providers({ locale, component, children }: { locale: unknown; component: string; children: ReactNode }) {
  const [lang, setLangState] = useState<Lang>(() => initialLang(locale));
  const [page, setPage] = useState({ component, locale });
  const setLang = (l: Lang) => {
    setLangState(l);
    writePref("cp-lang", l);
    writeLangCookie(l);
  };
  // Track the current page so <html lang> matches what is on screen after Inertia visits.
  useEffect(() => router.on("navigate", (e) => setPage({ component: e.detail.page.component, locale: e.detail.page.props.locale })), []);
  const pageLang = htmlLang(page.component, page.locale, lang);
  useEffect(() => {
    document.documentElement.lang = pageLang;
  }, [pageLang]);
  // A choice saved before the cookie existed: share it once on load.
  useEffect(() => {
    const saved = readPref("cp-lang");
    if (saved === "bm" || saved === "en") writeLangCookie(saved);
  }, []);
  return (
    <ThemeProvider>
      <LangContext.Provider value={{ lang, setLang }}>
        <PageLangContext.Provider value={pageLang}>
          <TooltipProvider delayDuration={200}>
            <div className="app-shell">{children}</div>
          </TooltipProvider>
        </PageLangContext.Provider>
      </LangContext.Provider>
    </ThemeProvider>
  );
}

createInertiaApp({
  id: "root",
  title: (title) => (title ? `${title} · Club Portal` : "Club Portal"),
  resolve: (name) => {
    const pages = import.meta.glob("./Pages/**/*.tsx");
    const page = pages[`./Pages/${name}.tsx`];
    if (!page) throw new Error(`Inertia page not found: ${name}`);
    return page();
  },
  setup({ el, App, props }) {
    createRoot(el).render(
      <StrictMode>
        <Providers locale={props.initialPage.props.locale} component={props.initialPage.component}>
          <App {...props} />
        </Providers>
      </StrictMode>,
    );
  },
  // Styles live in resources/css/progress.css: an injected <style> would be blocked by the CSP.
  progress: { includeCSS: false, showSpinner: false },
});
