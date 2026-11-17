import type { FundCategory } from "@/types/member";
import type { ReactNode } from "react";
import { Link } from "@inertiajs/react";
import { ArrowRight, Ban, CheckCircle2, Clock, CreditCard, FilePlus2, HandHeart, Hourglass, Landmark, ReceiptText, TrendingDown, Wallet } from "lucide-react";
import { AdminLayout } from "@/components/app/AdminLayout";
import { PageHeader } from "@/components/app/PageHeader";
import { Money } from "@/components/app/Money";
import { CollectedSpentChart } from "@/components/app/Charts";
import { KpiTile, SpentSplit } from "@/components/app/Kpi";
import { CategoryBar } from "@/components/app/CategoryBar";
import { toSlices } from "@/components/app/MemberParts";
import { FundStatusBadge } from "@/components/app/FundStatusBadge";
import { StatusChip } from "@/components/app/StatusBadges";
import { Avatar, IconBubble, type Tone } from "@/components/app/Visuals";
import { Button } from "@/components/ui/button";
import { fmtDate, fmtDateTime, fmtPeriod, rm } from "@/lib/format";
import type { AdminNavProps, FigureWindow, FundRow } from "@/types/admin";
import type { MonthPoint } from "@/types/domain";

interface Activity {
  id: string;
  kind: "fee" | "expense" | "welfare" | "approved";
  title: string;
  who: string;
  at: string;
  amount: number;
  direction: "in" | "out";
  pending: boolean;
  voidPending: boolean;
  url: string | null;
}

interface Props {
  nav: AdminNavProps;
  greetingName: string;
  year: number;
  kpis: {
    balance: number;
    balanceAsOf: string;
    window: FigureWindow;
    collectedYtd: number;
    /** Spent: approved expenses + welfare + absorbed bank / gateway charges (FundFigures). */
    spentYtd: number;
    expensesYtd: number;
    /** Gateway fees the club absorbed: bank charges, part of spentYtd. */
    chargesYtd: number;
    collectedDeltaPct: number | null;
    spentDeltaPct: number | null;
    awaiting: { count: number; sum: number; mine: number };
    welfareYtd: number;
    welfareCountYtd: number;
    gatewayFees: { absorbedYtd: number; payer: "club" | "member"; perBill: number };
  };
  monthly: MonthPoint[];
  monthlyWindow: FigureWindow;
  categories: FundCategory[];
  queue: (FundRow & { canApprove: boolean })[];
  /** Transfer slips waiting for an admin (TransferVerificationController::card, without files). */
  slips: {
    count: number;
    transferReady: boolean;
    /** One row per slip: a "Bayar semua" slip lists its lead (oldest) fee, how many fees it covers and their total. */
    items: { id: number; member: { name: string }; periodStart: string; periodEnd: string; amount: number; feeCount: number; transferDate: string | null; submittedAt: string | null }[];
  };
  activity: Activity[];
  urls: { fundUsage: string; awaitingMine: string; createExpense: string; classic: string; ledger: string; auditLog: string; billing: string; verifications: string };
}

const activityIcon: Record<Activity["kind"], { Icon: typeof Wallet; tone: Tone }> = {
  fee: { Icon: Wallet, tone: "mint" },
  expense: { Icon: ReceiptText, tone: "neutral" },
  welfare: { Icon: HandHeart, tone: "info" },
  approved: { Icon: CheckCircle2, tone: "accent" },
};

function Panel({ title, sub, action, children, className }: { title: string; sub?: string; action?: ReactNode; children: ReactNode; className?: string }) {
  return (
    <section className={`flex min-w-0 flex-col gap-4 rounded-lg border bg-card p-4 sm:p-5 ${className ?? ""}`}>
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0">
          <h2 className="font-display text-lg font-bold tracking-tight">{title}</h2>
          {sub && <p className="text-sm text-muted-foreground">{sub}</p>}
        </div>
        {action}
      </div>
      {children}
    </section>
  );
}

const delta = (pct: number | null) => (pct === null ? undefined : `${pct >= 0 ? "+" : "−"}${Math.abs(pct)}%`);

function greeting() {
  const h = new Date().getHours();
  return h < 12 ? "Good morning" : h < 18 ? "Good afternoon" : "Good evening";
}

