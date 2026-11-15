import { useState } from "react";
import { router } from "@inertiajs/react";
import { AlertTriangle, BadgeCheck, CheckCircle2, Clock, ExternalLink, Inbox, Landmark, Layers, XCircle } from "lucide-react";
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
  /** "Bayar semua": one slip for several fees, approved or rejected as a set. */
  group: SlipGroup | null;
  blocker: string | null;
  urls: { approve: string; reject: string; payment: string };
}

/** TransferVerificationController::groupCard(). */
export interface SlipGroup {
  id: number;
  reference: string;
  totalSen: number;
  /** What approving marks paid: totalSen minus fees paid online while the slip was checked. */
  approveSen: number;
  paidOnlineSen: number;
  fees: {
    id: number;
    invoiceNo: string;
    periodStart: string;
    periodEnd: string;
    frequency: "monthly" | "quarterly" | "yearly";
    amount: number;
    status: string;
    url: string;
  }[];
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
  const g = s.group;
  const amount = g ? g.totalSen : s.amount;
  // Fees paid online meanwhile are left out of the approval (their share is flagged for a refund).
  const approveAmount = g ? g.approveSen : s.amount;
  const label = g ? `${g.fees.length} fees: ${fmtPeriod(g.fees[0].periodStart, g.fees[g.fees.length - 1].periodEnd, "en")}` : feeLabel(s);
  const summary: SlipSummary = {
    id: s.id,
    member: s.member,
    amount: approveAmount,
    feeLabel: label,
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
              <Money sen={amount} className="font-display text-2xl font-bold" />
            </div>
            <div className="text-sm text-muted-foreground">
              {g ? (
                <span className="inline-flex flex-wrap items-center gap-x-2 gap-y-1">
                  <StatusChip Icon={Layers} tone="info">
                    Pay all · {g.fees.length} fees
                  </StatusChip>
                  <span>One slip for the whole set: approve or reject them together.</span>
                </span>
              ) : (
                <span className="block truncate">
                  {feeLabel(s)} · {freq[s.frequency]} · {s.invoiceNo}
                </span>
              )}
            </div>
          </div>
        </div>

        {g && (
          <div className="overflow-hidden rounded-md border" data-testid="slip-group">
            <table className="w-full text-sm">
              <caption className="sr-only">Fees covered by this slip</caption>
              <thead className="bg-muted/60 text-left text-xs text-muted-foreground">
                <tr>
                  <th scope="col" className="px-3 py-2 font-medium">Fee</th>
                  <th scope="col" className="hidden px-3 py-2 font-medium sm:table-cell">Invoice</th>
                  <th scope="col" className="px-3 py-2 text-right font-medium">Amount</th>
                </tr>
              </thead>
              <tbody className="divide-y">
                {g.fees.map((f) => (
                  <tr key={f.id}>
                    <td className="px-3 py-2">
                      <a href={f.url} className="hover:underline">
                        {feeLabel(f)}
                      </a>
                      <span className="block text-xs text-muted-foreground">
                        {freq[f.frequency]}
                        <span className="sm:hidden"> · {f.invoiceNo}</span>
                        {f.status !== "pending_verification" && <span className="font-semibold text-danger-foreground"> · now {f.status.replace("_", " ")}</span>}
                      </span>
                    </td>
                    <td className="hidden px-3 py-2 font-mono text-xs sm:table-cell">{f.invoiceNo}</td>
                    <td className="px-3 py-2 text-right">
                      <Money sen={f.amount} />
                    </td>
                  </tr>
                ))}
              </tbody>
              <tfoot className="border-t bg-muted/40">
                <tr>
                  <th scope="row" colSpan={2} className="hidden px-3 py-2 text-left font-semibold sm:table-cell">
                    Total
                  </th>
                  <th scope="row" className="px-3 py-2 text-left font-semibold sm:hidden">
                    Total
                  </th>
                  <td className="px-3 py-2 text-right font-bold">
                    <Money sen={g.totalSen} />
                  </td>
                </tr>
              </tfoot>
            </table>
          </div>
        )}

        {g && g.paidOnlineSen > 0 && (
          <Alert variant="warning" data-testid="slip-group-paid-online">
            <AlertTriangle aria-hidden />
            <AlertTitle>Part of this set was paid online while the slip was being checked</AlertTitle>
            <AlertDescription>
              Approving marks the remaining fees paid (<Money sen={g.approveSen} />). If the transfer of <Money sen={g.totalSen} /> arrived, refund{" "}
              <Money sen={g.paidOnlineSen} /> to the member: it is flagged in the audit log.
            </AlertDescription>
          </Alert>
        )}

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
  const total = pending.reduce((s, p) => s + (p.group ? p.group.totalSen : p.amount), 0);
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
