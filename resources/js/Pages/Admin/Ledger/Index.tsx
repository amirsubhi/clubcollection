import { useMemo, useState, type FormEvent, type ReactNode } from "react";
import { router } from "@inertiajs/react";
import { ArrowDownLeft, ArrowUpRight, BarChart3, CalendarRange, CheckCircle2, Clock, FileDown, FileSpreadsheet, Hourglass, Landmark, PencilLine, Table2, TriangleAlert, Undo2 } from "lucide-react";
import { AdminLayout } from "@/components/app/AdminLayout";
import { PageHeader } from "@/components/app/PageHeader";
import { CollectedSpentChart, KpiTile, SpentSplit } from "@/components/app/Charts";
import { StatusChip } from "@/components/app/StatusBadges";
import { Button } from "@/components/ui/button";
import { DateHint, Input, Label, FieldError, FieldHint } from "@/components/ui/input";
import { fmtDate, fmtMonth, rm } from "@/lib/format";
import { cn } from "@/lib/utils";
import type { AdminNavProps, FigureWindow } from "@/types/admin";
import type { MonthPoint } from "@/types/domain";

type Preset = "year" | "12m" | "custom";

interface Txn {
  n: number;
  date: string;
  direction: "in" | "out";
  /** "charges": bank / gateway charges the club absorbed, one row per month. */
  kind: "fee" | "expense" | "welfare" | "charges";
  description: string;
  party: string;
  reference: string;
  amount: number;
  balance: number;
}

interface MonthRow {
  key: string;
  month: string;
  in: number;
  out: number;
  net: number;
  balance: number;
}

interface OutstandingRow {
  id: number;
  member: string;
  periodStart: string;
  dueDate: string | null;
  amount: number;
  status: "overdue" | "pending" | "verifying";
}

interface Props {
  nav: AdminNavProps;
  clubName: string;
  filters: { from: string; to: string; preset: Preset; openingBalance: string };
  presets: FigureWindow[];
  maxMonths: number;
  today: string;
  range: string;
  /** Opening balance: from FundFigures at the From date, or typed by an admin. All money in sen. */
  opening: { amount: number; source: "computed" | "manual"; computed: number; asOf: string };
  summary: { opening: number; in: number; out: number; outExpenses: number; outWelfare: number; outCharges: number; net: number; closing: number; inCount: number; outCount: number };
  monthly: MonthRow[];
  transactions: Txn[];
  outstanding: { rows: OutstandingRow[]; overdue: number; pending: number; verifying: number; total: number };
  urls: { index: string; csv: string; pdf: string; dashboard: string };
}

const PAGE = 100;
const PAGE_PHONE = 25;

const kindLabel: Record<Txn["kind"], string> = { fee: "Fee", expense: "Expense", welfare: "Welfare", charges: "Bank charges" };

/** The latest To date the server accepts: From plus N months, without overflow (Carbon addMonthsNoOverflow). */
function latestTo(from: string, months: number) {
  const [fy, fMonth, fd] = from.split("-").map(Number);
  const total = fy * 12 + (fMonth - 1) + months;
  const tYear = Math.floor(total / 12);
  const tMonth = (total % 12) + 1;
  const lastDay = new Date(Date.UTC(tYear, tMonth, 0)).getUTCDate();
  return `${tYear}-${String(tMonth).padStart(2, "0")}-${String(Math.min(fd, lastDay)).padStart(2, "0")}`;
}

function Section({ title, sub, action, children, className, id }: { title: string; sub?: ReactNode; action?: ReactNode; children: ReactNode; className?: string; id?: string }) {
  return (
    <section aria-labelledby={id} className={cn("flex min-w-0 flex-col gap-4 rounded-lg border bg-card p-4 sm:p-5", className)}>
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0">
          <h2 id={id} className="font-display text-lg font-bold tracking-tight">
            {title}
          </h2>
          {sub && <p className="text-sm text-muted-foreground">{sub}</p>}
        </div>
        {action}
      </div>
      {children}
    </section>
  );
}

/** Signed amount with the direction in words, never colour alone. */
function Direction({ dir }: { dir: "in" | "out" }) {
  return dir === "in" ? (
    <span className="inline-flex items-center gap-1 text-xs font-semibold text-success-foreground">
      <ArrowDownLeft className="h-3.5 w-3.5" aria-hidden /> Money in
    </span>
  ) : (
    <span className="inline-flex items-center gap-1 text-xs font-semibold text-danger-foreground">
      <ArrowUpRight className="h-3.5 w-3.5" aria-hidden /> Money out
    </span>
  );
}

