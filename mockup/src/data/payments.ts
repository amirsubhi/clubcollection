import type { MemberPayment, Sen } from "./types";

const monthly = (
  id: number,
  month: string,
  monthBm: string,
  mm: string,
  extra: Partial<MemberPayment>,
): MemberPayment => ({
  id,
  invoiceNo: `INV-2026-${String(1400 + id).padStart(6, "0")}`,
  description: `Membership fee, ${month} 2026`,
  descriptionBm: `Yuran keahlian, ${monthBm} 2026`,
  period: `${month} 2026`,
  amount: 2500,
  dueDate: `2026-${mm}-${mm === "02" ? "28" : ["04", "06", "09", "11"].includes(mm) ? "30" : "31"}`,
  status: "paid",
  method: "FPX (ToyyibPay)",
  ...extra,
});

export const memberPayments: MemberPayment[] = [
  monthly(12, "December", "Disember", "12", { status: "pending", method: undefined }),
  monthly(11, "November", "November", "11", { status: "pending", method: undefined }),
  monthly(10, "October", "Oktober", "10", { status: "overdue", method: undefined }),
  monthly(9, "September", "September", "09", { paidDate: "2026-09-26", receiptNo: "DEMO-2026-000318", bank: "Maybank2u", gatewayRef: "TP2609261184" }),
  monthly(8, "August", "Ogos", "08", { paidDate: "2026-08-29", receiptNo: "DEMO-2026-000291", bank: "Maybank2u", gatewayRef: "TP2608290932" }),
  {
    id: 20,
    invoiceNo: "INV-2026-001420",
    description: "Special collection: Arwah father of Rajesh Kumar",
    descriptionBm: "Kutipan khas: Bapa kepada Rajesh Kumar",
    period: "One-off",
    amount: 2500,
    dueDate: "2026-07-31",
    status: "paid",
    paidDate: "2026-07-18",
    receiptNo: "DEMO-2026-000247",
    method: "FPX (ToyyibPay)",
    bank: "CIMB Clicks",
    gatewayRef: "TP2607181520",
  },
  monthly(7, "July", "Julai", "07", { paidDate: "2026-07-18", receiptNo: "DEMO-2026-000246", bank: "CIMB Clicks", gatewayRef: "TP2607181519" }),
  monthly(6, "June", "Jun", "06", { paidDate: "2026-06-27", receiptNo: "DEMO-2026-000203", bank: "Maybank2u", gatewayRef: "TP2606270811" }),
  monthly(5, "May", "Mei", "05", { paidDate: "2026-05-30", receiptNo: "DEMO-2026-000171", method: "Cash (Tunai)", bank: undefined, gatewayRef: undefined }),
  monthly(4, "April", "April", "04", { paidDate: "2026-04-25", receiptNo: "DEMO-2026-000133", bank: "Maybank2u", gatewayRef: "TP2604250744" }),
  monthly(3, "March", "Mac", "03", { paidDate: "2026-03-28", receiptNo: "DEMO-2026-000098", bank: "Maybank2u", gatewayRef: "TP2603281102" }),
  monthly(2, "February", "Februari", "02", { paidDate: "2026-02-27", receiptNo: "DEMO-2026-000061", bank: "Maybank2u", gatewayRef: "TP2602270930" }),
  monthly(1, "January", "Januari", "01", { paidDate: "2026-01-30", receiptNo: "DEMO-2026-000023", bank: "Maybank2u", gatewayRef: "TP2601300815" }),
];

export const dueNow = memberPayments.filter((p) => p.status === "overdue" || p.id === 11);
export const amountDue: Sen = dueNow.reduce((s, p) => s + p.amount, 0);
export const upcoming = memberPayments.filter((p) => p.id === 12);
export const paidHistory = memberPayments.filter((p) => p.status === "paid");

/** Receipt used on the success and receipt screens. */
export const latestReceipt = {
  receiptNo: "DEMO-2026-000412",
  paidAt: "2026-11-13T21:42",
  amount: amountDue,
  bank: "Maybank2u",
  gateway: "ToyyibPay",
  gatewayRef: "TP2611132142",
  items: dueNow,
};
