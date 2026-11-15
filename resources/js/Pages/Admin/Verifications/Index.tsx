import { useState } from "react";
import { router } from "@inertiajs/react";
import { AlertTriangle, BadgeCheck, CheckCircle2, Clock, ExternalLink, Inbox, Landmark, XCircle } from "lucide-react";
import { AdminLayout } from "@/components/app/AdminLayout";
import { PageHeader } from "@/components/app/PageHeader";
import { Money } from "@/components/app/Money";
import { AttachmentGallery } from "@/components/app/AttachmentGallery";
import { ApproveSlipDialog, RejectSlipDialog, type SlipSummary } from "@/components/app/SlipDialogs";
import { StatusChip } from "@/components/app/StatusBadges";
import { Avatar } from "@/components/app/Visuals";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Tabs, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { fmtDate, fmtDateTime, fmtPeriod } from "@/lib/format";
import type { AdminNavProps, FundAttachment } from "@/types/admin";

/** TransferVerificationController::card() plus the actions. */
export interface PendingSlip {
  id: number;
  invoiceNo: string;
  member: { name: string; email: string | null };
  periodStart: string;
  periodEnd: string;
  frequency: "monthly" | "quarterly" | "yearly";
  amount: number;
  dueDate: string;
  transferDate: string | null;
  transferRef: string | null;
  expectedRef: string;
  submittedAt: string | null;
  slips: FundAttachment[];
  blocker: string | null;
  urls: { approve: string; reject: string; payment: string };
}

/** TransferVerificationController::history(). */
export interface HandledSlip {
  id: number;
  paymentId: number;
  outcome: "approved" | "rejected" | "gateway_paid";
  member: string;
  amount: number;
  periodStart: string | null;
  periodEnd: string | null;
  by: string;
  at: string;
  reason: string | null;
  reference: string | null;
  paidDate: string | null;
  url: string | null;
}

interface Props {
  nav: AdminNavProps;
  club: { name: string; transferReady: boolean };
  tab: "pending" | "handled";
  pending: PendingSlip[];
  handled: HandledSlip[];
  today: string;
  urls: { index: string; billing: string };
}

const freq = { monthly: "Monthly", quarterly: "Quarterly", yearly: "Yearly" };

const feeLabel = (s: { periodStart: string; periodEnd: string }) => `Membership fee ${fmtPeriod(s.periodStart, s.periodEnd, "en")}`;

function Fact({ label, children, mono = false }: { label: string; children: React.ReactNode; mono?: boolean }) {
  return (
    <div className="min-w-0">
      <dt className="text-xs text-muted-foreground">{label}</dt>
      <dd className={mono ? "break-words font-mono text-sm" : "text-sm font-medium"}>{children}</dd>
    </div>
  );
}

