import "../css/progress.css";
import { StrictMode, useState, type ReactNode } from "react";
import { createRoot } from "react-dom/client";
import { createInertiaApp } from "@inertiajs/react";
import { setNonce } from "get-nonce";
import { TooltipProvider } from "@/components/ui/tooltip";
import { LangContext, type Lang } from "@/lib/i18n";
import { readPref, writePref } from "@/lib/storage";
import { bootTheme, ThemeProvider } from "@/lib/theme";

// Radix (react-remove-scroll) injects a <style> while dialogs and menus are open; give it the CSP nonce.
// Browsers hide the nonce attribute value from getAttribute, so read the .nonce property.
const nonce = document.querySelector<HTMLMetaElement>('meta[property="csp-nonce"]')?.nonce;
if (nonce) setNonce(nonce);

// Apply the saved theme (system / light / dark via data-theme on <html>) before the first render.
bootTheme();

/** Laravel locale ("ms", "en", ...) to the UI language; a saved choice wins. */
function initialLang(locale: unknown): Lang {
  const saved = readPref("cp-lang");
  if (saved === "bm" || saved === "en") return saved;
  return typeof locale === "string" && locale.toLowerCase().startsWith("en") ? "en" : "bm";
}

function Providers({ locale, children }: { locale: unknown; children: ReactNode }) {
  const [lang, setLangState] = useState<Lang>(() => initialLang(locale));
  const setLang = (l: Lang) => {
    setLangState(l);
    writePref("cp-lang", l);
  };
  return (
    <ThemeProvider>
      <LangContext.Provider value={{ lang, setLang }}>
        <TooltipProvider delayDuration={200}>
          <div className="app-shell">{children}</div>
        </TooltipProvider>
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
        <Providers locale={props.initialPage.props.locale}>
          <App {...props} />
        </Providers>
      </StrictMode>,
    );
  },
  // Styles live in resources/css/progress.css: an injected <style> would be blocked by the CSP.
  progress: { includeCSS: false, showSpinner: false },
});
