export type Area = "member" | "admin" | "report" | "index";

export interface ScreenDef {
  hash: string;
  title: string;
  description: string;
  area: Area;
}

/** Hash names are plain ASCII words with hyphens (e.g. #member-home). */
export const screens: ScreenDef[] = [
  { hash: "overview", title: "Overview", description: "Index of every screen.", area: "index" },
  { hash: "member-home", title: "Member home", description: "Amount due with one Pay button, overdue notice, upcoming dues and the “Where your money goes” card.", area: "member" },
  { hash: "member-invoice", title: "Invoice", description: "What is being paid for, before paying.", area: "member" },
  { hash: "member-pay", title: "Review & pay (FPX hand-off)", description: "Confirms the amount, names FPX and ToyyibPay, bank picker.", area: "member" },
  { hash: "pay-success", title: "Result: success", description: "Payment confirmed, with the official receipt number.", area: "member" },
  { hash: "pay-pending", title: "Result: pending", description: "“We're confirming… don't pay again.”", area: "member" },
  { hash: "pay-failed", title: "Result: failed", description: "Declined or cancelled, with what to do if money already left the account.", area: "member" },
  { hash: "member-history", title: "Payment history", description: "Paid, pending and overdue items for the year.", area: "member" },
  { hash: "member-receipt", title: "Receipt", description: "Official receipt (resit rasmi) view.", area: "member" },
  { hash: "member-fund", title: "Fund tab (transparency)", description: "Totals by category and welfare count. No documents or names.", area: "member" },
  { hash: "member-account", title: "Account", description: "Member details and language.", area: "member" },
  { hash: "fund-usage", title: "Fund Usage list", description: "KPIs, tabs (All / Expenses / Welfare / Awaiting my approval / Void), filters, table. Void tab shows the empty state.", area: "admin" },
  { hash: "fund-new", title: "New record (expense)", description: "Form with multi-file uploader, per-file kind, save draft or submit.", area: "admin" },
  { hash: "fund-new-errors", title: "New record: validation errors", description: "The same form after submitting with missing fields.", area: "admin" },
  { hash: "welfare-new", title: "New welfare case", description: "Beneficiary, deceased/affected person, recipient, certificate sighted, PDPA notice.", area: "admin" },
  { hash: "fund-item", title: "Record detail", description: "Summary, attachments with preview, audit trail, approve / return / void dialogs, state switcher.", area: "admin" },
  { hash: "fund-approve", title: "Approve on phone", description: "Compact approval view for a second admin on mobile.", area: "admin" },
  { hash: "agm-report", title: "AGM report preview", description: "Penyata Penerimaan dan Pembayaran FY 2026 with reconciliation and sign-off.", area: "report" },
  { hash: "questions", title: "Questions for committee", description: "Decisions we need from you, with short options.", area: "report" },
];

export const screenByHash = Object.fromEntries(screens.map((s) => [s.hash, s])) as Record<string, ScreenDef>;

export function go(hash: string) {
  window.location.hash = hash;
}
