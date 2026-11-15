/** All money values are integer sen (RM 1.00 = 100). Dates are ISO yyyy-mm-dd. */
export type Sen = number;

export interface Club {
  name: string;
  shortName: string;
  regNo: string;
  email: string;
  financialYear: string;
}

export interface Member {
  id: number;
  name: string;
  memberNo: string;
  email: string;
  phone: string;
  role?: string;
}

export type PaymentStatus = "paid" | "pending" | "overdue";

export interface MemberPayment {
  id: number;
  invoiceNo: string;
  description: string;
  descriptionBm: string;
  period: string;
  amount: Sen;
  dueDate: string;
  status: PaymentStatus;
  paidDate?: string;
  receiptNo?: string;
  method?: string;
  bank?: string;
  gatewayRef?: string;
}

export type FundType = "expense" | "welfare";
export type FundStatus = "draft" | "submitted" | "approved" | "void";
export type AccountKind = "cash" | "bank";
export type AttachmentKind = "invoice" | "receipt" | "bank_slip" | "acknowledgement" | "other";

export interface Attachment {
  id: string;
  name: string;
  kind: AttachmentKind;
  sizeKb: number;
  /** MIME type of the stored file, e.g. "image/jpeg" or "application/pdf". */
  mime?: string;
  /** Audited view/download URL; when absent a placeholder drawing is shown. */
  url?: string;
  /** Hex SHA-256 of the stored file, when known. */
  sha256?: string;
}

export interface WelfareDetails {
  beneficiaryId: number;
  beneficiaryName: string;
  subjectName: string;
  relationship: string;
  recipientName: string;
  certificateSightedBy?: string;
  certificateSightedOn?: string;
}

export interface AuditEvent {
  at: string; // ISO datetime yyyy-mm-ddTHH:MM
  who: string;
  action: "recorded" | "edited" | "submitted" | "returned" | "approved" | "void_requested" | "voided" | "downloaded";
  note?: string;
}

export interface FundItem {
  id: number;
  ref: string;
  type: FundType;
  status: FundStatus;
  category: string;
  description: string;
  payee: string;
  invoiceNo?: string;
  amount: Sen;
  invoiceDate: string;
  paidDate?: string;
  account: AccountKind;
  minuteRef?: string;
  recordedBy: string;
  approvedBy?: string;
  attachments: Attachment[];
  welfare?: WelfareDetails;
  voidReason?: string;
  returnReason?: string;
  awaitingMe?: boolean;
}

/** A labelled money line (statement rows, fund summaries). */
export interface Line {
  label: string;
  bm?: string;
  amount: Sen;
  note?: string;
}

/** One month of collected vs spent, for the bar chart. */
export interface MonthPoint {
  month: string;
  monthEn: string;
  collected: Sen;
  spent: Sen;
}

/** One spending group for the 100% split bar. */
export interface CategorySlice {
  key: string;
  bm: string;
  en: string;
  amount: Sen;
  colorVar: string;
  /** Token for a label printed inside the fill (white or near-black by the fill's luminance). */
  onVar: string;
}
