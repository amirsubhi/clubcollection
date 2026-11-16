import { useState, type ReactNode } from "react";
import { Link, router } from "@inertiajs/react";
import { Ban, Pencil, Send, ShieldCheck, Trash2, UserX, Users } from "lucide-react";
import { AdminLayout } from "@/components/app/AdminLayout";
import { PageHeader } from "@/components/app/PageHeader";
import { Money } from "@/components/app/Money";
import { FundStatusBadge } from "@/components/app/FundStatusBadge";
import { FundTypeBadge } from "@/components/app/StatusBadges";
import { AttachmentGallery } from "@/components/app/AttachmentGallery";
import { AuditTimeline } from "@/components/app/AuditTimeline";
import { ApprovalRail } from "@/components/app/ApprovalRail";
import { ApprovalFlowDiagram } from "@/components/app/Diagrams";
import { Stamp } from "@/components/app/Visuals";
import { ApproveDialog, ReturnDialog, VoidDialog } from "@/components/app/FundDialogs";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Button, type ButtonProps } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from "@/components/ui/dialog";
import { fmtDate, fmtDateTime } from "@/lib/format";
import type { AdminNavProps, FundDetail, FundEvent } from "@/types/admin";
import type { FundItem } from "@/types/domain";

interface Props {
  nav: AdminNavProps;
  item: FundDetail;
  events: FundEvent[];
  can: { edit: boolean; submit: boolean; delete: boolean; approve: boolean; return: boolean; requestVoid: boolean; confirmVoid: boolean };
  approvalBlocker: string | null;
  needsSecondApprover: boolean;
  eligibleApprovers: number;
  urls: { index: string; edit: string; submit: string; approve: string; return: string; voidRequest: string; voidConfirm: string; destroy: string };
}

/** Shape expected by the shared ApprovalRail / FundDialogs components. */
function toFundItem(i: FundDetail): FundItem {
  return {
    id: i.id,
    ref: i.ref,
    type: i.type,
    status: i.status,
    category: i.category,
    description: i.description,
    payee: i.payee ?? i.welfare?.recipientName ?? "—",
    invoiceNo: i.invoiceNo ?? undefined,
    amount: i.amount,
    invoiceDate: i.invoiceDate,
    paidDate: i.paidDate ?? undefined,
    minuteRef: i.minuteRef ?? undefined,
    recordedBy: i.recordedBy,
    approvedBy: i.approvedBy ?? undefined,
    attachments: i.attachments,
    welfare: i.welfare
      ? {
          beneficiaryId: i.welfare.beneficiaryId,
          beneficiaryName: i.welfare.beneficiaryName,
          subjectName: i.welfare.subjectName,
          relationship: i.welfare.relationship,
          recipientName: i.welfare.recipientName,
        }
      : undefined,
  };
}

function Row({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="grid grid-cols-1 gap-0.5 py-2.5 sm:grid-cols-[180px_1fr] sm:gap-3">
      <dt className="text-sm text-muted-foreground">{label}</dt>
      <dd className="min-w-0 text-sm">{children}</dd>
    </div>
  );
}