function SlipCard({ s, today }: { s: PendingSlip; today: string }) {
  const post = (url: string, data: Record<string, string>) => router.post(url, data, { preserveScroll: true });
  const summary: SlipSummary = {
    id: s.id,
    member: s.member,
    amount: s.amount,
    feeLabel: feeLabel(s),
    transferDate: s.transferDate,
    transferRef: s.transferRef,
    expectedRef: s.expectedRef,
  };
  const refMatches = !!s.transferRef && s.transferRef.toUpperCase().includes(s.expectedRef);
  return (
    <li className="grid min-w-0 gap-4 rounded-lg border bg-card p-4 sm:p-5 lg:grid-cols-[minmax(0,1fr)_340px]" data-testid="slip-card">
      <div className="flex min-w-0 flex-col gap-4">
        <div className="flex items-start gap-3">
          <Avatar name={s.member.name} size={44} />
          <div className="min-w-0 flex-1">
            <div className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
              <h3 className="truncate font-display text-lg font-bold tracking-tight">{s.member.name}</h3>
              <Money sen={s.amount} className="font-display text-2xl font-bold" />
            </div>
            <div className="truncate text-sm text-muted-foreground">
              {feeLabel(s)} · {freq[s.frequency]} · {s.invoiceNo}
            </div>
          </div>
        </div>

        <dl className="grid grid-cols-2 gap-x-4 gap-y-3 rounded-md bg-muted/60 p-3 sm:grid-cols-4">
          <Fact label="Transfer date">{s.transferDate ? fmtDate(s.transferDate) : "—"}</Fact>
          <Fact label="Slip sent">{s.submittedAt ? fmtDateTime(s.submittedAt) : "—"}</Fact>
          <Fact label="Reference to look for" mono>
            {s.expectedRef}
          </Fact>
          <Fact label="Member's bank ref" mono>
            {s.transferRef ?? <span className="font-sans text-muted-foreground">Not given</span>}
          </Fact>
        </dl>

        <div className="mt-auto flex flex-col gap-2">
          <div className="flex flex-wrap items-center gap-2">
            <ApproveSlipDialog
              slip={summary}
              today={today}
              triggerProps={{ disabled: !!s.blocker }}
              onConfirm={(paidDate, reference) =>
                post(s.urls.approve, {
                  paid_date: paidDate,
                  reference,
                })
              }
            />
            <RejectSlipDialog slip={summary} triggerProps={{ disabled: !!s.blocker }} onConfirm={(reason) => post(s.urls.reject, { reason })} />
            <a href={s.urls.payment} className="ml-auto inline-flex min-h-9 items-center gap-1 text-sm font-semibold text-primary hover:underline">
              Payment record <ExternalLink className="h-3.5 w-3.5" aria-hidden />
            </a>
          </div>
          {s.blocker ? (
            <p className="text-xs text-muted-foreground">{s.blocker}</p>
          ) : (
            <p className="text-xs text-muted-foreground">
              Look for {s.expectedRef}
              {s.transferRef && !refMatches ? ` or ${s.transferRef}` : ""} in the club's bank statement before approving.
            </p>
          )}
        </div>
      </div>

      <div className="min-w-0">
        <h4 className="mb-2 text-sm font-semibold">
          Slip{s.slips.length === 1 ? "" : "s"} ({s.slips.length})
        </h4>
        <AttachmentGallery items={s.slips} frozen />
      </div>
    </li>
  );
}

const outcomeMeta: Record<
  HandledSlip["outcome"],
  {
    label: string;
    tone: "success" | "danger" | "warning";
    Icon: typeof CheckCircle2;
  }
> = {
  approved: { label: "Approved", tone: "success", Icon: CheckCircle2 },
  rejected: { label: "Rejected", tone: "danger", Icon: XCircle },
  gateway_paid: {
    label: "Paid online too",
    tone: "warning",
    Icon: AlertTriangle,
  },
};

function HandledList({ items }: { items: HandledSlip[] }) {
  if (items.length === 0) {
    return <p className="rounded-lg border bg-card p-6 text-center text-sm text-muted-foreground">Nothing handled yet.</p>;
  }
  return (
    <ul className="flex flex-col divide-y rounded-lg border bg-card">
      {items.map((h) => {
        const m = outcomeMeta[h.outcome];
        return (
          <li key={h.id} className="flex flex-col gap-2 p-4 sm:flex-row sm:items-start sm:gap-4">
            <div className="flex min-w-0 flex-1 items-start gap-3">
              <Avatar name={h.member} />
              <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-center gap-2">
                  <span className="truncate text-sm font-semibold">{h.member}</span>
                  <StatusChip Icon={m.Icon} tone={m.tone}>
                    {m.label}
                  </StatusChip>
                </div>
                <div className="truncate text-xs text-muted-foreground">
                  {h.periodStart && h.periodEnd
                    ? feeLabel({
                        periodStart: h.periodStart,
                        periodEnd: h.periodEnd,
                      })
                    : `Payment #${h.paymentId}`}{" "}
                  · {h.by} · {fmtDateTime(h.at)}
                </div>
                {h.outcome === "rejected" && h.reason && <p className="mt-1 text-sm">“{h.reason}”</p>}
                {h.outcome === "approved" && (
                  <p className="mt-1 text-sm text-muted-foreground">
                    Paid {h.paidDate ? fmtDate(h.paidDate) : ""}
                    {h.reference ? ` · ref ${h.reference}` : ""}
                  </p>
                )}
                {h.outcome === "gateway_paid" && (
                  <p className="mt-1 text-sm text-warning-foreground">
                    Paid online while a transfer slip was also sent
                    {h.reference ? ` (txn ${h.reference})` : ""}. If both payments reached the club, refund one to the member.
                  </p>
                )}
              </div>
            </div>
            <div className="flex items-center justify-between gap-3 pl-12 sm:flex-col sm:items-end sm:pl-0">
              <Money sen={h.amount} className="text-sm font-bold" />
              {h.url && (
                <div className="flex items-center gap-3">
                  <a href={`${h.url}#slips`} className="text-xs font-semibold text-primary hover:underline">
                    View slips
                  </a>
                  <a href={h.url} className="text-xs font-semibold text-primary hover:underline">
                    Payment record
                  </a>
                </div>
              )}
            </div>
          </li>
        );
      })}
    </ul>
  );
}

