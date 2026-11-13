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
  mime: "image/jpeg" | "image/png" | "application/pdf";
  sizeKb: number;
  /** Which placeholder drawing to render (no real images in the mockup). */
  art: "receipt" | "invoice" | "slip" | "letter";
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