function ConfirmDialog({ trigger, title, body, confirm, destructive, onConfirm }: { trigger: ReactNode; title: string; body: ReactNode; confirm: ReactNode; destructive?: boolean; onConfirm: () => void }) {
  const [open, setOpen] = useState(false);
  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <DialogTrigger asChild>{trigger}</DialogTrigger>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{title}</DialogTitle>
          <DialogDescription>{body}</DialogDescription>
        </DialogHeader>
        <DialogFooter>
          <Button variant="outline" onClick={() => setOpen(false)}>
            Cancel
          </Button>
          <Button
            variant={destructive ? "destructive" : "default"}
            onClick={() => {
              setOpen(false);
              onConfirm();
            }}
          >
            {confirm}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

/**
 * Approve / Return that this admin can't use: still focusable and announced with the reason (aria-disabled +
 * aria-describedby), but a click does nothing (preventDefault stops the dialog trigger from opening).
 */
function blockedTrigger(blocked: boolean): ButtonProps {
  if (!blocked) return { className: "w-full" };
  return {
    className: "w-full cursor-not-allowed opacity-50",
    "aria-disabled": true,
    "aria-describedby": "approval-blocker",
    onClick: (e) => e.preventDefault(),
  };
}

export default function FundUsageShow({ nav, item, events, can, approvalBlocker, needsSecondApprover, eligibleApprovers, urls }: Props) {
  const fi = toFundItem(item);
  const w = item.welfare;
  const post = (url: string, data: Record<string, unknown> = {}) => router.post(url, data as never, { preserveScroll: true });
  const lastSubmitted = [...events].reverse().find((e) => e.action === "submitted")?.at ?? item.submittedAt ?? "";
  const flow = item.returned ? "returned" : item.status;

  const actions = (
    <div className="flex flex-col gap-2">
      {item.status === "draft" && (
        <>
          {can.submit && (
            <Button onClick={() => post(urls.submit)}>
              <Send aria-hidden /> Submit for approval
            </Button>
          )}
          {can.edit && (
            <Button variant="outline" asChild>
              <Link href={urls.edit}>
                <Pencil aria-hidden /> Edit
              </Link>
            </Button>
          )}
          {can.delete && (
            <ConfirmDialog
              trigger={
                <Button variant="ghost" className="text-danger-foreground">
                  <Trash2 aria-hidden /> Delete draft
                </Button>
              }
              title={`Delete draft ${item.ref}?`}
              body="The draft and its documents are removed. This is logged in the audit trail."
              confirm="Delete draft"
              destructive
              onConfirm={() => router.delete(urls.destroy)}
            />
          )}
          {!can.edit && <p className="text-sm text-muted-foreground">Only {item.recordedBy} (the maker) can edit or submit this draft.</p>}
        </>
      )}
      {item.status === "submitted" && (
        <>
          <ApproveDialog item={fi} triggerProps={blockedTrigger(!can.approve)} onConfirm={() => post(urls.approve, { declaration: true })} />
          <ReturnDialog item={fi} triggerProps={blockedTrigger(!can.return)} onConfirm={(r) => post(urls.return, { reason: r ?? "" })} />
          {can.edit && (
            <Button variant="ghost" asChild>
              <Link href={urls.edit}>
                <Pencil aria-hidden /> Edit (returns to draft)
              </Link>
            </Button>
          )}
          {(approvalBlocker || !can.approve || !can.return) && (
            <p id="approval-blocker" className="text-xs text-muted-foreground">
              {approvalBlocker ?? "Another admin has to approve or return this record."}
            </p>
          )}
        </>
      )}
      {item.status === "approved" &&
        (item.voidPending ? (
          can.confirmVoid ? (
            <ConfirmDialog
              trigger={
                <Button variant="destructive" className="w-full">
                  <Ban aria-hidden /> Confirm void
                </Button>
              }
              title={`Confirm void of ${item.ref}?`}
              body={`Requested by ${item.voidRequestedBy}: “${item.voidReason}”. Once confirmed it is excluded from all totals. The record and documents are kept.`}
              confirm={
                <>
                  <Ban aria-hidden /> Confirm void
                </>
              }
              destructive
              onConfirm={() => post(urls.voidConfirm)}
            />
          ) : (
            <p className="text-sm text-muted-foreground">Void requested by you. Waiting for a second admin to confirm.</p>
          )
        ) : (
          <VoidDialog item={fi} triggerProps={{ className: "w-full text-danger-foreground", disabled: !can.requestVoid }} onConfirm={(r) => post(urls.voidRequest, { reason: r ?? "" })} />
        ))}
      {item.status === "void" && <p className="text-sm text-muted-foreground">No actions. Voided records are kept for audit.</p>}
    </div>
  );

  return (
    <AdminLayout nav={nav} active="fund-usage" title={item.ref}>
      <PageHeader
        eyebrow={
          <Link href={urls.index} className="hover:underline">
            Fund Usage
          </Link>
        }
        title={<span className="font-mono text-[1.625rem] font-medium tracking-normal sm:text-[1.875rem]">{item.ref}</span>}
        description={item.description}
        actions={
          <div className="flex flex-wrap items-center gap-2">
            <FundTypeBadge type={item.type} />
            <FundStatusBadge status={item.status} returned={item.returned} />
          </div>
        }
      />

      {needsSecondApprover && (
        <Alert variant="warning">
          <UserX aria-hidden />
          <div>
            <AlertTitle>Needs second approver</AlertTitle>
            <AlertDescription>
              {item.recordedByMe ? "You recorded this, so another admin must approve it. " : ""}
              {eligibleApprovers === 0
                ? "This club has no other admin who can approve it. Ask the super admin to approve it, or add a second club admin. Nothing is approved automatically."
                : `${eligibleApprovers} other admin${eligibleApprovers === 1 ? "" : "s"} can approve it. Nothing is approved automatically.`}
            </AlertDescription>
          </div>
        </Alert>
      )}
      {item.returned && item.returnReason && (
        <Alert variant="warning">
          <Pencil aria-hidden />
          <div>
            <AlertTitle>Returned to draft · Dikembalikan</AlertTitle>
            <AlertDescription>{item.returnReason}</AlertDescription>
          </div>
        </Alert>
      )}
      {item.voidPending && (
        <Alert variant="warning">
          <Users aria-hidden />
          <div>
            <AlertTitle>Void requested: needs a second admin</AlertTitle>
            <AlertDescription>
              {item.voidRequestedBy} asked to void this: “{item.voidReason}”. Still counted until another admin confirms.
            </AlertDescription>
          </div>
        </Alert>
      )}
      {item.status === "void" && (
        <Alert variant="danger">
          <Ban aria-hidden />
          <div>
            <AlertTitle>Voided</AlertTitle>
            <AlertDescription>
              {item.voidReason}. Requested by {item.voidRequestedBy}, confirmed by {item.voidApprovedBy}. Excluded from all totals.
            </AlertDescription>
          </div>
        </Alert>
      )}

      <div className="grid gap-5 lg:grid-cols-[minmax(0,1fr)_340px]">
        <div className="flex min-w-0 flex-col gap-5">
          <Card className="relative overflow-hidden">
            <CardContent className="flex flex-col gap-5 p-4 pt-5 sm:p-6">
              <div className="flex flex-col gap-1 pr-24 sm:pr-36">
                <span className="text-sm text-muted-foreground">{item.type === "welfare" ? "Welfare payout" : item.paidDate ? "Amount paid" : "Amount"}</span>
                <Money sen={item.amount} className="figure font-display text-[2.5rem] font-extrabold leading-none tracking-tight" />
                <span className="text-sm">
                  to <span className="font-semibold">{fi.payee}</span> · {item.category}
                </span>
              </div>
              {(item.status === "approved" || item.status === "void") && (
                <Stamp key={item.status} tone={item.status === "void" ? "danger" : "success"} className="absolute right-4 top-6 sm:right-8 sm:top-8 sm:text-3xl">
                  {item.status === "void" ? "Void" : "Approved"}
                </Stamp>
              )}
              <div className="rounded-lg border bg-muted/40 p-3 sm:p-4">
                <ApprovalRail item={fi} preparedAt={lastSubmitted ? fmtDateTime(lastSubmitted) : ""} approvedAt={item.approvedAt ? fmtDateTime(item.approvedAt) : undefined} />
              </div>
              <dl className="divide-y">
                <Row label="Category">{item.category}</Row>
                <Row label="Paid to">{fi.payee}</Row>
                {item.invoiceNo && (
                  <Row label="Invoice no.">
                    <span className="font-mono text-[0.8125rem]">{item.invoiceNo}</span>
                  </Row>
                )}
                <Row label={item.type === "welfare" ? "Committee approval date" : "Invoice date"}>{fmtDate(item.invoiceDate)}</Row>
                <Row label="Date paid">{item.paidDate ? fmtDate(item.paidDate) : "—"}</Row>
                <Row label="Minute ref.">{item.minuteRef ?? "—"}</Row>
                <Row label="Recorded by">{item.recordedBy}</Row>
              </dl>
            </CardContent>
          </Card>

          {w && (
            <Card>
              <CardHeader className="pb-1">
                <CardTitle>Welfare details</CardTitle>
              </CardHeader>
              <CardContent>
                <dl className="divide-y">
                  <Row label="Beneficiary member">{w.beneficiaryName}</Row>
                  <Row label="Deceased / affected">
                    {w.subjectName} ({w.relationship})
                  </Row>
                  <Row label="Recipient">{w.recipientName}</Row>
                  <Row label="Certificate sighted">
                    {w.certificateSightedBy ? (
                      <span className="inline-flex items-center gap-1">
                        <ShieldCheck className="h-4 w-4 text-success" aria-hidden />
                        {w.certificateSightedBy} on {w.certificateSightedOn ? fmtDate(w.certificateSightedOn) : "—"}
                      </span>
                    ) : (
                      "Not recorded"
                    )}
                  </Row>
                  {w.retentionUntil && <Row label="Personal data kept until">{fmtDate(w.retentionUntil)}</Row>}
                </dl>
                <p className="mt-2 text-xs text-muted-foreground">Admins only. The beneficiary ({w.beneficiaryName.split(" ")[0]}) can't approve this record.</p>
              </CardContent>
            </Card>
          )}

          <Card>
            <CardHeader className="pb-2">
              <CardTitle>Documents ({item.attachments.length})</CardTitle>
            </CardHeader>
            <CardContent>
              <AttachmentGallery items={item.attachments} frozen={item.status !== "draft"} />
            </CardContent>
          </Card>
        </div>

        <div className="flex flex-col gap-5">
          <Card>
            <CardHeader className="pb-2">
              <CardTitle>Actions</CardTitle>
            </CardHeader>
            <CardContent>{actions}</CardContent>
          </Card>
          <Card>
            <CardHeader className="pb-2">
              <CardTitle>Audit trail</CardTitle>
            </CardHeader>
            <CardContent>{events.length ? <AuditTimeline events={events} /> : <p className="text-sm text-muted-foreground">No events yet.</p>}</CardContent>
          </Card>
          <Card>
            <CardHeader className="pb-2">
              <CardTitle>How approval works</CardTitle>
            </CardHeader>
            <CardContent>
              <ApprovalFlowDiagram current={flow} />
            </CardContent>
          </Card>
        </div>
      </div>
    </AdminLayout>
  );
}
