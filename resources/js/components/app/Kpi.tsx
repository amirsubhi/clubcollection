import { ArrowDownRight, ArrowUpRight, Table2, type LucideIcon } from "lucide-react";
import { IconBubble, type Tone } from "./Visuals";
import { rm } from "@/lib/format";
import { cn } from "@/lib/utils";

/* Non-chart building blocks, kept apart from Charts.tsx so pages without a chart don't pull in recharts. */

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
      <div className="figure font-display text-[1.75rem] font-bold leading-none tracking-tight lg:text-[1.875rem]">{value}</div>
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
