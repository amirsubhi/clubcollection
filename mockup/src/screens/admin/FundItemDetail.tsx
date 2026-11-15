import { useMemo, useState } from "react";
import { Ban, Pencil, Send, ShieldCheck, Trash2, UserX, Users } from "lucide-react";
import { AdminShell } from "@/components/app/AdminShell";
import { PageHeader } from "@/components/app/PageHeader";
import { MockControl } from "@/components/app/MockControl";
import { Money } from "@/components/app/Money";
import { FundStatusBadge, FundTypeBadge } from "@/components/app/StatusBadges";
import { AttachmentGallery } from "@/components/app/AttachmentGallery";
import { AuditTimeline } from "@/components/app/AuditTimeline";
import { ApprovalRail } from "@/components/app/ApprovalRail";
import { ApprovalFlowDiagram } from "@/components/app/Diagrams";
import { Stamp } from "@/components/app/Visuals";
import { ApproveDialog, ReturnDialog, VoidDialog } from "@/components/app/FundDialogs";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { auditTrail, fundItems } from "@/data/fund";
import type { AuditEvent, FundItem, FundStatus } from "@/data/types";
import { fmtDate, fmtDateTime } from "@/lib/format";
import { currentAdmin } from "@/data/club";

type Which = "expense" | "welfare";

function itemFor(which: Which, status: FundStatus, byMe: boolean): FundItem {
  const base = fundItems.find((i) => i.id === (which === "expense" ? 31 : 30))!;
  return {
    ...base,
    status,
    recordedBy: byMe ? currentAdmin.name : base.recordedBy,
    approvedBy: status === "approved" || status === "void" ? (byMe ? "Ahmad Faizal bin Rahman" : currentAdmin.name) : undefined,
    voidReason: status === "void" ? "Duplicate of FU-2026-0019 (deposit already recorded)" : undefined,
  };
}

function trailFor(item: FundItem, extra: AuditEvent[]): AuditEvent[] {
  const base = auditTrail[item.status].map((e) =>
    e.action === "recorded" || e.action === "submitted" ? { ...e, who: item.recordedBy } : e.action === "approved" ? { ...e, who: item.approvedBy ?? e.who } : e,
  );
  return [...base, ...extra];
}

function Row({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="grid grid-cols-1 gap-0.5 py-2.5 sm:grid-cols-[180px_1fr] sm:gap-3">
      <dt className="text-sm text-muted-foreground">{label}</dt>
      <dd className="min-w-0 text-sm">{children}</dd>
    </div>
  );
}