/** Payment verifications: transfer slips members sent, oldest first. */
function SlipsPanel({ slips, url }: { slips: Props["slips"]; url: string }) {
  return (
    <Panel
      title="Transfer slips"
      sub={slips.count > 0 ? `${slips.count} waiting to be checked` : "Nothing to check"}
      action={
        <Link href={url} className="inline-flex min-h-9 items-center gap-1 text-sm font-semibold text-primary hover:underline">
          Check <ArrowRight className="h-4 w-4" aria-hidden />
        </Link>
      }
    >
      {slips.items.length === 0 ? (
        <p className="text-sm text-muted-foreground">No slips waiting.</p>
      ) : (
        <ul className="flex flex-col divide-y">
          {slips.items.map((s) => (
            <li key={s.id}>
              <Link href={url} className="-mx-2 flex min-h-12 items-center gap-3 rounded-md px-2 py-2 hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring">
                <IconBubble Icon={Hourglass} tone="warning" />
                <div className="min-w-0 flex-1">
                  <div className="truncate text-sm font-semibold">{s.member.name}</div>
                  <div className="truncate text-xs text-muted-foreground">
                    {s.feeCount > 1 ? `Pay all · ${s.feeCount} fees from ${fmtPeriod(s.periodStart, s.periodEnd)}` : `Fee ${fmtPeriod(s.periodStart, s.periodEnd)}`}
                    {s.transferDate ? ` · transferred ${fmtDate(s.transferDate)}` : ""}
                  </div>
                </div>
                <Money sen={s.amount} className="text-sm font-bold" />
              </Link>
            </li>
          ))}
        </ul>
      )}
    </Panel>
  );
}

