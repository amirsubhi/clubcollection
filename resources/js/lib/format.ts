const MONTHS = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];
const MONTHS_BM = ["Jan", "Feb", "Mac", "Apr", "Mei", "Jun", "Jul", "Ogo", "Sep", "Okt", "Nov", "Dis"];

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

/** ISO yyyy-mm-dd -> "d MMM yyyy". */
export function fmtDate(iso: string, lang: "bm" | "en" = "en"): string {
  const [y, m, d] = iso.slice(0, 10).split("-").map(Number);
  const names = lang === "bm" ? MONTHS_BM : MONTHS;
  return `${d} ${names[m - 1]} ${y}`;
}

/** ISO yyyy-mm-dd -> "MMM yyyy". */
export function fmtMonth(iso: string, lang: "bm" | "en" = "en"): string {
  const [y, m] = iso.slice(0, 10).split("-").map(Number);
  return `${(lang === "bm" ? MONTHS_BM : MONTHS)[m - 1]} ${y}`;
}

/** Fee period: "Okt 2026", or "Okt – Dis 2026", or "Dis 2026 – Feb 2027". */
export function fmtPeriod(start: string, end: string, lang: "bm" | "en" = "en"): string {
  const a = fmtMonth(start, lang);
  const b = fmtMonth(end, lang);
  if (a === b) return a;
  if (start.slice(0, 4) === end.slice(0, 4)) return `${a.split(" ")[0]} – ${b}`;
  return `${a} – ${b}`;
}

/** ISO datetime "yyyy-mm-ddTHH:MM" -> "d MMM yyyy, h:mm am"; a date-only value prints the date alone. */
export function fmtDateTime(iso: string, lang: "bm" | "en" = "en"): string {
  if (!iso.includes("T")) return fmtDate(iso, lang);
  const [date, time = "00:00"] = iso.split("T");
  const [h, min] = time.split(":").map(Number);
  const ap = h >= 12 ? "pm" : "am";
  const hh = h % 12 === 0 ? 12 : h % 12;
  return `${fmtDate(date, lang)}, ${hh}:${String(min).padStart(2, "0")} ${ap}`;
}
