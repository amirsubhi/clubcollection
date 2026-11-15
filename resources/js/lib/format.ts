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

/** ISO datetime "yyyy-mm-ddTHH:MM" -> "d MMM yyyy, h:mm am". */
export function fmtDateTime(iso: string): string {
  const [date, time = "00:00"] = iso.split("T");
  const [h, min] = time.split(":").map(Number);
  const ap = h >= 12 ? "pm" : "am";
  const hh = h % 12 === 0 ? 12 : h % 12;
  return `${fmtDate(date)}, ${hh}:${String(min).padStart(2, "0")} ${ap}`;
}
