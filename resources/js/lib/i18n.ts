import { createContext, useContext } from "react";
import en from "../../../lang/member/en.json";

/*
 * The member app's text, one JSON file per language in lang/member/ (bundled by Vite: no fetch, so the service
 * worker and the CSP need nothing). Keys are short names, not sentences: they must never go through Laravel's __(),
 * which shows the raw key when a JSON file lacks it. English is the reference file: it has every key (DictKey comes
 * from it, so tsc catches a wrong key) and a key missing from another language falls back to it.
 *
 * Each file describes itself in "_meta" (name, label, htmlLang, localeTag, beta), so adding a language is adding
 * lang/member/<code>.json and nothing in code: the list below is the folder (import.meta.glob), and the PHP side
 * (App\Support\UiLang::available()) reads the same folder with the same file-name rule and order.
 * tests/Unit/MemberLangFilesTest.php checks every file has _meta, en.json's keys and {placeholders};
 * tests/Unit/UiLangTest.php checks the two lists are built the same way. See docs/i18n-translator-brief.md.
 */

/** A language file's own description ("_meta" in lang/member/<code>.json). */
export type LangMeta = {
  /** The language's own name, as its speakers write it ("Bahasa Melayu"); the picker lists it. */
  name: string;
  /** Short code shown on the closed picker ("BM"). */
  label: string;
  /** The <html lang> / lang="" value (BCP 47), e.g. "zh-Hans" so browsers pick Chinese, not Japanese, glyphs. */
  htmlLang: string;
  /** BCP 47 tag for the browser's own formatting (plural rules, times, date inputs), e.g. "ms-MY". */
  localeTag: string;
  /** Shown as "(beta)" in the picker: a first translation, not yet checked by a native speaker. */
  beta: boolean;
};

/** English holds every key; this is the type of a key. */
export type DictKey = Exclude<keyof typeof en, "_meta">;
type Strings = Record<DictKey, string>;
type LangFile = Partial<Strings> & { _meta?: Partial<LangMeta> };

/** The same rule as UiLang::available(): a file name like "ms", "en", "zh" or "pt_BR". */
const CODE = /^[a-z]{2,3}(_[A-Z]{2})?$/;

/** Every lang/member/*.json, by code. */
const FILES: Record<string, LangFile> = Object.fromEntries(
  Object.entries(import.meta.glob<LangFile>("../../../lang/member/*.json", { eager: true, import: "default" }))
    .map(([path, file]) => [path.replace(/^.*\/([^/]+)\.json$/, "$1"), file] as const)
    .filter(([code]) => CODE.test(code)),
);

/** A member app language: the code of its lang/member/<code>.json file (also the Laravel locale). */
export type Lang = string;
/** Malay unless the member chose otherwise. */
export const DEFAULT_LANG: Lang = "ms";
const REFERENCE_LANG: Lang = "en";

/** The available languages: the default first, then the rest by code (as UiLang::available()). */
export const LANGS: Lang[] = [...new Set([DEFAULT_LANG, ...Object.keys(FILES).sort()])].filter((l) => l in FILES);

/** A file's text without its _meta. */
function stringsOf(file: LangFile): Record<string, string> {
  const strings: Record<string, string> = {};
  for (const [k, v] of Object.entries(file)) if (k !== "_meta" && typeof v === "string") strings[k] = v;
  return strings;
}

/** Each language merged over English, so a missing key shows the English text rather than nothing. */
const ENGLISH = stringsOf(en as LangFile);
const DICTS: Record<Lang, Record<string, string>> = Object.fromEntries(LANGS.map((l) => [l, { ...ENGLISH, ...stringsOf(FILES[l]) }]));

function dict(lang: Lang): Record<string, string> {
  return DICTS[lang] ?? DICTS[REFERENCE_LANG];
}

/** A language's _meta. Never falls back to English's: a file without it shows its code. */
export function langMeta(lang: Lang): LangMeta {
  const m = FILES[lang]?._meta ?? {};
  return {
    name: m.name ?? lang,
    label: m.label ?? lang.toUpperCase(),
    htmlLang: m.htmlLang ?? lang.replace("_", "-"),
    localeTag: m.localeTag ?? lang.replace("_", "-"),
    beta: m.beta === true,
  };
}

/** BCP 47 tag for the browser's own formatting (date inputs, times, plural rules). */
export function localeTag(lang: Lang): string {
  return langMeta(lang).localeTag;
}

/** The <html lang> value for a language. */
export function htmlLangOf(lang: Lang): string {
  return langMeta(lang).htmlLang;
}

