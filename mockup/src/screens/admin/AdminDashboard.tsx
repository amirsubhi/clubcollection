import { ArrowRight, Banknote, Clock, FilePlus2, HandHeart, Landmark, ReceiptText, TrendingDown, Wallet, CheckCircle2 } from "lucide-react";
import { AdminShell } from "@/components/app/AdminShell";
import { PageHeader } from "@/components/app/PageHeader";
import { Money } from "@/components/app/Money";
import { CategoryBar, CollectedSpentChart, KpiTile } from "@/components/app/Charts";
import { FundStatusBadge, StatusChip } from "@/components/app/StatusBadges";
import { Avatar, IconBubble, type Tone } from "@/components/app/Visuals";
import { Button } from "@/components/ui/button";
import { fundItems } from "@/data/fund";
import { recentActivity, type Activity } from "@/data/dashboard";
import { ytd } from "@/data/report";
import { currentAdmin } from "@/data/club";
import { fmtDate, fmtDateTime, rm } from "@/lib/format";

const activityIcon: Record<Activity["kind"], typeof Wallet> = {
  fee: Wallet,
  cash: Banknote,
  expense: ReceiptText,
  welfare: HandHeart,
  approved: CheckCircle2,
};

function Panel({ title, sub, action, children, className }: { title: string; sub?: string; action?: React.ReactNode; children: React.ReactNode; className?: string }) {
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

export function AdminDashboard() {
  const submitted = fundItems.filter((i) => i.status === "submitted");
  const waitingSum = submitted.reduce((s, i) => s + i.amount, 0);
  const mine = submitted.filter((i) => i.awaitingMe);
  const totalOut = ytd.spent + ytd.welfarePaid + ytd.bankCharges;
  return (
    <AdminShell active="admin-dashboard">
      <PageHeader
        eyebrow="Papan pemuka · FY 2026"
        title={`Selamat petang, ${currentAdmin.name.split(" ").slice(1).join(" ")}`}
        description="Where the club fund stands today, and what needs a second pair of eyes."
        actions={
          <>
            <Button asChild variant="outline">
              <a href="#fund-approve">
                <Clock aria-hidden /> Review {mine.length} waiting
              </a>
            </Button>
            <Button asChild>
              <a href="#fund-new">
                <FilePlus2 aria-hidden /> New expense
              </a>
            </Button>
          </>
        }
      />

      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <KpiTile label="Fund balance" value={rm(ytd.balance)} delta="+RM 27,348" deltaGood hint="since 1 Jan" Icon={Landmark} tone="accent" />
        <KpiTile label="Collected this year" value={rm(ytd.collected)} delta="+6.8%" deltaGood hint="vs Jan–Nov 2025" Icon={Wallet} tone="accent" />
        <KpiTile label="Spent this year" value={rm(totalOut)} delta="+12.4%" deltaGood={false} hint="vs Jan–Nov 2025" Icon={TrendingDown} tone="accent" />
        <KpiTile label="Awaiting approval" value={`${submitted.length} records`} hint={`${rm(waitingSum)} · ${mine.length} need you`} Icon={Clock} tone="accent" />
      </div>

      <div className="grid min-w-0 gap-4 lg:grid-cols-3">
        <Panel title="Collected vs spent" sub="Approved items only · Jan–Nov 2026, RM per month" className="lg:col-span-2">
          <CollectedSpentChart lang="en" height={280} />
        </Panel>

        <Panel
          title="Approvals queue"
          sub="Menunggu kelulusan"
          action={
            <a href="#fund-usage" className="inline-flex min-h-9 items-center gap-1 text-sm font-semibold text-primary hover:underline">
              All <ArrowRight className="h-4 w-4" aria-hidden />
            </a>
          }
        >
          <ul className="flex flex-col divide-y">
            {submitted.map((i) => (
              <li key={i.id} className="flex flex-col gap-2 py-3 first:pt-0 last:pb-0">
                <div className="flex items-start gap-3">
                  <Avatar name={i.recordedBy} />
                  <div className="min-w-0 flex-1">
                    <div className="flex items-baseline justify-between gap-2">
                      <span className="font-mono text-xs text-muted-foreground">{i.ref}</span>
                      <Money sen={i.amount} className="text-sm font-bold" />
                    </div>
                    <div className="truncate text-sm font-semibold">{i.payee}</div>
                    <div className="truncate text-xs text-muted-foreground">
                      by {i.recordedBy.split(" ")[0]} · {fmtDate(i.paidDate ?? i.invoiceDate)}
                    </div>
                  </div>
                </div>
                <div className="flex items-center justify-between gap-2 pl-12">
                  <FundStatusBadge status={i.status} />
                  {i.awaitingMe ? (
                    <Button asChild size="sm" variant="default" className="h-8">
                      <a href={i.type === "welfare" ? "#fund-approve" : "#fund-item"}>Review</a>
                    </Button>
                  ) : (
                    <span className="text-xs text-muted-foreground">You recorded this</span>
                  )}
                </div>
              </li>
            ))}
          </ul>
        </Panel>
      </div>

      <div className="grid min-w-0 gap-4 lg:grid-cols-3">
        <Panel title="Where the money went" sub="Share of RM spent, Jan–Nov 2026">
          <CategoryBar lang="en" />
          <p className="text-sm text-muted-foreground">
            Annual dinner is the biggest single cost; welfare is a quarter of all spending ({rm(ytd.welfarePaid)}, {ytd.welfareCount} cases).
          </p>
        </Panel>

        <Panel
          title="Recent activity"
          sub="Latest 5, newest first"
          className="lg:col-span-2"
          action={
            <a href="#fund-usage" className="inline-flex min-h-9 items-center gap-1 text-sm font-semibold text-primary hover:underline">
              See all <ArrowRight className="h-4 w-4" aria-hidden />
            </a>
          }
        >
          <ul className="flex flex-col divide-y">
            {recentActivity.map((a) => {
              const Icon = activityIcon[a.kind];
              return (
                <li key={a.id}>
                  <a href={`#${a.hash}`} className="-mx-2 flex min-h-12 items-center gap-3 rounded-md px-2 py-2 hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring">
                    <IconBubble Icon={Icon} tone={a.tone as Tone} />
                    <div className="min-w-0 flex-1">
                      <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                        <span className="truncate text-sm font-semibold">{a.title}</span>
                        {a.pending && (
                          <StatusChip Icon={Clock} tone="warning">
                            Not counted yet
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
                      <span className="text-[11px] font-medium text-muted-foreground">{a.direction === "in" ? "Money in" : "Money out"}</span>
                    </div>
                  </a>
                </li>
              );
            })}
          </ul>
        </Panel>
      </div>
    </AdminShell>
  );
}
