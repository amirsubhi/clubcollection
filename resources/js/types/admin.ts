import type { Attachment, AuditEvent, FundStatus, FundType, Sen } from "./domain";

/** App\Support\Admin\AdminNav::props(). */
export interface AdminNavProps {
  groups: { title: string; items: { key: string; label: string; href: string; icon: string; external: boolean; badge?: number | null }[] }[];
  homeHref: string;
  brandSubtitle: string | null;
  user: { name: string; initials: string; roleLabel: string };
  memberHref: string;
  logoutUrl: string;
}

/** FundFigures::windowProps(): the period a figure covers. */
export interface FigureWindow {
  key: string;
  /** e.g. "This year so far", "Last 12 months". */
  label: string;
  /** e.g. "1 Jan – 15 Nov 2026". */
  range: string;
  from: string;
  to: string;
}

/** FundUsagePresenter::row(): no welfare personal data. */
export interface FundRow {
  id: number;
  ref: string;
  type: FundType;
  status: FundStatus;
  category: string;
  description: string;
  /** Null for welfare (the recipient is private). */
  payee: string | null;
  amount: Sen;
  invoiceDate: string;
  paidDate: string | null;
  recordedBy: string;
  approvedBy: string | null;
  attachmentCount: number;
  returned: boolean;
  voidPending: boolean;
  recordedByMe: boolean;
  url: string;
}

export interface FundAttachment extends Attachment {
  frozen: boolean;
}

export interface FundWelfare {
  beneficiaryId: number;
  beneficiaryName: string;
  subjectName: string;
  relationship: string;
  recipientName: string;
  certificateSightedById: number | null;
  certificateSightedBy: string | null;
  certificateSightedOn: string | null;
  retentionUntil: string | null;
}

/** FundUsagePresenter::detail(): admin pages only. */
export interface FundDetail extends FundRow {
  categoryId: number;
  invoiceNo: string | null;
  minuteRef: string | null;
  submittedAt: string | null;
  approvedAt: string | null;
  returnReason: string | null;
  voidReason: string | null;
  voidRequestedBy: string | null;
  voidRequestedAt: string | null;
  voidApprovedBy: string | null;
  attachments: FundAttachment[];
  welfare: FundWelfare | null;
}

export type FundEvent = AuditEvent;

export interface Option {
  id: number;
  name: string;
}
