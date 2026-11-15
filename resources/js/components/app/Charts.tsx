import { ArrowDownRight, ArrowUpRight, Table2, type LucideIcon } from "lucide-react";
import { Area, AreaChart, CartesianGrid, XAxis, YAxis } from "recharts";
import { ChartContainer, ChartLegend, ChartTooltip, ChartTooltipContent, type ChartConfig } from "@/components/ui/chart";
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
  Icon,
  tone = "accent",
  className,
}: {
  label: string;
  value: string;
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
      <div className="flex items-center justify-between gap-2">
        <span className="text-sm font-medium text-muted-foreground">{label}</span>
        {Icon && <IconBubble Icon={Icon} tone={tone} />}
      </div>
      <div className="figure font-display text-[28px] font-bold leading-none tracking-tight lg:text-[30px]">{value}</div>
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

/* ---------- Collected vs spent area chart ---------- */

const compact = (sen: number) => {
  const r = sen / 100;
  return r >= 1000 ? `${(r / 1000).toFixed(r >= 10000 ? 0 : 1).replace(/\.0$/, "")}K` : `${Math.round(r)}`;
};

export function CollectedSpentChart({
  data: monthly,
  year,
  summary: summaryProp,
  lang = "en",
  height = 260,
  className,
}: {
  data: MonthPoint[];
  /** Appended to tooltip labels, e.g. "Nov 2026". */
  year?: string | number;
  /** Plain-language takeaway shown under the chart; a totals sentence is used when omitted. */
  summary?: string;
  lang?: "bm" | "en";
  height?: number;
  className?: string;
}) {
  const config: ChartConfig = {
    collected: { label: lang === "bm" ? "Dikutip" : "Collected", color: "rgb(var(--chart-1-stroke))" },
    spent: { label: lang === "bm" ? "Dibelanjakan" : "Spent", color: "rgb(var(--chart-4-stroke))", dashed: true },
  };
  const data = monthly.map((m) => ({ month: lang === "bm" ? m.month : m.monthEn, collected: m.collected, spent: m.spent }));
  const totalIn = monthly.reduce((s, m) => s + m.collected, 0);
  const totalOut = monthly.reduce((s, m) => s + m.spent, 0);
  const last = data.length - 1;
  // When both series end close together their right-edge labels would overlap:
  // push the higher one up and the lower one down by half a line each.
  const peak = Math.max(1, ...data.flatMap((d) => [d.collected, d.spent]));
  const endC = last >= 0 ? data[last].collected : 0;
  const endS = last >= 0 ? data[last].spent : 0;
  const close = Math.abs(endC - endS) < peak * 0.1;
  const nudge = { collected: close ? (endC >= endS ? -7 : 7) : 0, spent: close ? (endC >= endS ? 7 : -7) : 0 };
  const endLabel =
    (key: "collected" | "spent") =>
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    (p: any) =>
      p.index === last ? (
        <text x={p.x + 8} y={p.y + 4 + nudge[key]} className="fill-foreground" fontSize={12} fontWeight={600}>
          {config[key].label}
        </text>
      ) : null;
  const range = data.length ? `${data[0].month}–${data[last].month}${year ? ` ${year}` : ""}` : "";
  const summary =
    summaryProp ??
    (lang === "bm" ? `${range}: dikutip ${rm(totalIn)}, dibelanjakan ${rm(totalOut)}.` : `${range}: collected ${rm(totalIn)}, spent ${rm(totalOut)}.`);
  return (
    <figure className={cn("flex flex-col gap-3", className)}>
      <ChartLegend config={config} />
      <ChartContainer config={config} style={{ height }} role="img" aria-label={summary}>
        <AreaChart data={data} margin={{ top: 8, right: 84, bottom: 0, left: -12 }}>
          <CartesianGrid vertical={false} strokeWidth={1} />
          <XAxis dataKey="month" tickLine={false} axisLine={false} tickMargin={8} interval="preserveStartEnd" minTickGap={10} fontSize={12} />
          <YAxis tickLine={false} axisLine={false} tickFormatter={compact} width={48} fontSize={12} tickCount={5} />
          <ChartTooltip
            cursor={{ strokeWidth: 1 }}
            content={<ChartTooltipContent formatter={(v) => rm(v)} labelFormatter={(l) => (year ? `${l} ${year}` : String(l))} />}
          />
          <Area
            dataKey="collected"
            type="monotone"
            stroke="var(--color-collected)"
            strokeWidth={2}
            fill="var(--color-collected)"
            fillOpacity={0.12}
            dot={false}
            activeDot={{ r: 5, strokeWidth: 2, className: "stroke-card" }}
            label={endLabel("collected")}
            isAnimationActive={false}
          />
          <Area
            dataKey="spent"
            type="monotone"
            stroke="var(--color-spent)"
            strokeWidth={2}
            strokeDasharray="6 4"
            fill="var(--color-spent)"
            fillOpacity={0.06}
            dot={false}
            activeDot={{ r: 5, strokeWidth: 2, className: "stroke-card" }}
            label={endLabel("spent")}
            isAnimationActive={false}
          />
        </AreaChart>
      </ChartContainer>
      <figcaption className="text-sm text-muted-foreground">{summary}</figcaption>
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
              <tr key={d.month} className="border-b last:border-0">
                <td className="py-1.5">{d.month}</td>
                <td className="money py-1.5 text-right">{rm(d.collected)}</td>
                <td className="money py-1.5 text-right">−{rm(d.spent)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </TableView>
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