export default function AdminDashboard({ nav, greetingName, year, kpis, monthly, monthlyWindow, categories, queue, slips, activity, urls }: Props) {
  const slices = toSlices(categories, { bm: "Lain-lain", en: "Others" });
  const spentTotal = categories.reduce((s, c) => s + c.amount, 0);
  return (
    <AdminLayout nav={nav} active="dashboard" title="Dashboard">
      <PageHeader
        eyebrow={`Dashboard · ${year}`}
        title={`${greeting()}, ${greetingName.split(" ")[0]}`}
        description="Where the club fund stands today, and what needs a second pair of eyes."
        actions={
          <>
            {kpis.awaiting.mine > 0 && (
              <Button asChild variant="outline">
                <Link href={urls.awaitingMine}>
                  <Clock aria-hidden /> Review {kpis.awaiting.mine} waiting
                </Link>
              </Button>
            )}
            <Button asChild>
              <Link href={urls.createExpense}>
                <FilePlus2 aria-hidden /> New expense
              </Link>
            </Button>
          </>
        }
      />

      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <KpiTile label="Fund balance" window={`As of ${fmtDate(kpis.balanceAsOf)}`} value={rm(kpis.balance)} hint="All fees collected minus everything spent" Icon={Landmark} />
        <KpiTile
          label="Collected"
          window={kpis.window.range}
          value={rm(kpis.collectedYtd)}
          delta={delta(kpis.collectedDeltaPct)}
          deltaGood={(kpis.collectedDeltaPct ?? 0) >= 0}
          hint={kpis.collectedDeltaPct === null ? "Paid fees" : "vs same days last year"}
          Icon={Wallet}
        />
        <KpiTile
          label="Spent"
          window={kpis.window.range}
          value={rm(kpis.spentYtd)}
          detail={<SpentSplit expenses={kpis.expensesYtd} welfare={kpis.welfareYtd} charges={kpis.chargesYtd} />}
          delta={delta(kpis.spentDeltaPct)}
          deltaGood={(kpis.spentDeltaPct ?? 0) <= 0}
          hint={kpis.spentDeltaPct === null ? "Approved records + bank charges" : "vs same days last year"}
          Icon={TrendingDown}
        />
        <KpiTile
          label="Awaiting approval"
          window="Waiting now"
          value={`${kpis.awaiting.count} record${kpis.awaiting.count === 1 ? "" : "s"}`}
          hint={kpis.awaiting.count === 0 ? "Nothing to approve" : `${rm(kpis.awaiting.sum)} · ${kpis.awaiting.mine} need you`}
          Icon={Clock}
        />
      </div>

      <div className="grid min-w-0 gap-4 lg:grid-cols-3">
        <Panel title="Collected vs spent" sub={`${monthlyWindow.label} · RM per month · spent is approved expenses, welfare and bank charges`} className="lg:col-span-2">
          <CollectedSpentChart data={monthly} lang="en" height={280} windowLabel={`${monthlyWindow.label} (${monthlyWindow.range})`} />
        </Panel>

        <div className="flex min-w-0 flex-col gap-4">
          {(slips.count > 0 || slips.transferReady) && <SlipsPanel slips={slips} url={urls.verifications} />}
          <Panel
            title="Approvals queue"
            sub="Waiting for a second admin"
            action={
              <Link href={urls.fundUsage} className="inline-flex min-h-9 items-center gap-1 text-sm font-semibold text-primary hover:underline">
                All <ArrowRight className="h-4 w-4" aria-hidden />
              </Link>
            }
          >
            {queue.length === 0 ? (
              <p className="text-sm text-muted-foreground">Nothing is waiting for approval.</p>
            ) : (
              <ul className="flex flex-col divide-y">
                {queue.map((i) => (
                  <li key={i.id} className="flex flex-col gap-2 py-3 first:pt-0 last:pb-0">
                    <div className="flex items-start gap-3">
                      <Avatar name={i.recordedBy} />
                      <div className="min-w-0 flex-1">
                        <div className="flex items-baseline justify-between gap-2">
                          <span className="font-mono text-xs text-muted-foreground">{i.ref}</span>
                          <Money sen={i.amount} className="text-sm font-bold" />
                        </div>
                        <div className="truncate text-sm font-semibold">{i.payee ?? `Welfare · ${i.category}`}</div>
                        <div className="truncate text-xs text-muted-foreground">
                          by {i.recordedBy.split(" ")[0]} · {fmtDate(i.paidDate ?? i.invoiceDate)}
                        </div>
                      </div>
                    </div>
                    <div className="flex items-center justify-between gap-2 pl-12">
                      <FundStatusBadge status={i.status} />
                      {i.canApprove ? (
                        <Button asChild size="sm" className="h-8">
                          <Link href={i.url}>Review</Link>
                        </Button>
                      ) : (
                        <Link href={i.url} className="text-xs text-muted-foreground hover:underline">
                          {i.recordedByMe ? "You recorded this" : "You can't approve this"}
                        </Link>
                      )}
                    </div>
                  </li>
                ))}
              </ul>
            )}
          </Panel>
        </div>
      </div>

      <div className="grid min-w-0 gap-4 lg:grid-cols-3">
        <Panel title="Where the money went" sub={`Share of spending · ${kpis.window.range}`}>
          {spentTotal > 0 ? (
            <>
              <CategoryBar slices={slices} lang="en" />
              <p className="text-sm text-muted-foreground">
                {rm(spentTotal)} spent
                {kpis.welfareCountYtd > 0 ? `, including ${rm(kpis.welfareYtd)} welfare (${kpis.welfareCountYtd} case${kpis.welfareCountYtd === 1 ? "" : "s"})` : ""}.
              </p>
            </>
          ) : (
            <p className="text-sm text-muted-foreground">No approved spending yet this year.</p>
          )}
          <p className="flex items-start gap-2 text-sm text-muted-foreground">
            <CreditCard className="mt-0.5 h-4 w-4 shrink-0" aria-hidden />
            <span>
              {kpis.gatewayFees.payer === "member" && kpis.gatewayFees.absorbedYtd === 0
                ? "Gateway fees are paid by members."
                : `Bank / gateway charges: the club absorbed ${rm(kpis.gatewayFees.absorbedYtd)} in gateway fees on online payments. They are counted in spent.`}
            </span>
          </p>
          <div className="mt-auto flex flex-wrap gap-x-4 gap-y-1 text-sm">
            <Link href={urls.ledger} className="font-semibold text-primary hover:underline">
              Ledger
            </Link>
            <a href={urls.classic} className="font-semibold text-primary hover:underline">
              Monthly report (classic)
            </a>
            <Link href={urls.billing} className="font-semibold text-primary hover:underline">
              Billing settings
            </Link>
          </div>
        </Panel>

        <Panel
          title="Recent activity"
          sub="Latest money events, newest first"
          className="lg:col-span-2"
          action={
            <a href={urls.auditLog} className="inline-flex min-h-9 items-center gap-1 text-sm font-semibold text-primary hover:underline">
              Audit log <ArrowRight className="h-4 w-4" aria-hidden />
            </a>
          }
        >
          {activity.length === 0 ? (
            <p className="text-sm text-muted-foreground">No activity yet.</p>
          ) : (
            <ul className="flex flex-col divide-y">
              {activity.map((a) => {
                const { Icon, tone } = activityIcon[a.kind];
                const inner = (
                  <>
                    <IconBubble Icon={Icon} tone={tone} />
                    <div className="min-w-0 flex-1">
                      <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                        <span className="truncate text-sm font-semibold">{a.title}</span>
                        {a.pending && (
                          <StatusChip Icon={Clock} tone="warning">
                            Not counted yet
                          </StatusChip>
                        )}
                        {a.voidPending && (
                          <StatusChip Icon={Ban} tone="danger">
                            Void requested
                          </StatusChip>
                        )}
                      </div>
                      <div className="truncate text-xs text-muted-foreground">
                        {a.who} · {fmtDateTime(a.at)}
                      </div>
                    </div>
                    <div className="flex flex-col items-end">
                      <span className="money text-sm font-bold">
                        {a.direction === "in" ? "+" : "−"}
                        {rm(a.amount)}
                      </span>
                      <span className="text-xs font-medium text-muted-foreground">{a.direction === "in" ? "Money in" : "Money out"}</span>
                    </div>
                  </>
                );
                const cls = "-mx-2 flex min-h-12 items-center gap-3 rounded-md px-2 py-2";
                return (
                  <li key={a.id}>
                    {a.url ? (
                      <Link href={a.url} className={`${cls} hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring`}>
                        {inner}
                      </Link>
                    ) : (
                      <div className={cls}>{inner}</div>
                    )}
                  </li>
                );
              })}
            </ul>
          )}
        </Panel>
      </div>
    </AdminLayout>
  );
}