/**
 * Any language value from outside (cookie cp_lang, localStorage cp-lang, ?lang=, a server locale) to a Lang, or null
 * when it isn't one. "bm" is the older code for Malay and is still what gets stored (see storedLang).
 */
export function normaliseLang(value: unknown): Lang | null {
  if (typeof value !== "string") return null;
  const code = value === "bm" ? "ms" : value;
  return LANGS.includes(code) ? code : null;
}

/**
 * The value written to cp_lang / cp-lang. Malay is still stored as "bm", the code that pages and sign-in screens
 * from before the language files only know (AuthPageLocale writes the same), so a tab left open keeps working.
 */
export function storedLang(lang: Lang): string {
  return lang === "ms" ? "bm" : lang;
}

/** A Laravel locale ("ms", "en", "zh", "en_US", ...) to the UI language: its own when it has a file, else Malay. */
export function langFromLocale(locale: unknown): Lang {
  const own = normaliseLang(locale);
  if (own) return own;
  return typeof locale === "string" && locale.toLowerCase().startsWith("en") ? REFERENCE_LANG : DEFAULT_LANG;
}

export const LangContext = createContext<{ lang: Lang; setLang: (l: Lang) => void }>({
  lang: DEFAULT_LANG,
  setLang: () => undefined,
});

/** A dictionary entry in a given language, whatever the page shows (e.g. the BM WhatsApp help message). */
export function textIn(key: DictKey, lang: Lang): string {
  return dict(lang)[key];
}

/** A lookup bound to one language, for components that take a `lang` prop. */
export function textsIn(lang: Lang): (key: DictKey) => string {
  const d = dict(lang);
  return (key) => d[key];
}

export function useT() {
  const { lang } = useContext(LangContext);
  return textsIn(lang);
}

/** A dictionary entry in every language, for text built outside a component (e.g. the "Others" slice). */
export function allLangs(key: DictKey): LocalText {
  return { ...Object.fromEntries(LANGS.map((l) => [l, dict(l)[key]])), en: dict(REFERENCE_LANG)[key] };
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
  const d = dict(lang);
  return d[`month_${month}`] ?? d.month_1;
}

/** A month's short name (1 = Jan) for dates such as "5 Okt 2026". */
export function monthShort(month: number, lang: Lang): string {
  return dict(lang)[`monthShort_${month}`];
}

/** Replace "{name}" placeholders in a translated string. */
export function fill(text: string, vars: Record<string, string | number>): string {
  return text.replace(/\{(\w+)\}/g, (m, k: string) => (k in vars ? String(vars[k]) : m));
}

export function isDictKey(key: string): key is DictKey {
  return key !== "_meta" && Object.prototype.hasOwnProperty.call(en, key);
}

/**
 * A text with a count: the key's base name, e.g. "feesN" for feesN_one / feesN_other. Every file has both; a
 * language whose grammar has more forms adds them (feesN_few, feesN_many, feesN_two, feesN_zero), chosen by the
 * browser's CLDR plural rules for the language's localeTag. A missing form falls back to _other.
 */
export type CountKey = { [K in DictKey]: K extends `${infer B}_one` ? B : never }[DictKey];

const pluralRules = new Map<Lang, Intl.PluralRules>();

/** The plural form ("one", "other", "few", ...) a language uses for n. */
export function pluralForm(lang: Lang, n: number): Intl.LDMLPluralRule {
  let rules = pluralRules.get(lang);
  if (!rules) {
    rules = new Intl.PluralRules(localeTag(lang));
    pluralRules.set(lang, rules);
  }
  return rules.select(n);
}

/** The text for a count, with {n} (and any other placeholders) filled in: countText(lang, "feesN", 3). */
export function countText(lang: Lang, base: CountKey, n: number, vars: Record<string, string | number> = {}): string {
  const d = dict(lang);
  const text = d[`${base}_${pluralForm(lang, n)}`] ?? d[`${base}_other`];
  return fill(text, { n, ...vars });
}

/** countText bound to the member's language. */
export function useCountText() {
  const { lang } = useContext(LangContext);
  return (base: CountKey, n: number, vars?: Record<string, string | number>) => countText(lang, base, n, vars);
}

/**
 * The language of the page on screen, as set on <html lang>: member pages follow the language picker, admin pages
 * the server locale. Shared UI parts (dialog "Close", uploader defaults) read it so they match the page.
 */
export const PageLangContext = createContext<Lang>(REFERENCE_LANG);

export function usePageLang() {
  return useContext(PageLangContext);
}

export function useLang() {
  return useContext(LangContext);
}