function Balance({ sen, className }: { sen: number; className?: string }) {
  return <span className={cn("money", sen < 0 && "text-danger-foreground", className)}>{rm(sen)}</span>;
}

function PeriodFilter({ filters, presets, maxMonths, today, url, opening }: Pick<Props, "filters" | "presets" | "maxMonths" | "today" | "opening"> & { url: string }) {
  const [custom, setCustom] = useState(filters.preset === "custom");
  const [from, setFrom] = useState(filters.from);
  const [to, setTo] = useState(filters.to);
  const [editOpening, setEditOpening] = useState(false);
  const [openingInput, setOpeningInput] = useState(filters.openingBalance || (opening.amount / 100).toFixed(2));

  const go = (params: Record<string, string>) => router.get(url, params, { preserveScroll: true, preserveState: false });
  // A typed opening balance belongs to its From date; moving From drops it (the server checks opening_for too).
  const keepOpening = (newFrom: string): Record<string, string> =>
    filters.openingBalance && newFrom === filters.from ? { opening_balance: filters.openingBalance, opening_for: filters.from } : {};

  const rangeError =
    !from || !to ? "Choose both dates." : from > to ? "The From date must be on or before the To date." : to > latestTo(from, maxMonths) ? `Choose ${maxMonths} months or less: the To date can be ${fmtDate(latestTo(from, maxMonths))} at the latest.` : null;

  const applyCustom = (e: FormEvent) => {
    e.preventDefault();
    if (!rangeError) go({ from, to, ...keepOpening(from) });
  };

  const openingError = openingInput.trim() === "" || !/^\d{1,9}(\.\d{1,2})?$/.test(openingInput.trim()) ? "Enter an amount in ringgit, e.g. 1250.00." : null;

  const tabs: { key: Preset; label: string; window?: FigureWindow }[] = [
    ...presets.map((p) => ({ key: p.key as Preset, label: p.key === "year" ? "This year" : p.label, window: p })),
    { key: "custom", label: "Custom dates" },
  ];
  const selected: Preset = custom ? "custom" : filters.preset;

  return (
    <div className="flex flex-col gap-4 rounded-lg border bg-card p-4 sm:p-5">
      <div className="flex flex-col gap-2">
        <span id="period-label" className="text-sm font-medium">
          Period
        </span>
        <div role="group" aria-labelledby="period-label" className="flex flex-wrap gap-2">
          {tabs.map((t) => {
            const on = selected === t.key;
            return (
              <button
                key={t.key}
                type="button"
                aria-pressed={on}
                onClick={() => {
                  if (t.window) {
                    setCustom(false);
                    go({ from: t.window.from, to: t.window.to, ...keepOpening(t.window.from) });
                  } else {
                    setCustom(true);
                  }
                }}
                className={cn(
                  "inline-flex min-h-10 flex-col items-start justify-center rounded-md border px-3 py-1.5 text-left text-sm transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring",
                  on ? "border-primary bg-accent text-accent-foreground" : "bg-card hover:bg-muted",
                )}
              >
                <span className="font-semibold">{t.label}</span>
                {t.window && <span className={cn("text-xs", on ? "text-accent-foreground" : "text-muted-foreground")}>{t.window.range}</span>}
              </button>
            );
          })}
        </div>
      </div>

      {selected === "custom" && (
        <form onSubmit={applyCustom} className="flex flex-col gap-2" noValidate>
          <div className="flex flex-col gap-3 sm:flex-row sm:items-end">
            <div className="flex flex-col gap-1.5">
              <Label htmlFor="ledger-from">From</Label>
              <Input id="ledger-from" type="date" value={from} max={to || today} onChange={(e) => setFrom(e.target.value)} invalid={!!rangeError} className="sm:w-44" />
              <DateHint value={from} />
            </div>
            <div className="flex flex-col gap-1.5">
              <Label htmlFor="ledger-to">To</Label>
              <Input id="ledger-to" type="date" value={to} min={from} onChange={(e) => setTo(e.target.value)} invalid={!!rangeError} className="sm:w-44" />
              <DateHint value={to} />
            </div>
            {/* Lines up with the inputs, above the written-out dates. */}
            <Button type="submit" disabled={!!rangeError} className="sm:mb-[1.375rem]">
              <CalendarRange aria-hidden /> Show this period
            </Button>
          </div>
          {rangeError ? <FieldError>{rangeError}</FieldError> : <FieldHint>Up to {maxMonths} months at a time.</FieldHint>}
        </form>
      )}

      <div className="flex flex-col gap-2 border-t pt-4">
        <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
          <span className="font-medium">Opening balance</span>
          <span className="money font-semibold">{rm(opening.amount)}</span>
          {opening.source === "manual" ? (
            <span className="text-muted-foreground">
              Set by hand; the records say {rm(opening.computed)} · <span lang="ms">diubah secara manual</span>
            </span>
          ) : (
            <span className="text-muted-foreground">Fund balance at the end of {fmtDate(opening.asOf)}, from the records</span>
          )}
        </div>
        {editOpening ? (
          <form
            className="flex flex-col gap-2"
            noValidate
            onSubmit={(e) => {
              e.preventDefault();
              if (!openingError) go({ from: filters.from, to: filters.to, opening_balance: openingInput.trim(), opening_for: filters.from });
            }}
          >
            <div className="flex flex-col gap-3 sm:flex-row sm:items-end">
              <div className="flex flex-col gap-1.5">
                <Label htmlFor="ledger-opening">Opening balance (RM)</Label>
                <Input
                  id="ledger-opening"
                  inputMode="decimal"
                  value={openingInput}
                  onChange={(e) => setOpeningInput(e.target.value)}
                  invalid={!!openingError}
                  className="sm:w-44"
                  aria-describedby="ledger-opening-help"
                />
              </div>
              <div className="flex gap-2">
                <Button type="submit" disabled={!!openingError}>
                  Use this amount
                </Button>
                <Button type="button" variant="ghost" onClick={() => setEditOpening(false)}>
                  Cancel
                </Button>
              </div>
            </div>
            <p id="ledger-opening-help" className="text-xs text-muted-foreground">
              {openingError ?? "Only for money the club held before it used this system. The page and exports will mark it as set manually."}
            </p>
          </form>
        ) : (
          <div className="flex flex-wrap gap-2">
            <Button type="button" variant="outline" size="sm" onClick={() => setEditOpening(true)}>
              <PencilLine aria-hidden /> Set manually
            </Button>
            {opening.source === "manual" && (
              <Button type="button" variant="ghost" size="sm" onClick={() => go({ from: filters.from, to: filters.to })}>
                <Undo2 aria-hidden /> Use the records again
              </Button>
            )}
          </div>
        )}
      </div>
    </div>
  );
}

