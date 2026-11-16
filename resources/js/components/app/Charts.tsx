import { useId } from "react";
import { ArrowDownRight, ArrowUpRight, Table2, type LucideIcon } from "lucide-react";
import { Bar, BarChart, CartesianGrid, ReferenceLine, XAxis, YAxis } from "recharts";
import { ChartContainer, ChartTooltip, ChartTooltipContent, type ChartConfig } from "@/components/ui/chart";
import { IconBubble, type Tone } from "./Visuals";
import type { MonthPoint } from "@/types/domain";
import { rm } from "@/lib/format";
import { cn } from "@/lib/utils";

/* ---------- KPI tile ---------- */

export function KpiTile({
  label,
  value,
  delta,
  deltaGood,
  hint,
  window: windowLabel,
  detail,
  Icon,
  tone = "accent",
  className,
}: {
  label: string;
  value: string;
  /** The period the figure covers, e.g. "1 Jan – 15 Nov 2026" (every money tile carries one). */
  window?: string;
  /** Extra line under the value, e.g. the spent split. */
  detail?: React.ReactNode;
  delta?: string;
  /** Whether the change is good news (colours the delta; the arrow carries direction). */
  deltaGood?: boolean;
  hint?: string;
  Icon?: LucideIcon;
  tone?: Tone;
  className?: string;
}) {
  const up = delta?.trim().startsWith("+");
  return (
    <div className={cn("flex min-w-0 flex-col gap-2 rounded-lg border bg-card p-4", className)}>
      <div className="flex items-start justify-between gap-2">
        <div className="min-w-0">
          <div className="text-sm font-medium text-muted-foreground">{label}</div>
          {windowLabel && <div className="text-xs text-muted-foreground">{windowLabel}</div>}
        </div>
        {Icon && <IconBubble Icon={Icon} tone={tone} />}
      </div>
      <div className="figure font-display text-[28px] font-bold leading-none tracking-tight lg:text-[30px]">{value}</div>
      {detail && <div className="text-xs text-muted-foreground">{detail}</div>}
      <div className="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs">
        {delta && (
          <span className={cn("inline-flex items-center gap-0.5 font-semibold", deltaGood ? "text-success-foreground" : "text-danger-foreground")}>
            {up ? <ArrowUpRight className="h-3.5 w-3.5" aria-hidden /> : <ArrowDownRight className="h-3.5 w-3.5" aria-hidden />}
            {delta}
          </span>
        )}
        {hint && <span className="text-muted-foreground">{hint}</span>}
      </div>
    </div>
  );
}

/** "Expenses RM a + Welfare RM b + Bank charges RM c": the parts of spent (FundFigures). */
export function SpentSplit({ expenses, welfare, charges }: { expenses: number; welfare: number; charges: number }) {
  const part = (label: string, sen: number) => (
    <span className="whitespace-nowrap">
      {label} <span className="money font-semibold text-foreground">{rm(sen)}</span>
    </span>
  );
  return (
    <span className="inline-flex flex-wrap gap-x-1">
      {part("Expenses", expenses)} + {part("Welfare", welfare)} + {part("Bank charges", charges)}
    </span>
  );
}

/* ---------- Collected vs spent: grouped bars from zero ---------- */

const compact = (sen: number) => {
  const r = sen / 100;
  return r >= 1000 ? `${(r / 1000).toFixed(r >= 10000 ? 0 : 1).replace(/\.0$/, "")}K` : `${Math.round(r)}`;
};

/** Legend key for bar series: a filled swatch, hatched for the second series (colour is never the only cue). */
function BarLegend({ items, uid }: { items: { key: string; label: string; hatched?: boolean }[]; uid: string }) {
  return (
    <ul className="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted-foreground">
      {items.map((i) => (
        <li key={i.key} className="inline-flex items-center gap-1.5">
          <svg width="14" height="14" aria-hidden>
            <rect x="0.5" y="0.5" width="13" height="13" rx="3" fill={i.hatched ? `url(#${uid}-legend-${i.key})` : `var(--color-${i.key})`} stroke={`var(--color-${i.key})`} />
            {i.hatched && (
              <defs>
                <pattern id={`${uid}-legend-${i.key}`} width="4" height="4" patternUnits="userSpaceOnUse" patternTransform="rotate(45)">
                  <rect width="4" height="4" fill={`var(--color-${i.key})`} fillOpacity={0.35} />
                  <line x1="0" y1="0" x2="0" y2="4" stroke={`var(--color-${i.key})`} strokeWidth="2" />
                </pattern>
              </defs>
            )}
          </svg>
          {i.label}
        </li>
      ))}
    </ul>
  );
}

/**
 * Money in vs money out per month as grouped bars on a zero baseline (no
 * smoothing, so a quiet month is never drawn below zero). Spent bars are
 * hatched as well as coloured. A text summary and a table view carry the
 * same numbers for screen readers and printed copies.
 */
