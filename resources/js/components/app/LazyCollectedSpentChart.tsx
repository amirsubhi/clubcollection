import { lazy, Suspense, type ComponentProps } from "react";

/**
 * CollectedSpentChart loaded on demand, so recharts is not part of the Dashboard or Ledger's first download.
 * The fallback reserves the legend row, the plot, the summary line and (unless showTable is false) the table row,
 * so nothing jumps when the chart arrives.
 */
const Chart = lazy(() => import("./Charts").then((m) => ({ default: m.CollectedSpentChart })));

type Props = ComponentProps<typeof Chart>;

export function LazyCollectedSpentChart(props: Props) {
  const height = props.height ?? 260;
  return (
    <Suspense
      fallback={
        <div className="flex flex-col gap-3" aria-busy="true" aria-label="Loading chart">
          <div className="h-4 w-48 animate-pulse rounded bg-muted" />
          <div className="animate-pulse rounded-lg bg-muted/60" style={{ height }} />
          {/* The one-line summary under the chart, and the "Show as table" row (TableView: min-h-10 plus its border). */}
          <div className="h-5 w-3/4 animate-pulse rounded bg-muted" />
          {props.showTable !== false && <div className="h-[42px] rounded-md border bg-card" />}
        </div>
      }
    >
      <Chart {...props} />
    </Suspense>
  );
}