function MonthlySection({ monthly, range, className }: { monthly: MonthRow[]; range: string; className?: string }) {
  const [chart, setChart] = useState(false);
  const points: MonthPoint[] = monthly.map((m) => {
    const [mon, yr] = m.month.split(" ");
    return { month: mon, monthEn: mon, year: Number(yr), collected: m.in, spent: m.out };
  });
  return (
    <Section
      id="ledger-monthly"
      className={className}
      title="Month by month"
      sub="Money in and out per month, with the balance at each month end"
      action={
        <Button type="button" variant="outline" size="sm" aria-pressed={chart} onClick={() => setChart((c) => !c)}>
          {chart ? <Table2 aria-hidden /> : <BarChart3 aria-hidden />} {chart ? "Show as table" : "Show as chart"}
        </Button>
      }
    >
      {chart ? (
        <CollectedSpentChart data={points} labels={{ collected: "Money in", spent: "Money out" }} windowLabel={range} showTable={false} height={260} />
      ) : (
        <div className="-mx-4 overflow-x-auto sm:mx-0">
          <table className="w-full text-[13px] sm:min-w-[34rem] sm:text-sm">
            <thead>
              <tr className="border-b text-left text-xs text-muted-foreground">
                <th scope="col" className="px-4 py-2 font-semibold sm:pl-0">Month</th>
                <th scope="col" className="px-2 py-2 text-right font-semibold">Money in</th>
                <th scope="col" className="px-2 py-2 text-right font-semibold">Money out</th>
                <th scope="col" className="hidden px-2 py-2 text-right font-semibold sm:table-cell">Net</th>
                <th scope="col" className="px-4 py-2 text-right font-semibold sm:pr-0">
                  Balance<span className="hidden sm:inline"> at month end</span>
                </th>
              </tr>
            </thead>
            <tbody>
              {monthly.map((m) => (
                <tr key={m.key} className="border-b last:border-0">
                  <th scope="row" className="whitespace-nowrap py-2 pl-4 pr-1 text-left font-medium sm:pl-0 sm:pr-2">{m.month}</th>
                  <td className="money px-1 py-2 text-right sm:px-2">{m.in > 0 ? `+${rm(m.in)}` : "—"}</td>
                  <td className="money px-1 py-2 text-right sm:px-2">{m.out > 0 ? `−${rm(m.out)}` : "—"}</td>
                  <td className="money hidden px-2 py-2 text-right text-muted-foreground sm:table-cell">{m.net === 0 ? "—" : `${m.net > 0 ? "+" : "−"}${rm(Math.abs(m.net))}`}</td>
                  <td className="py-2 pl-1 pr-4 text-right font-semibold sm:pl-2 sm:pr-0">
                    <Balance sen={m.balance} />
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </Section>
  );
}

const outstandingMeta: Record<OutstandingRow["status"], { label: string; tone: "danger" | "warning" | "info"; Icon: typeof Clock }> = {
  overdue: { label: "Overdue", tone: "danger", Icon: TriangleAlert },
  pending: { label: "Not paid yet", tone: "warning", Icon: Clock },
  verifying: { label: "Slip being checked", tone: "info", Icon: Hourglass },
};

function OutstandingSection({ outstanding, className }: { outstanding: Props["outstanding"]; className?: string }) {
  const [all, setAll] = useState(false);
  const rows = all ? outstanding.rows : outstanding.rows.slice(0, 8);
  return (
    <Section
      id="ledger-outstanding"
      className={className}
      title="Fees not yet received"
      sub="All open fees, whatever the period. They are not in the balance until paid."
      action={outstanding.rows.length > 0 ? <span className="money text-sm font-semibold">{rm(outstanding.total)}</span> : undefined}
    >
      {outstanding.rows.length === 0 ? (
        <p className="flex items-center gap-2 text-sm text-muted-foreground">
          <CheckCircle2 className="h-4 w-4 text-success-foreground" aria-hidden /> Every fee has been paid.
        </p>
      ) : (
        <>
          <div className="flex flex-wrap gap-2 text-xs">
            {outstanding.overdue > 0 && <StatusChip Icon={TriangleAlert} tone="danger">Overdue {rm(outstanding.overdue)}</StatusChip>}
            {outstanding.pending > 0 && <StatusChip Icon={Clock} tone="warning">Not paid yet {rm(outstanding.pending)}</StatusChip>}
            {outstanding.verifying > 0 && <StatusChip Icon={Hourglass} tone="info">Slip being checked {rm(outstanding.verifying)}</StatusChip>}
          </div>
          <ul className="flex flex-col divide-y">
            {rows.map((r) => {
              const m = outstandingMeta[r.status];
              return (
                <li key={r.id} className="flex flex-col gap-1 py-2.5 text-sm">
                  <div className="flex items-baseline justify-between gap-3">
                    <span className="min-w-0 truncate font-medium" title={r.member}>
                      {r.member}
                    </span>
                    <span className="money shrink-0 font-semibold">{rm(r.amount)}</span>
                  </div>
                  <div className="flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
                    <span className="text-xs text-muted-foreground">
                      {fmtMonth(r.periodStart)}
                      {r.dueDate ? ` · due ${fmtDate(r.dueDate)}` : ""}
                    </span>
                    <StatusChip Icon={m.Icon} tone={m.tone}>
                      {m.label}
                    </StatusChip>
                  </div>
                </li>
              );
            })}
          </ul>
          {outstanding.rows.length > rows.length || all ? (
            <Button type="button" variant="outline" size="sm" className="self-start" onClick={() => setAll((a) => !a)}>
              {all ? "Show fewer" : `Show all ${outstanding.rows.length}`}
            </Button>
          ) : null}
        </>
      )}
    </Section>
  );
}

function TransactionsSection({ transactions, summary, opening, from, to }: { transactions: Txn[]; summary: Props["summary"]; opening: Props["opening"]; from: string; to: string }) {
  // Phones start with 25 lines, wider screens with 100.
  const [shown, setShown] = useState(() => (typeof window !== "undefined" && window.matchMedia("(max-width: 767px)").matches ? PAGE_PHONE : PAGE));
  const rows = transactions.slice(0, shown);
  const complete = rows.length === transactions.length;
  return (
    <Section
      id="ledger-transactions"
      title="Transactions"
      sub={`${transactions.length} in date order · ${fmtDate(from)} – ${fmtDate(to)}`}
    >
      {transactions.length === 0 ? (
        <p className="py-6 text-center text-sm text-muted-foreground">No money came in or went out in this period.</p>
      ) : (
        <>
          {/* Desktop: AGM-style statement */}
          <div className="-mx-4 hidden overflow-x-auto sm:mx-0 md:block">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b text-left text-xs text-muted-foreground">
                  <th scope="col" className="w-10 py-2 pr-2 font-semibold">#</th>
                  <th scope="col" className="w-28 px-2 py-2 font-semibold">Date</th>
                  <th scope="col" className="px-2 py-2 font-semibold">Description</th>
                  <th scope="col" className="w-28 px-2 py-2 font-semibold">Reference</th>
                  <th scope="col" className="w-32 px-2 py-2 text-right font-semibold">Money in</th>
                  <th scope="col" className="w-32 px-2 py-2 text-right font-semibold">Money out</th>
                  <th scope="col" className="w-36 py-2 pl-2 text-right font-semibold">Balance</th>
                </tr>
              </thead>
              <tbody>
                <tr className="border-b bg-muted/50">
                  <td className="py-2 pr-2 text-muted-foreground">—</td>
                  <td className="whitespace-nowrap px-2 py-2 text-muted-foreground">{fmtDate(from)}</td>
                  <td className="px-2 py-2 italic text-muted-foreground" colSpan={4}>
                    Opening balance brought forward{opening.source === "manual" ? " (diubah secara manual)" : ""}
                  </td>
                  <td className="py-2 pl-2 text-right font-semibold">
                    <Balance sen={opening.amount} />
                  </td>
                </tr>
                {rows.map((t) => (
                  <tr key={t.n} className="border-b align-top last:border-0 hover:bg-muted/40">
                    <td className="py-2 pr-2 text-muted-foreground">{t.n}</td>
                    <td className="whitespace-nowrap px-2 py-2">{fmtDate(t.date)}</td>
                    <td className="min-w-0 px-2 py-2">
                      <div className="font-medium">{t.description}</div>
                      <div className="flex flex-wrap items-center gap-x-2 text-xs text-muted-foreground">
                        <Direction dir={t.direction} />
                        <span>
                          {kindLabel[t.kind]} · {t.party}
                        </span>
                      </div>
                    </td>
                    <td className="px-2 py-2 font-mono text-xs text-muted-foreground">{t.reference || "—"}</td>
                    <td className="money px-2 py-2 text-right font-semibold">{t.direction === "in" ? `+${rm(t.amount)}` : ""}</td>
                    <td className="money px-2 py-2 text-right font-semibold">{t.direction === "out" ? `−${rm(t.amount)}` : ""}</td>
                    <td className="py-2 pl-2 text-right">
                      <Balance sen={t.balance} />
                    </td>
                  </tr>
                ))}
              </tbody>
              {complete && (
                <tfoot>
                  <tr className="border-t-2 border-double border-foreground/40 font-semibold">
                    <td className="py-2.5 pr-2" colSpan={4}>
                      Closing balance · {fmtDate(to)}
                    </td>
                    <td className="money px-2 py-2.5 text-right">+{rm(summary.in)}</td>
                    <td className="money px-2 py-2.5 text-right">−{rm(summary.out)}</td>
                    <td className="py-2.5 pl-2 text-right">
                      <Balance sen={summary.closing} />
                    </td>
                  </tr>
                </tfoot>
              )}
            </table>
          </div>

          {/* Phone: one card per line */}
          <ol className="flex flex-col divide-y md:hidden">
            <li className="flex items-center justify-between gap-3 py-2 text-sm text-muted-foreground">
              <span>
                Opening balance{opening.source === "manual" ? " (diubah secara manual)" : ""}
              </span>
              <Balance sen={opening.amount} className="font-semibold text-foreground" />
            </li>
            {rows.map((t) => (
              <li key={t.n} className="flex flex-col gap-1 py-3">
                <div className="flex items-start justify-between gap-3">
                  <span className="min-w-0 font-medium">{t.description}</span>
                  <span className="money shrink-0 font-semibold">
                    {t.direction === "in" ? "+" : "−"}
                    {rm(t.amount)}
                  </span>
                </div>
                <div className="flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-muted-foreground">
                  <Direction dir={t.direction} />
                  <span>{fmtDate(t.date)}</span>
                  <span>
                    {kindLabel[t.kind]} · {t.party}
                  </span>
                  {t.reference && <span className="font-mono">{t.reference}</span>}
                </div>
                <div className="text-xs text-muted-foreground">
                  Balance <Balance sen={t.balance} className="font-semibold text-foreground" />
                </div>
              </li>
            ))}
            {complete && (
              <li className="flex items-center justify-between gap-3 py-3 text-sm font-semibold">
                <span>Closing balance</span>
                <Balance sen={summary.closing} />
              </li>
            )}
          </ol>

          {!complete && (
            <div className="flex flex-wrap items-center gap-3 text-sm">
              <span className="text-muted-foreground">
                Showing {rows.length} of {transactions.length}.
              </span>
              <Button type="button" variant="outline" size="sm" onClick={() => setShown(transactions.length)}>
                Show all {transactions.length}
              </Button>
            </div>
          )}
        </>
      )}
    </Section>
  );
}

export default function LedgerIndex({ nav, clubName, filters, presets, maxMonths, today, range, opening, summary, monthly, transactions, outstanding, urls }: Props) {
  const periodLabel = useMemo(() => presets.find((p) => p.key === filters.preset)?.label, [presets, filters.preset]);
  return (
    <AdminLayout nav={nav} active="ledger" title="Ledger">
      <PageHeader
        eyebrow={`Lejar kewangan · ${clubName}`}
        title="Ledger"
        description="Every ringgit in and out for the period, with the running balance. The same figures as the dashboard, ready for the AGM."
        actions={
          <>
            <Button asChild variant="outline">
              <a href={urls.csv}>
                <FileSpreadsheet aria-hidden /> Export CSV
              </a>
            </Button>
            <Button asChild variant="outline">
              <a href={urls.pdf}>
                <FileDown aria-hidden /> Export PDF
              </a>
            </Button>
          </>
        }
      />

      <PeriodFilter key={`${filters.from}|${filters.to}|${filters.openingBalance}`} filters={filters} presets={presets} maxMonths={maxMonths} today={today} url={urls.index} opening={opening} />

      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <KpiTile
          label="Opening balance"
          window={opening.source === "manual" ? "(diubah secara manual)" : `End of ${fmtDate(opening.asOf)}`}
          value={rm(summary.opening)}
          hint={opening.source === "manual" ? `The records say ${rm(opening.computed)}` : "From the records"}
          Icon={Landmark}
        />
        <KpiTile label="Money in" window={range} value={`+${rm(summary.in)}`} hint={`${summary.inCount} fee payment${summary.inCount === 1 ? "" : "s"}`} Icon={ArrowDownLeft} tone="mint" />
        <KpiTile
          label="Money out"
          window={range}
          value={`−${rm(summary.out)}`}
          detail={
            <SpentSplit expenses={summary.outExpenses} welfare={summary.outWelfare} charges={summary.outCharges} />
          }
          hint={`${summary.outCount} approved record${summary.outCount === 1 ? "" : "s"} + bank charges`}
          Icon={ArrowUpRight}
          tone="danger"
        />
        <KpiTile
          label="Closing balance"
          window={`End of ${fmtDate(filters.to)}`}
          value={rm(summary.closing)}
          hint={`${summary.net >= 0 ? "Up" : "Down"} ${rm(Math.abs(summary.net))} over ${periodLabel ? periodLabel.toLowerCase() : "the period"}`}
          Icon={Landmark}
        />
      </div>

      <div className="grid min-w-0 gap-4 xl:grid-cols-5">
        <MonthlySection monthly={monthly} range={range} className="xl:col-span-3" />
        <OutstandingSection outstanding={outstanding} className="xl:col-span-2" />
      </div>

      <TransactionsSection transactions={transactions} summary={summary} opening={opening} from={filters.from} to={filters.to} />
    </AdminLayout>
  );
}
