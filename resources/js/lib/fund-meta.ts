import type { AttachmentKind, AuditEvent, FundStatus } from "@/types/domain";

/** Display labels for fund-usage enums. Not data: these mirror the backend enum values. */
export const attachmentKinds: { value: AttachmentKind; label: string; bm: string }[] = [
  { value: "invoice", label: "Invoice", bm: "Invois" },
  { value: "receipt", label: "Receipt", bm: "Resit" },
  { value: "bank_slip", label: "Bank slip", bm: "Slip bank" },
  { value: "acknowledgement", label: "Acknowledgement", bm: "Akuan terima" },
  { value: "other", label: "Other", bm: "Lain-lain" },
];

export const statusMeta: Record<FundStatus, { label: string; bm: string; variant: "secondary" | "warning" | "success" | "danger" }> = {
  draft: { label: "Draft", bm: "Draf", variant: "secondary" },
  submitted: { label: "Awaiting approval", bm: "Menunggu kelulusan", variant: "warning" },
  approved: { label: "Approved", bm: "Diluluskan", variant: "success" },
  void: { label: "Void", bm: "Dibatalkan", variant: "danger" },
};

export const auditLabels: Record<AuditEvent["action"], string> = {
  recorded: "Recorded",
  edited: "Edited",
  submitted: "Submitted for approval",
  returned: "Returned to draft",
  approved: "Approved",
  void_requested: "Void requested",
  voided: "Voided",
  downloaded: "Attachment downloaded",
};