export default function VerificationsIndex({ nav, club, tab: initialTab, pending, handled, today, urls }: Props) {
  const [tab, setTab] = useState(initialTab);
  const total = pending.reduce((s, p) => s + p.amount, 0);
  return (
    <AdminLayout nav={nav} active="verifications" title="Payment verifications">
      <PageHeader
        eyebrow="Pengesahan bayaran"
        title="Payment verifications"
        description="Members who paid by bank transfer or DuitNow sent these slips. Check each one against the club's bank statement, then approve or reject it."
        actions={
          <a href={urls.billing} className="inline-flex min-h-9 items-center gap-1.5 text-sm font-semibold text-primary hover:underline">
            <Landmark className="h-4 w-4" aria-hidden /> Bank details
          </a>
        }
      />

      {!club.transferReady && (
        <Alert variant="info">
          <Landmark aria-hidden />
          <div>
            <AlertTitle>Bank transfer is off for members</AlertTitle>
            <AlertDescription>
              New slips can't be sent until you add the club's bank details and turn it on in{" "}
              <a href={urls.billing} className="font-semibold underline">
                Billing settings
              </a>
              . Slips already sent still show here.
            </AlertDescription>
          </div>
        </Alert>
      )}

      <Tabs value={tab} onValueChange={(v) => setTab(v as Props["tab"])}>
        <TabsList>
          <TabsTrigger value="pending">
            <Clock className="h-4 w-4" aria-hidden /> Waiting
            <span className="rounded-full bg-background/70 px-1.5 font-mono text-[11px]">{pending.length}</span>
          </TabsTrigger>
          <TabsTrigger value="handled">
            <BadgeCheck className="h-4 w-4" aria-hidden /> Recently handled
            <span className="rounded-full bg-background/70 px-1.5 font-mono text-[11px]">{handled.length}</span>
          </TabsTrigger>
        </TabsList>
      </Tabs>

      {tab === "pending" ? (
        pending.length === 0 ? (
          <div className="flex flex-col items-center gap-2 rounded-lg border bg-card p-10 text-center">
            <Inbox className="h-8 w-8 text-muted-foreground" strokeWidth={1.5} aria-hidden />
            <p className="font-semibold">No slips waiting</p>
            <p className="text-sm text-muted-foreground">When a member sends a transfer slip it shows up here.</p>
          </div>
        ) : (
          <>
            <p className="text-sm text-muted-foreground">
              {pending.length} slip
              {pending.length === 1 ? "" : "s"} · <Money sen={total} /> in total · oldest first
            </p>
            <ul className="flex flex-col gap-4">
              {pending.map((s) => (
                <SlipCard key={s.id} s={s} today={today} />
              ))}
            </ul>
          </>
        )
      ) : (
        <HandledList items={handled} />
      )}
    </AdminLayout>
  );
}
