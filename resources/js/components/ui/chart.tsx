import * as React from "react";
import { ResponsiveContainer, Tooltip as RechartsTooltip } from "recharts";
import { cn } from "@/lib/utils";

/**
 * shadcn-style chart wrapper around Recharts.
 * Each series key gets a CSS variable --color-<key>; marks reference it (stroke="var(--color-collected)").
 * Colours come from theme tokens, so light and dark switch with the page.
 */
export type ChartConfig = Record<string, { label: string; color: string; dashed?: boolean }>;

const ChartContext = React.createContext<{ config: ChartConfig }>({ config: {} });
export const useChart = () => React.useContext(ChartContext);

export function ChartContainer({
  config,
  className,
  children,
  style: styleProp,
  ...props
}: React.HTMLAttributes<HTMLDivElement> & { config: ChartConfig; children: React.ReactElement }) {
  const style = { ...Object.fromEntries(Object.entries(config).map(([k, v]) => [`--color-${k}`, v.color])), ...styleProp } as React.CSSProperties;
  return (
    <ChartContext.Provider value={{ config }}>
      <div
        data-chart
        style={style}
        className={cn(
          "relative w-full text-xs",
          "[&_.recharts-cartesian-axis-tick_text]:fill-chart-axis [&_.recharts-cartesian-grid_line]:stroke-chart-grid",
          "[&_.recharts-curve.recharts-tooltip-cursor]:stroke-chart-axis [&_.recharts-reference-line_line]:stroke-chart-axis",
          className,
        )}
        {...props}
      >
        <ResponsiveContainer width="100%" height="100%">
          {children}
        </ResponsiveContainer>
      </div>
    </ChartContext.Provider>
  );
}

export const ChartTooltip = RechartsTooltip;

interface TooltipPayloadItem {
  dataKey?: string | number;
  name?: string | number;
  value?: number | string;
  color?: string;
}

/** Tooltip card: label on top, one row per series with a swatch. Text stays in text tokens. */
export function ChartTooltipContent({
  active,
  payload,
  label,
  formatter,
  labelFormatter,
}: {
  active?: boolean;
  payload?: TooltipPayloadItem[];
  label?: string | number;
  formatter?: (v: number) => string;
  labelFormatter?: (l: string | number) => React.ReactNode;
}) {
  const { config } = useChart();
  if (!active || !payload?.length) return null;
  return (
    <div className="min-w-[10rem] rounded-lg border bg-popover px-3 py-2 text-xs text-popover-foreground shadow-lg">
      <div className="mb-1.5 font-semibold">{labelFormatter && label !== undefined ? labelFormatter(label) : label}</div>
      <ul className="flex flex-col gap-1">
        {payload.map((p) => {
          const key = String(p.dataKey ?? p.name);
          const c = config[key];
          return (
            <li key={key} className="flex items-center gap-2">
              <span className="h-2.5 w-2.5 shrink-0 rounded-[3px]" style={{ background: `var(--color-${key})` }} aria-hidden />
              <span className="text-muted-foreground">{c?.label ?? key}</span>
              <span className="money ml-auto pl-3 font-semibold">{formatter ? formatter(Number(p.value)) : p.value}</span>
            </li>
          );
        })}
      </ul>
    </div>
  );
}

/** HTML legend built from the config (always shown for 2+ series). Dashed series get a dashed key. */
export function ChartLegend({ config, className, keys }: { config: ChartConfig; className?: string; keys?: string[] }) {
  const entries = (keys ?? Object.keys(config)).map((k) => [k, config[k]] as const);
  return (
    <ul className={cn("flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted-foreground", className)}>
      {entries.map(([k, c]) => (
        <li key={k} className="inline-flex items-center gap-1.5">
          <svg width="18" height="8" aria-hidden>
            <line x1="1" y1="4" x2="17" y2="4" stroke={c.color} strokeWidth="2.5" strokeLinecap="round" strokeDasharray={c.dashed ? "4 3" : undefined} />
          </svg>
          {c.label}
        </li>
      ))}
    </ul>
  );
}
