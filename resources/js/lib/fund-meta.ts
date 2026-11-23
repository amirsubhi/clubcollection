import type { AttachmentKind, AuditEvent, FundStatus } from "@/types/domain";

/** Display labels for fund-usage enums. Not data: these mirror the backend enum values. */
export const attachmentKinds: { value: AttachmentKind; label: string }[] = [
  { value: "invoice", label: "Invoice" },
  { value: "receipt", label: "Receipt" },
  { value: "bank_slip", label: "Bank slip" },
  { value: "acknowledgement", label: "Acknowledgement" },
  { value: "other", label: "Other" },
];

/** Admin labels (English). What members see is fundStatus_<status> in lang/member (FundStatusBadge). */
export const statusMeta: Record<FundStatus, { label: string; variant: "secondary" | "warning" | "success" | "danger" }> = {
  draft: { label: "Draft", variant: "secondary" },
  submitted: { label: "Awaiting approval", variant: "warning" },
  approved: { label: "Approved", variant: "success" },
  void: { label: "Void", variant: "danger" },
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