export function FundItemDetail() {
  const [which, setWhich] = useState<Which>("expense");
  const [status, setStatus] = useState<FundStatus>("submitted");
  const [byMe, setByMe] = useState<"other" | "me">("other");
  const [extra, setExtra] = useState<AuditEvent[]>([]);
  const [voidRequested, setVoidRequested] = useState<string | null>(null);
  const [returnedReason, setReturnedReason] = useState<string | null>(null);

  const item = useMemo(() => itemFor(which, status, byMe === "me"), [which, status, byMe]);
  const events = trailFor(item, extra);
  const now = "2026-11-13T10:15";
  const reset = () => {
    setExtra([]);
    setVoidRequested(null);
    setReturnedReason(null);
  };
  const recordedByMe = byMe === "me";
  const w = item.welfare;

  const actions = (
    <div className="flex flex-col gap-2">
      {item.status === "draft" && (
        <>
          <Button>
            <Send aria-hidden /> Submit for approval
          </Button>
          <Button variant="outline">
            <Pencil aria-hidden /> Edit
          </Button>
          <Button variant="ghost" className="text-danger-foreground">
            <Trash2 aria-hidden /> Delete draft
          </Button>
        </>
      )}
      {item.status === "submitted" && (
        <>
          <ApproveDialog
            item={item}
            triggerProps={{ disabled: recordedByMe, className: "w-full" }}
            onConfirm={() => {
              setStatus("approved");
              setExtra([{ at: now, who: currentAdmin.name, action: "approved", note: "No conflict of interest declared (just now)" }]);
            }}
          />
          <ReturnDialog
            item={item}
            triggerProps={{ disabled: recordedByMe, className: "w-full" }}
            onConfirm={(r) => {
              setStatus("draft");
              setReturnedReason(r ?? "");
              setExtra([{ at: now, who: currentAdmin.name, action: "returned", note: r }]);
            }}
          />
          {recordedByMe && <p className="text-xs text-muted-foreground">You recorded this, so you can't approve or return it.</p>}
        </>
      )}
      {item.status === "approved" &&
        (voidRequested ? (
          <p className="text-sm text-muted-foreground">Void requested. Waiting for a second admin.</p>
        ) : (
          <VoidDialog
            item={item}
            triggerProps={{ className: "w-full text-danger-foreground" }}
            onConfirm={(r) => {
              setVoidRequested(r ?? "");
              setExtra([{ at: now, who: currentAdmin.name, action: "void_requested", note: r }]);
            }}
          />
        ))}
      {item.status === "void" && <p className="text-sm text-muted-foreground">No actions. Voided records are kept for audit.</p>}
    </div>
  );

  return (
    <AdminShell active="fund-item">
      <PageHeader
        eyebrow={<a href="#fund-usage" className="hover:underline">Fund Usage</a>}
        title={<span className="font-mono text-[26px] font-medium tracking-normal sm:text-[30px]">{item.ref}</span>}
        description={item.description}
        actions={
          <div className="flex flex-wrap items-center gap-2">
            <FundTypeBadge type={item.type} />
            <FundStatusBadge status={item.status} showBm returned={item.status === "draft" && returnedReason !== null} />
          </div>
        }
      />

      <div className="flex flex-col gap-2">
        <MockControl
          label="Status"
          value={item.status}
          options={[
            { value: "draft", label: "Draft" },
            { value: "submitted", label: "Submitted" },
            { value: "approved", label: "Approved" },
            { value: "void", label: "Void" },
          ]}
          onChange={(v) => (setStatus(v), reset())}
        />
        <div className="flex flex-wrap gap-2">
          <MockControl
            label="Recorded by"
            value={byMe}
            options={[
              { value: "other", label: "Another admin" },
              { value: "me", label: "Me" },
            ]}
            onChange={(v) => (setByMe(v), reset())}
          />
          <MockControl
            label="Record"
            value={which}
            options={[
              { value: "expense", label: "Expense" },
              { value: "welfare", label: "Welfare" },
            ]}
            onChange={(v) => (setWhich(v), reset())}
          />
        </div>
      </div>

      {item.status === "submitted" && recordedByMe && (
        <Alert variant="warning">
          <UserX aria-hidden />
          <div>
            <AlertTitle>Needs second approver (you recorded this)</AlertTitle>
            <AlertDescription>
              Perlu pelulus kedua. Ahmad Faizal bin Rahman and Priya a/p Subramaniam have been notified. Nothing is approved automatically.
            </AlertDescription>
          </div>
        </Alert>
      )}
      {item.status === "draft" && returnedReason !== null && (
        <Alert variant="warning">
          <Pencil aria-hidden />
          <div>
            <AlertTitle>Returned to draft</AlertTitle>
            <AlertDescription>{returnedReason}</AlertDescription>
          </div>
        </Alert>
      )}
      {voidRequested !== null && (
        <Alert variant="warning">
          <Users aria-hidden />
          <div>
            <AlertTitle>Void requested: needs a second admin</AlertTitle>
            <AlertDescription>Reason: {voidRequested}. Still counted until another admin confirms.</AlertDescription>
          </div>
        </Alert>
      )}
      {item.status === "void" && (
        <Alert variant="danger">
          <Ban aria-hidden />
          <div>
            <AlertTitle>Voided · Dibatalkan</AlertTitle>
            <AlertDescription>
              {item.voidReason}. Requested by Lim Siew Ling, confirmed by Ahmad Faizal bin Rahman. Excluded from all totals.
            </AlertDescription>
          </div>
        </Alert>
      )}

      <div className="grid gap-5 lg:grid-cols-[minmax(0,1fr)_340px]">
        <div className="flex min-w-0 flex-col gap-5">
          <Card className="relative overflow-hidden">
            <CardContent className="flex flex-col gap-5 p-4 pt-5 sm:p-6">
              <div className="flex flex-col gap-1 pr-24 sm:pr-36">
                <span className="text-sm text-muted-foreground">{item.type === "welfare" ? "Welfare payout" : "Amount paid"}</span>
                <Money sen={item.amount} className="figure font-display text-[40px] font-extrabold leading-none tracking-tight" />
                <span className="text-sm">
                  to <span className="font-semibold">{item.payee}</span> · {item.category}
                </span>
              </div>
              {(item.status === "approved" || item.status === "void") && (
                <Stamp key={item.status} tone={item.status === "void" ? "danger" : "success"} className="absolute right-4 top-6 sm:right-8 sm:top-8 sm:text-3xl">
                  {item.status === "void" ? "Batal" : "Lulus"}
                </Stamp>
              )}
              <div className="rounded-lg border bg-muted/40 p-3 sm:p-4">
                <ApprovalRail item={item} preparedAt={fmtDateTime(events.find((e) => e.action === "submitted")?.at ?? "2026-11-10T16:12")} approvedAt={fmtDateTime(events.find((e) => e.action === "approved")?.at ?? now)} />
              </div>
              <dl className="divide-y">
                <Row label="Category">{item.category}</Row>
                <Row label="Paid to">{item.payee}</Row>
                {item.invoiceNo && (
                  <Row label="Invoice no.">
                    <span className="font-mono text-[13px]">{item.invoiceNo}</span>
                  </Row>
                )}
                <Row label={item.type === "welfare" ? "Committee approval date" : "Invoice date"}>{fmtDate(item.invoiceDate)}</Row>
                <Row label="Date paid">{item.paidDate ? fmtDate(item.paidDate) : "—"}</Row>
                <Row label="Paid from">{item.account === "bank" ? "Bank (Maybank current account)" : "Cash in hand"}</Row>
                <Row label="Minute ref.">{item.minuteRef ?? "—"}</Row>
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
                    <span className="inline-flex items-center gap-1">
                      <ShieldCheck className="h-4 w-4 text-success" aria-hidden />
                      {w.certificateSightedBy} on {w.certificateSightedOn ? fmtDate(w.certificateSightedOn) : "—"}
                    </span>
                  </Row>
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
            <CardContent>
              <AuditTimeline events={events} />
            </CardContent>
          </Card>
          <Card>
            <CardHeader className="pb-2">
              <CardTitle>How approval works</CardTitle>
            </CardHeader>
            <CardContent>
              <ApprovalFlowDiagram current={item.status === "draft" && returnedReason !== null ? "returned" : item.status} />
            </CardContent>
          </Card>
        </div>
      </div>
    </AdminShell>
  );
}