export function CollectedSpentChart({
  data: monthly,
  year,
  summary: summaryProp,
  windowLabel,
  labels,
  lang = "en",
  height = 260,
  showTable = true,
  className,
}: {
  data: MonthPoint[];
  /** Appended to tooltip labels, e.g. "Nov 2026" (when months carry no year). */
  year?: string | number;
  /** Plain-language takeaway shown under the chart; a totals sentence is used when omitted. */
  summary?: string;
  /** The window the bars cover, e.g. "Last 12 months · 1 Dec 2025 – 15 Nov 2026"; starts the summary. */
  windowLabel?: string;
  /** Series names; default Collected / Spent. */
  labels?: { collected: string; spent: string };
  lang?: "bm" | "en";
  height?: number;
  showTable?: boolean;
  className?: string;
}) {
  const config: ChartConfig = {
    collected: { label: labels?.collected ?? (lang === "bm" ? "Dikutip" : "Collected"), color: "rgb(var(--chart-1-stroke))" },
    spent: { label: labels?.spent ?? (lang === "bm" ? "Dibelanjakan" : "Spent"), color: "rgb(var(--chart-4-stroke))" },
  };
  const data = monthly.map((m) => ({
    month: lang === "bm" ? m.month : m.monthEn,
    full: `${lang === "bm" ? m.month : m.monthEn}${m.year ? ` ${m.year}` : year ? ` ${year}` : ""}`,
    collected: m.collected,
    spent: m.spent,
  }));
  const totalIn = monthly.reduce((s, m) => s + m.collected, 0);
  const totalOut = monthly.reduce((s, m) => s + m.spent, 0);
  const last = data.length - 1;
  const range = windowLabel ?? (data.length ? `${data[0].full}–${data[last].full}` : "");
  const summary =
    summaryProp ??
    (lang === "bm"
      ? `${range}: dikutip ${rm(totalIn)}, dibelanjakan ${rm(totalOut)}.`
      : `${range}: ${config.collected.label.toLowerCase()} ${rm(totalIn)}, ${config.spent.label.toLowerCase()} ${rm(totalOut)}.`);
  // Unique per chart: two charts on a page must not share pattern ids.
  const uid = useId().replace(/:/g, "");
  const hatchId = `${uid}-spent`;
  return (
    <figure className={cn("flex flex-col gap-3", className)}>
      <div style={Object.fromEntries(Object.entries(config).map(([k, v]) => [`--color-${k}`, v.color])) as React.CSSProperties}>
        <BarLegend uid={uid} items={[{ key: "collected", label: config.collected.label }, { key: "spent", label: config.spent.label, hatched: true }]} />
      </div>
      <ChartContainer config={config} style={{ height }} role="img" aria-label={summary}>
        <BarChart data={data} margin={{ top: 8, right: 4, bottom: 0, left: -12 }} barCategoryGap="22%" barGap={2}>
          <defs>
            <pattern id={hatchId} width="5" height="5" patternUnits="userSpaceOnUse" patternTransform="rotate(45)">
              <rect width="5" height="5" fill="var(--color-spent)" fillOpacity={0.3} />
              <line x1="0" y1="0" x2="0" y2="5" stroke="var(--color-spent)" strokeWidth="2.5" />
            </pattern>
          </defs>
          <CartesianGrid vertical={false} strokeWidth={1} />
          <XAxis dataKey="full" tickFormatter={(v: string) => v.split(" ")[0]} tickLine={false} axisLine={false} tickMargin={8} interval="preserveStartEnd" minTickGap={6} fontSize={12} />
          <YAxis tickLine={false} axisLine={false} tickFormatter={compact} width={48} fontSize={12} tickCount={5} domain={[0, "auto"]} allowDecimals={false} />
          <ReferenceLine y={0} strokeWidth={1} />
          <ChartTooltip
            cursor={{ className: "fill-muted", fillOpacity: 0.6 }}
            content={
              <ChartTooltipContent
                formatter={(v) => rm(v)}
                labelFormatter={(l) => String(l)}
              />
            }
          />
          <Bar dataKey="collected" fill="var(--color-collected)" radius={[3, 3, 0, 0]} maxBarSize={22} isAnimationActive={false} />
          <Bar dataKey="spent" fill={`url(#${hatchId})`} stroke="var(--color-spent)" strokeWidth={1} radius={[3, 3, 0, 0]} maxBarSize={22} isAnimationActive={false} />
        </BarChart>
      </ChartContainer>
      <figcaption className="text-sm text-muted-foreground">{summary}</figcaption>
      {showTable && (
        <TableView label={lang === "bm" ? "Lihat sebagai jadual" : "Show as table"}>
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b text-left text-xs text-muted-foreground">
                <th className="py-1.5 font-semibold">{lang === "bm" ? "Bulan" : "Month"}</th>
                <th className="py-1.5 text-right font-semibold">{config.collected.label}</th>
                <th className="py-1.5 text-right font-semibold">{config.spent.label}</th>
              </tr>
            </thead>
            <tbody>
              {data.map((d) => (
                <tr key={d.full} className="border-b last:border-0">
                  <td className="py-1.5">{d.full}</td>
                  <td className="money py-1.5 text-right">+{rm(d.collected)}</td>
                  <td className="money py-1.5 text-right">−{rm(d.spent)}</td>
                </tr>
              ))}
            </tbody>
            <tfoot>
              <tr className="border-t font-semibold">
                <td className="py-1.5">{lang === "bm" ? "Jumlah" : "Total"}</td>
                <td className="money py-1.5 text-right">+{rm(totalIn)}</td>
                <td className="money py-1.5 text-right">−{rm(totalOut)}</td>
              </tr>
            </tfoot>
          </table>
        </TableView>
      )}
    </figure>
  );
}

export function TableView({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <details className="group rounded-md border bg-card text-sm">
      <summary className="flex min-h-10 cursor-pointer list-none items-center gap-2 px-3 py-2 font-medium text-muted-foreground hover:text-foreground [&::-webkit-details-marker]:hidden">
        <Table2 className="h-4 w-4" aria-hidden /> {label}
      </summary>
      <div className="border-t px-3 py-2">{children}</div>
    </details>
  );
}

export { CategoryBar } from "./CategoryBar";
