import "../css/progress.css";
import { StrictMode, useEffect, useState, type ReactNode } from "react";
import { createRoot } from "react-dom/client";
import { createInertiaApp, router } from "@inertiajs/react";
import { setNonce } from "get-nonce";
import { DEFAULT_LANG, LangContext, PageLangContext, htmlLangOf, langFromLocale, normaliseLang, storedLang, type Lang } from "@/lib/i18n";
import { resendUnsaved, saveLang, unsavedLang } from "@/lib/langSave";
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

/** A signed-in member, whose language is saved on the account (props.langSave, shared for members only). */
type Account = { id: number; saveUrl: string; saved: boolean } | null;

/**
 * The language the app opens in. A signed-in member: their own choice made here that has not reached the server yet
 * (keyed by their user id, less than 24 hours old: lib/langSave.ts), else the server's answer (UserLocale: the
 * account's saved language, else the portal default). Never what this browser stored (cp-lang / cp_lang): on a
 * shared browser that may be someone else's choice. Others (admins on member pages): the choice stored in this
 * browser, else the server's answer.
 */
function initialLang(locale: unknown, account: Account): Lang {
  if (account) return unsavedLang(account.id) ?? normaliseLang(locale) ?? DEFAULT_LANG;
  return normaliseLang(readPref("cp-lang")) ?? langFromLocale(locale);
}

/** Member pages follow the language picker; admin (English) and other pages follow the server locale. */
function htmlLang(component: string, locale: unknown, lang: Lang): Lang {
  return component.startsWith("Member/") ? lang : langFromLocale(locale);
}

/** Shared with the sign-in pages (AuthPageLocale reads it), so they open in the language last chosen here. */
function writeLangCookie(l: Lang) {
  const secure = window.location.protocol === "https:" ? "; Secure" : "";
  document.cookie = `cp_lang=${storedLang(l)}; Path=/; Max-Age=31536000; SameSite=Lax${secure}`;
}

/** This browser's copies of the choice: cp-lang (member app, sign-in pages) and the cp_lang cookie (server). */
function rememberLang(l: Lang) {
  writePref("cp-lang", storedLang(l));
  writeLangCookie(l);
}

function Providers({ locale, account, component, children }: { locale: unknown; account: Account; component: string; children: ReactNode }) {
  const [lang, setLangState] = useState<Lang>(() => initialLang(locale, account));
  const [page, setPage] = useState({ component, locale });
  // The switch: the page changes at once, the account is updated in the background (kept and retried if offline).
  const setLang = (l: Lang) => {
    setLangState(l);
    rememberLang(l);
    if (account) saveLang(account.saveUrl, account.id, l);
  };
  // Track the current page so <html lang> matches what is on screen after Inertia visits.
  useEffect(() => router.on("navigate", (e) => setPage({ component: e.detail.page.component, locale: e.detail.page.props.locale })), []);
  const pageLang = htmlLang(page.component, page.locale, lang);
  useEffect(() => {
    document.documentElement.lang = htmlLangOf(pageLang);
  }, [pageLang]);
  useEffect(() => {
    if (account) {
      // Nothing this browser holds is saved on the account on load: only the member's own switch saves (setLang).
      // This browser's copies (read by the sign-in pages after signing out) follow the account's own language; with
      // nothing saved they are left as they are, so the portal default is never written down as a choice.
      if (account.saved || unsavedLang(account.id)) rememberLang(lang);
      // Send this member's own choice that did not reach the server: now, and again when the connection comes back.
      // It keeps the time it was made, so it expires 24 hours later however often it is resent.
      const retry = () => resendUnsaved(account.saveUrl, account.id);
      retry();
      window.addEventListener("online", retry);
      return () => window.removeEventListener("online", retry);
    }
    // A choice saved before the cookie existed: share it once on load.
    const saved = normaliseLang(readPref("cp-lang"));
    if (saved) writeLangCookie(saved);
    return undefined;
    // Once, on load: later switches save themselves.
  }, []);
  return (
    <ThemeProvider>
      <LangContext.Provider value={{ lang, setLang }}>
        <PageLangContext.Provider value={pageLang}>
          <div className="app-shell">{children}</div>
        </PageLangContext.Provider>
      </LangContext.Provider>
    </ThemeProvider>
  );
}

/** The portal name (Portal settings, shared as props.portal); read once from the first page. */
let portalName = "Club Portal";

createInertiaApp({
  id: "root",
  title: (title) => (title ? `${title} · ${portalName}` : portalName),
  resolve: (name) => {
    const pages = import.meta.glob("./Pages/**/*.tsx");
    const page = pages[`./Pages/${name}.tsx`];
    if (!page) throw new Error(`Inertia page not found: ${name}`);
    return page();
  },
  setup({ el, App, props }) {
    portalName = props.initialPage.props.portal?.name || portalName;
    const { auth, langSave } = props.initialPage.props;
    const account: Account = auth?.user && langSave ? { id: auth.user.id, saveUrl: langSave.url, saved: langSave.saved } : null;
    createRoot(el).render(
      <StrictMode>
        <Providers locale={props.initialPage.props.locale} account={account} component={props.initialPage.component}>
          <App {...props} />
        </Providers>
      </StrictMode>,
    );
  },
  // Styles live in resources/css/progress.css: an injected <style> would be blocked by the CSP.
  progress: { includeCSS: false, showSpinner: false },
});
