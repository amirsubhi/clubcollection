import type { CategorySlice, Sen } from "@/types/domain";
import { rm } from "@/lib/format";
import { cn } from "@/lib/utils";

/* Kept apart from Charts.tsx so pages that only need this bar don't pull in recharts. */

/* ---------- 100% stacked category bar (tall segmented bar) ---------- */

export function CategoryBar({
  slices: spendSlices,
  lang = "en",
  total: totalProp,
  interactive = true,
  wideLegend = false,
}: {
  slices: CategorySlice[];
  lang?: "bm" | "en";
  total?: Sen;
  interactive?: boolean;
  wideLegend?: boolean;
}) {
  const total = totalProp ?? spendSlices.reduce((s, c) => s + c.amount, 0);
  const pct = (a: number) => (total > 0 ? (a / total) * 100 : 0);
  const name = (c: (typeof spendSlices)[number]) => (lang === "bm" ? c.bm : c.en);
  return (
    <div className="flex flex-col gap-3">
      <div className="flex h-7 w-full gap-[2px]" role="img" aria-label={spendSlices.map((c) => `${name(c)} ${rm(c.amount)} (${Math.round(pct(c.amount))}%)`).join("; ")}>
        {spendSlices.map((c) => {
          const p = pct(c.amount);
          return (
            <div
              key={c.key}
              tabIndex={interactive ? 0 : undefined}
              title={`${name(c)} · ${rm(c.amount)} · ${p.toFixed(1)}%`}
              className="flex h-full min-w-0 items-center rounded-[4px] px-2 text-xs font-bold focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-card"
              style={{ width: `${p}%`, background: `rgb(var(${c.colorVar}))`, color: `rgb(var(${c.onVar}))`, boxShadow: `inset 0 0 0 1.5px rgb(var(${c.colorVar}-stroke))` }}
            >
              {p >= 11 ? `${Math.round(p)}%` : ""}
            </div>
          );
        })}
      </div>
      <ul className={cn("grid grid-cols-1 gap-x-6 gap-y-1.5 text-sm", wideLegend && "sm:grid-cols-2")}>
        {spendSlices.map((c) => (
          <li key={c.key} className="flex min-w-0 flex-wrap items-center gap-x-2">
            <span className="h-2.5 w-2.5 shrink-0 rounded-[3px]" style={{ background: `rgb(var(${c.colorVar}))`, boxShadow: `inset 0 0 0 1.5px rgb(var(${c.colorVar}-stroke))` }} aria-hidden />
            <span className="min-w-0 flex-1 break-words">{name(c)}</span>
            <span className="money ml-auto font-semibold">{rm(c.amount)}</span>
          </li>
        ))}
      </ul>
    </div>
  );
}
