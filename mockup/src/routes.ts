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
  { hash: "member-home", title: "Member home", description: "Deep Pine balance card with one Bayar button, quick actions, what's due, recent activity and a fund teaser.", area: "member" },
  { hash: "member-invoice", title: "Invoice", description: "What is being paid for, before paying.", area: "member" },
  { hash: "member-pay", title: "Review & pay sheet", description: "Summary with Change link, popular banks first, trust cues, amount on the button.", area: "member" },
  { hash: "pay-success", title: "Result: success", description: "Animated tick and a light-paper receipt to download or share.", area: "member" },
  { hash: "pay-pending", title: "Result: pending", description: "“We're confirming… don't pay again.”", area: "member" },
  { hash: "pay-failed", title: "Result: failed", description: "Declined or cancelled, with what to do if money already left the account.", area: "member" },
  { hash: "member-history", title: "Payment history", description: "Paid, pending and overdue items for the year.", area: "member" },
  { hash: "member-receipt", title: "Receipt", description: "Official receipt (resit rasmi) view.", area: "member" },
  { hash: "member-fund", title: "Fund tab (transparency)", description: "Fund balance, “Where your money goes” flow diagram, monthly chart and spending split.", area: "member" },
  { hash: "member-account", title: "Account", description: "Member details and language.", area: "member" },
  { hash: "admin-dashboard", title: "Admin dashboard", description: "Admin landing: 4 KPI tiles, collected-vs-spent chart, spending split bar, approvals queue and recent activity.", area: "admin" },
  { hash: "fund-usage", title: "Fund Usage list", description: "KPIs, tabs (All / Expenses / Welfare / Awaiting my approval / Void), filters, table. Void tab shows the empty state.", area: "admin" },
  { hash: "fund-new", title: "New record (expense)", description: "Form with multi-file uploader, per-file kind, save draft or submit.", area: "admin" },
  { hash: "fund-new-errors", title: "New record: validation errors", description: "The same form after submitting with missing fields.", area: "admin" },
  { hash: "welfare-new", title: "New welfare case", description: "Beneficiary, deceased/affected person, recipient, certificate sighted, PDPA notice.", area: "admin" },
  { hash: "fund-item", title: "Record detail", description: "Approval rail with avatars, LULUS stamp, maker-checker diagram, audit trail and dialogs.", area: "admin" },
  { hash: "fund-approve", title: "Approve on phone", description: "Compact approval view for a second admin on mobile.", area: "admin" },
  { hash: "agm-report", title: "AGM report preview", description: "Penyata Penerimaan dan Pembayaran FY 2026 with reconciliation and sign-off.", area: "report" },
  { hash: "questions", title: "Questions for committee", description: "Decisions we need from you, with short options.", area: "report" },
];

export const screenByHash = Object.fromEntries(screens.map((s) => [s.hash, s])) as Record<string, ScreenDef>;

export function go(hash: string) {
  window.location.hash = hash;
}
