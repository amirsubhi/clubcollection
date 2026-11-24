import { fill, monthShort, textIn, type Lang } from "@/lib/i18n";

/** Amounts are stored as integer sen to avoid float rounding. */
export function rm(sen: number): string {
  const neg = sen < 0;
  const abs = Math.abs(Math.round(sen));
  const ringgit = Math.floor(abs / 100);
  const cents = String(abs % 100).padStart(2, "0");
  const grouped = ringgit.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ",");
  return `${neg ? "(" : ""}RM ${grouped}.${cents}${neg ? ")" : ""}`;
}

/** Plain number (no RM prefix), e.g. for report columns. */
export function num(sen: number): string {
  return rm(sen).replace("RM ", "");
}

/*
 * Date order is per language: the keys dateFormat ("{d} {m} {y}"), monthYearFormat, periodSameYear, periodRange,
 * dateTimeFormat and timeFormat in lang/member/<code>.json, with the short month names (monthShort_N). The PHP emails
 * use the same keys (App\Support\UiLang::date()).
 */

/** ISO yyyy-mm-dd -> "d MMM yyyy" (dateFormat). */
export function fmtDate(iso: string, lang: Lang = "en"): string {
  const [y, m, d] = iso.slice(0, 10).split("-").map(Number);
  return fill(textIn("dateFormat", lang), { d, m: monthShort(m, lang), y });
}

/** ISO yyyy-mm-dd -> "MMM yyyy" (monthYearFormat). */
export function fmtMonth(iso: string, lang: Lang = "en"): string {
  const [y, m] = iso.slice(0, 10).split("-").map(Number);
  return fill(textIn("monthYearFormat", lang), { m: monthShort(m, lang), y });
}

/** Fee period: "Okt 2026", or "Okt – Dis 2026" (periodSameYear), or "Dis 2026 – Feb 2027" (periodRange). */
export function fmtPeriod(start: string, end: string, lang: Lang = "en"): string {
  const a = fmtMonth(start, lang);
  const b = fmtMonth(end, lang);
  if (a === b) return a;
  if (start.slice(0, 4) === end.slice(0, 4)) {
    const month = (iso: string) => monthShort(Number(iso.slice(5, 7)), lang);
    return fill(textIn("periodSameYear", lang), { from: month(start), to: month(end), y: Number(end.slice(0, 4)) });
  }
  return fill(textIn("periodRange", lang), { from: a, to: b });
}

/**
 * ISO datetime "yyyy-mm-ddTHH:MM" -> "d MMM yyyy, h:mm am" (dateTimeFormat, timeFormat with {h} 12-hour, {H} 24-hour,
 * {HH} 24-hour with a leading zero, {mm}, {ap} = timeAm / timePm); a date-only value prints the date alone.
 */
export function fmtDateTime(iso: string, lang: Lang = "en"): string {
  if (!iso.includes("T")) return fmtDate(iso, lang);
  const [date, clock = "00:00"] = iso.split("T");
  const [h, min] = clock.split(":").map(Number);
  const time = fill(textIn("timeFormat", lang), {
    h: h % 12 === 0 ? 12 : h % 12,
    H: h,
    HH: String(h).padStart(2, "0"),
    mm: String(min).padStart(2, "0"),
    ap: textIn(h >= 12 ? "timePm" : "timeAm", lang),
  });
  return fill(textIn("dateTimeFormat", lang), { date: fmtDate(date, lang), time });
}
