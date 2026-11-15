import type { Sen } from "./types";
import { paymentsByCategory, totalBankCharges, totalPayments, totalReceipts, totalWelfare } from "./report";

/** Month-by-month collected vs spent, Jan–Nov 2026 (sample). Each series sums exactly to the YTD totals. */
const collectedRm = [3950, 3880, 4020, 3910, 3840, 3960, 7520, 3930, 4060, 7950];
const spentRm = [310, 285, 420, 365, 2980, 540, 395, 4980, 1610, 2520];
const monthsBm = ["Jan", "Feb", "Mac", "Apr", "Mei", "Jun", "Jul", "Ogo", "Sep", "Okt", "Nov"];
const monthsEn = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov"];

const toSen = (a: number[]) => a.map((x) => x * 100);
const collectedSen = toSen(collectedRm);
const spentSen = toSen(spentRm);
collectedSen.push(totalReceipts - collectedSen.reduce((s, x) => s + x, 0));
spentSen.push(totalPayments - spentSen.reduce((s, x) => s + x, 0));

export interface MonthPoint {
  month: string;
  monthEn: string;
  collected: Sen;
  spent: Sen;
}

export const monthly: MonthPoint[] = monthsBm.map((m, i) => ({ month: m, monthEn: monthsEn[i], collected: collectedSen[i], spent: spentSen[i] }));

/** Four spending groups for the 100% bar. Order = palette order (validated adjacent pairs). */
const byLabel = (l: string) => paymentsByCategory.find((c) => c.label === l)!.amount;
const dinner = byLabel("Annual dinner");
const sports = byLabel("Sports & recreation");
const other = paymentsByCategory.reduce((s, c) => s + c.amount, 0) - dinner - sports + totalBankCharges;

export interface CategorySlice {
  key: "events" | "welfare" | "sports" | "other";
  bm: string;
  en: string;
  amount: Sen;
  colorVar: string;
  /** Token for a label printed inside the fill (white or near-black by the fill's luminance). */
  onVar: string;
}

export const spendSlices: CategorySlice[] = [
  { key: "events", bm: "Majlis makan malam", en: "Annual dinner", amount: dinner, colorVar: "--chart-1", onVar: "--on-chart-1" },
  { key: "welfare", bm: "Kebajikan", en: "Welfare", amount: totalWelfare, colorVar: "--chart-2", onVar: "--on-chart-2" },
  { key: "sports", bm: "Sukan & rekreasi", en: "Sports & recreation", amount: sports, colorVar: "--chart-3", onVar: "--on-chart-3" },
  { key: "other", bm: "Lain-lain & caj bank", en: "Other & bank charges", amount: other, colorVar: "--chart-4", onVar: "--on-chart-4" },
];

export const outstanding = { members: 17, amount: 107500 as Sen };

export type ActivityTone = "pine" | "marigold" | "info" | "clay" | "neutral";
export interface Activity {
  id: string;
  at: string;
  title: string;
  who: string;
  amount: Sen;
  direction: "in" | "out";
  tone: ActivityTone;
  kind: "fee" | "cash" | "expense" | "welfare" | "approved";
  hash: string;
  pending?: boolean;
}

export const recentActivity: Activity[] = [
  { id: "r1", at: "2026-11-13T21:42", title: "Fee paid online (FPX)", who: "Muhammad Haziq bin Azman", amount: 5000, direction: "in", tone: "pine", kind: "fee", hash: "member-receipt" },
  { id: "r2", at: "2026-11-10T16:12", title: "FU-2026-0031 submitted", who: "Wong Kah Hoe", amount: 689500, direction: "out", tone: "marigold", kind: "expense", hash: "fund-item", pending: true },
  { id: "r3", at: "2026-11-08T10:20", title: "Welfare case submitted", who: "Priya a/p Subramaniam", amount: 100000, direction: "out", tone: "info", kind: "welfare", hash: "fund-approve", pending: true },
  { id: "r4", at: "2026-11-07T13:05", title: "Cash fee recorded", who: "Faridah binti Ismail", amount: 2500, direction: "in", tone: "pine", kind: "cash", hash: "fund-usage" },
  { id: "r5", at: "2026-10-05T11:40", title: "FU-2026-0027 approved", who: "Lim Siew Ling", amount: 58800, direction: "out", tone: "clay", kind: "approved", hash: "fund-item" },
];
