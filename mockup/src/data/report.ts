import type { Sen } from "./types";

/**
 * Penyata Penerimaan dan Pembayaran, FY 2026 (sample data).
 * Every total below is computed from its lines so the statement always balances.
 */
export interface Line {
  label: string;
  bm?: string;
  amount: Sen;
  note?: string;
}

const sum = (lines: Line[]) => lines.reduce((s, l) => s + l.amount, 0);

export const fy = { label: "FY 2026", period: "1 Jan 2026 – 31 Dec 2026", periodBm: "1 Januari 2026 hingga 31 Disember 2026" };

export const openingByAccount: Line[] = [
  { label: "Cash in hand", bm: "Wang tunai di tangan", amount: 85000 },
  { label: "Bank (Maybank current account)", bm: "Wang di bank", amount: 1842035 },
];
export const openingBalance = sum(openingByAccount);

export const receipts: { heading: string; bm: string; lines: Line[] }[] = [
  {
    heading: "Membership fees",
    bm: "Yuran keahlian",
    lines: [
      { label: "Online (FPX via ToyyibPay)", amount: 3912500 },
      { label: "Cash and bank transfer (official receipts)", amount: 347500 },
    ],
  },
  {
    heading: "Contributions (collection drives)",
    bm: "Sumbangan / kutipan khas",
    lines: [{ label: "Kutipan khas: father of Rajesh Kumar (Jul 2026)", amount: 355000 }],
  },
  {
    heading: "Other income",
    bm: "Pendapatan lain",
    lines: [
      { label: "Annual dinner tickets (non-members)", amount: 420000 },
      { label: "Bank profit (hibah)", amount: 8640 },
    ],
  },
];
export const totalReceipts = receipts.reduce((s, g) => s + sum(g.lines), 0);

export const paymentsByCategory: Line[] = [
  { label: "Annual dinner", bm: "Majlis makan malam tahunan", amount: 985000 },
  { label: "Sports & recreation", bm: "Sukan & rekreasi", amount: 342000 },
  { label: "Meeting refreshments", bm: "Jamuan mesyuarat", amount: 124550 },
  { label: "Gifts & tokens", bm: "Hadiah & cenderamata", amount: 98000 },
  { label: "Website & hosting", bm: "Laman web & hos", amount: 58800 },
  { label: "Admin & stationery", bm: "Pentadbiran & alat tulis", amount: 61280 },
];

export const welfareCases: Line[] = [
  { label: "W-2026-01 Death: father of Rajesh Kumar (incl. kutipan khas RM 3,550.00)", amount: 455000, note: "FU-2026-0024" },
  { label: "W-2026-02 Death: wife of Tan Wei Ming", amount: 100000, note: "FU-2026-0011" },
  { label: "W-2026-03 Hospitalisation: Siti Hajar binti Osman", amount: 50000, note: "FU-2026-0026" },
];

export const bankCharges: Line[] = [
  { label: "ToyyibPay FPX transaction fees (RM 1.00 × 318)", amount: 31800 },
  { label: "Bank service charges", amount: 2400 },
];

export const totalCategory = sum(paymentsByCategory);
export const totalWelfare = sum(welfareCases);
export const totalBankCharges = sum(bankCharges);
export const totalPayments = totalCategory + totalWelfare + totalBankCharges;
export const closingBalance = openingBalance + totalReceipts - totalPayments;

const closingCash: Sen = 112000;
export const closingByAccount: Line[] = [
  { label: "Cash in hand", bm: "Wang tunai di tangan", amount: closingCash },
  { label: "Bank (Maybank current account)", bm: "Wang di bank", amount: closingBalance - closingCash },
];

const unpresented: Sen = 40000;
export const reconciliation = {
  statementBalance: closingBalance - closingCash + unpresented,
  unpresented,
  unpresentedNote: "Less: cheque 004417 to Restoran Nasi Kandar Pelita, not yet presented",
  bookBalance: closingBalance - closingCash,
};

export const outstandingFees = { members: 17, amount: 107500 };

export const signOff = [
  { role: "Bendahari", en: "Treasurer", name: "Lim Siew Ling" },
  { role: "Pengerusi", en: "Chairman", name: "Ahmad Faizal bin Rahman" },
  { role: "Juruaudit Dalam", en: "Internal auditor", name: "Kavitha a/p Ramasamy" },
  { role: "Juruaudit Dalam", en: "Internal auditor", name: "Tan Wei Ming" },
];

/** Figures for the member "Where your money goes" card and the Fund Usage KPIs (year to date). */
export const ytd = {
  collected: totalReceipts,
  spentByCategory: paymentsByCategory,
  spent: totalCategory,
  welfareCount: welfareCases.length,
  welfarePaid: totalWelfare,
  bankCharges: totalBankCharges,
  balance: closingBalance,
};
