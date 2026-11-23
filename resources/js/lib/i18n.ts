import { createContext, useContext } from "react";
import en from "../../../lang/member/en.json";
import ms from "../../../lang/member/ms.json";

/*
 * The member app's text, one JSON file per language in lang/member/ (bundled by Vite: no fetch, so the service
 * worker and the CSP need nothing). Keys are short names, not sentences: they must never go through Laravel's __(),
 * which shows the raw key when a JSON file lacks it. English is the reference file: it has every key (DictKey comes
 * from it, so tsc catches a wrong key) and a key missing from another language falls back to it.
 * tests/Feature/MemberLangFilesTest.php checks that every file has the same keys and {placeholders} as en.json.
 *
 * To add a language: lang/member/<code>.json, an import and an entry in FILES below, its LABELS and LOCALE_TAGS
 * entry (tsc asks for both), plus Laravel's lang/<code>.json and lang/<code>/{auth,passwords,validation}.php.
 */

/** English holds every key; this is the type of a key. */
export type DictKey = keyof typeof en;
type Strings = Record<DictKey, string>;

const FILES = { ms, en } satisfies Record<string, Partial<Strings>>;

/** A member app language: its code is the file name and the <html lang> value. */
export type Lang = keyof typeof FILES;
export const LANGS = Object.keys(FILES) as Lang[];
/** Malay unless the member chose otherwise. */
export const DEFAULT_LANG: Lang = "ms";

/** Each language merged over English, so a missing key shows the English text rather than nothing. */
const DICTS = Object.fromEntries(LANGS.map((l) => [l, { ...en, ...FILES[l] }])) as Record<Lang, Strings>;

/** What the BM/EN switch shows for each language. */
export const LANG_LABELS: Record<Lang, string> = { ms: "BM", en: "EN" };

/** BCP 47 tag for the browser's own formatting (date inputs, times). */
const LOCALE_TAGS: Record<Lang, string> = { ms: "ms-MY", en: "en-MY" };

export function localeTag(lang: Lang): string {
  return LOCALE_TAGS[lang];
}

/**
 * Any language value from outside (cookie cp_lang, localStorage cp-lang, ?lang=, a server locale) to a Lang, or null
 * when it isn't one. "bm" is the older code for Malay and is still what gets stored (see storedLang).
 */
export function normaliseLang(value: unknown): Lang | null {
  if (typeof value !== "string") return null;
  const code = value === "bm" ? "ms" : value;
  return (LANGS as string[]).includes(code) ? (code as Lang) : null;
}

/**
 * The value written to cp_lang / cp-lang. Malay is still stored as "bm", the code that pages and sign-in screens
 * from before the language files only know (AuthPageLocale writes the same), so a tab left open keeps working.
 */
export function storedLang(lang: Lang): string {
  return lang === "ms" ? "bm" : lang;
}

/** A Laravel locale ("ms", "en", "en_US", ...) to the UI language: English when it is English, else Malay. */
export function langFromLocale(locale: unknown): Lang {
  return typeof locale === "string" && locale.toLowerCase().startsWith("en") ? "en" : DEFAULT_LANG;
}

export const LangContext = createContext<{ lang: Lang; setLang: (l: Lang) => void }>({
  lang: DEFAULT_LANG,
  setLang: () => undefined,
});

/** A dictionary entry in a given language, whatever the page shows (e.g. the BM WhatsApp help message). */
export function textIn(key: DictKey, lang: Lang): string {
  return DICTS[lang][key];
}

/** A lookup bound to one language, for components that take a `lang` prop. */
export function textsIn(lang: Lang): (key: DictKey) => string {
  return (key) => DICTS[lang][key];
}

export function useT() {
  const { lang } = useContext(LangContext);
  return textsIn(lang);
}

/** A dictionary entry in every language, for text built outside a component (e.g. the "Others" slice). */
export function allLangs(key: DictKey): Record<Lang, string> {
  return Object.fromEntries(LANGS.map((l) => [l, DICTS[l][key]])) as Record<Lang, string>;
}

/** Text that comes with the data in some languages: English always, the others when known. */
export type LocalText = { en: string } & Partial<Record<Lang, string | null | undefined>>;

/**
 * Text that comes with the data in fixed languages (a category's Malay and English names, a fee's BM description):
 * the one for `lang`, else the English.
 */
export function localText(lang: Lang, texts: LocalText): string {
  return texts[lang] ?? texts.en;
}

/** A month's name (1 = January) in the member's language. */
export function monthName(month: number, lang: Lang): string {
  const key = `month_${month}`;
  return isDictKey(key) ? DICTS[lang][key] : DICTS[lang].month_1;
}

/** A month's short name (1 = Jan) for dates such as "5 Okt 2026". */
export function monthShort(month: number, lang: Lang): string {
  return DICTS[lang][`monthShort_${month}` as DictKey];
}

/** Replace "{name}" placeholders in a translated string. */
export function fill(text: string, vars: Record<string, string | number>): string {
  return text.replace(/\{(\w+)\}/g, (m, k: string) => (k in vars ? String(vars[k]) : m));
}

export function isDictKey(key: string): key is DictKey {
  return Object.prototype.hasOwnProperty.call(en, key);
}

/**
 * The language of the page on screen, as set on <html lang>: member pages follow the BM/EN toggle, admin pages the
 * server locale. Shared UI parts (dialog "Close", uploader defaults) read it so they match the page.
 */
export const PageLangContext = createContext<Lang>("en");

export function usePageLang() {
  return useContext(PageLangContext);
}

export function useLang() {
  return useContext(LangContext);
}
