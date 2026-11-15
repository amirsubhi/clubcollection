import { useId } from "react";
import type { Sen } from "@/types/domain";
import { rm } from "@/lib/format";
import { cn } from "@/lib/utils";

const rmShort = (sen: number) => `RM ${Math.round(sen / 100).toLocaleString("en-MY")}`;

function Arrowhead({ id }: { id: string }) {
  return (
    <marker id={id} viewBox="0 0 10 10" refX="8" refY="5" markerWidth="9" markerHeight="9" markerUnits="userSpaceOnUse" orient="auto-start-reverse">
      <path d="M0 0 L10 5 L0 10 z" fill="currentColor" />
    </marker>
  );
}

/**
 * "Where your money goes": fees & special drives → club fund → spending / welfare / kept.
 * Arrow thickness follows the amount. Theme-aware: boxes use card/border tokens, text uses currentColor.
 */
export function MoneyFlowDiagram({
  fees,
  otherIncome,
  spending,
  welfare: totalWelfare,
  welfareCount,
  lang = "bm",
  className,
}: {
  /** Member fees and special collection drives. */
  fees: Sen;
  otherIncome: Sen;
  /** Approved expenses plus bank/FPX charges. */
  spending: Sen;
  welfare: Sen;
  welfareCount: number;
  lang?: "bm" | "en";
  className?: string;
}) {
  const id = useId().replace(/:/g, "");
  const bm = lang === "bm";
  const totalReceipts = fees + otherIncome;
  const kept = totalReceipts - spending - totalWelfare;
  const w = (sen: number) => Math.max(2, Math.round((totalReceipts > 0 ? Math.max(0, sen) / totalReceipts : 0) * 14));
  const claim = bm
    ? `Yuran & kutipan khas ${rm(fees)} dan pendapatan lain ${rm(otherIncome)} masuk ke dana kelab. Daripada dana: perbelanjaan ${rm(spending)}, kebajikan ${rm(totalWelfare)}, baki ${rm(kept)} kekal dalam dana.`
    : `Fees & special drives ${rm(fees)} and other income ${rm(otherIncome)} go into the club fund. From the fund: spending ${rm(spending)}, welfare ${rm(totalWelfare)}, and ${rm(kept)} stays in the fund.`;
  const outs = [
    { x: 6, label: bm ? "Perbelanjaan" : "Spending", sub: bm ? "majlis, sukan, caj" : "events, sports, fees", amt: spending, verb: bm ? "dibelanjakan" : "spent" },
    { x: 120, label: bm ? "Kebajikan" : "Welfare", sub: bm ? `${welfareCount} kes dibantu` : `${welfareCount} ${welfareCount === 1 ? "case" : "cases"} helped`, amt: totalWelfare, verb: bm ? "dibayar" : "paid" },
    { x: 234, label: bm ? "Kekal" : "Kept", sub: bm ? "baki dana" : "fund balance", amt: kept, verb: bm ? "disimpan" : "saved" },
  ];
  return (
    <figure className={cn("flex flex-col gap-2", className)}>
      <svg viewBox="0 0 340 292" role="img" aria-label={claim} className="h-auto w-full max-w-[460px] self-center text-foreground">
        <defs>
          <Arrowhead id={`${id}-a`} />
        </defs>
        {/* inputs */}
        <g>
          <rect x="6" y="6" width="160" height="54" rx="12" className="fill-card stroke-border" strokeWidth="1.5" />
          <text x="86" y="28" textAnchor="middle" fontSize="12.5" fontWeight="700" fill="currentColor">{bm ? "Yuran & kutipan khas" : "Fees & special drives"}</text>
          <text x="86" y="47" textAnchor="middle" fontSize="12.5" fontWeight="600" fill="currentColor" style={{ fontVariantNumeric: "tabular-nums" }}>{rmShort(fees)}</text>
          <rect x="174" y="6" width="160" height="54" rx="12" className="fill-card stroke-border" strokeWidth="1.5" />
          <text x="254" y="28" textAnchor="middle" fontSize="12.5" fontWeight="700" fill="currentColor">{bm ? "Pendapatan lain" : "Other income"}</text>
          <text x="254" y="47" textAnchor="middle" fontSize="12.5" fontWeight="600" fill="currentColor" style={{ fontVariantNumeric: "tabular-nums" }}>{rmShort(otherIncome)}</text>
        </g>
        {/* in-arrows */}
        <g className="text-chart-2-stroke" stroke="currentColor" fill="none" strokeLinecap="round">
          <path d="M86 62 C86 84 130 84 132 104" strokeWidth={w(fees)} markerEnd={`url(#${id}-a)`} />
          <path d="M254 62 C254 84 210 84 208 104" strokeWidth={w(otherIncome)} markerEnd={`url(#${id}-a)`} />
        </g>
        <text x="170" y="86" textAnchor="middle" fontSize="11.5" fill="currentColor" className="opacity-80">{bm ? "dikutip" : "collected"}</text>
        {/* fund */}
        <rect x="70" y="110" width="200" height="62" rx="14" className="fill-deep stroke-primary/40" strokeWidth="1.5" />
        <text x="170" y="134" textAnchor="middle" fontSize="13.5" fontWeight="800" className="fill-deep-foreground" style={{ fontFamily: '"Bricolage Grotesque", system-ui, sans-serif' }}>{bm ? "Dana kelab" : "Club fund"}</text>
        <text x="170" y="156" textAnchor="middle" fontSize="12.5" fontWeight="600" className="fill-deep-foreground" style={{ fontVariantNumeric: "tabular-nums" }}>{bm ? "Masuk " : "In "}{rmShort(totalReceipts)}</text>
        {/* out-arrows */}
        <g className="text-primary" stroke="currentColor" fill="none" strokeLinecap="round">
          {outs.map((o) => {
            const cx = o.x + 50;
            const sx = cx < 150 ? 130 : cx > 190 ? 210 : 170;
            return <path key={o.label} d={`M${sx} 174 C${sx} 196 ${cx} 192 ${cx} 216`} strokeWidth={w(o.amt)} markerEnd={`url(#${id}-a)`} />;
          })}
        </g>
        {outs.map((o) => (
          <g key={o.label}>
            <rect x={o.x} y="222" width="100" height="64" rx="12" className="fill-card stroke-border" strokeWidth="1.5" />
            <text x={o.x + 50} y="242" textAnchor="middle" fontSize="12.5" fontWeight="700" fill="currentColor">{o.label}</text>
            <text x={o.x + 50} y="259" textAnchor="middle" fontSize="12" fontWeight="600" fill="currentColor" style={{ fontVariantNumeric: "tabular-nums" }}>{rmShort(o.amt)}</text>
            <text x={o.x + 50} y="277" textAnchor="middle" fontSize="11" fill="currentColor" className="opacity-75">{o.sub}</text>
          </g>
        ))}
      </svg>
      <figcaption className="text-sm text-muted-foreground">{claim}</figcaption>
    </figure>
  );
}

export type FlowState = "draft" | "submitted" | "approved" | "returned" | "void";

/**
 * Maker-checker flow: Draf → Dihantar → Diluluskan / Dikembalikan (back to Draf) → Batal.
 * The current state is the one filled with the primary colour.
 */
export function ApprovalFlowDiagram({ current, className }: { current?: FlowState; className?: string }) {
  const id = useId().replace(/:/g, "");
  const nodes: { key: FlowState; x: number; y: number; bm: string; en: string }[] = [
    { key: "draft", x: 4, y: 26, bm: "Draf", en: "Draft" },
    { key: "submitted", x: 124, y: 26, bm: "Dihantar", en: "Submitted" },
    { key: "approved", x: 244, y: 26, bm: "Diluluskan", en: "Approved" },
    { key: "returned", x: 64, y: 150, bm: "Dikembalikan", en: "Returned" },
    { key: "void", x: 244, y: 150, bm: "Batal", en: "Void" },
  ];
  const W = 92;
  const H = 46;
  const claim =
    "Maker-checker flow. The maker saves a Draf and submits it (Dihantar). A different admin either approves it (Diluluskan) or returns it with a reason (Dikembalikan), which sends it back to Draf. An approved record can only be voided (Batal) when two admins agree.";
  return (
    <figure className={cn("flex flex-col gap-2", className)}>
      <svg viewBox="0 0 340 204" role="img" aria-label={claim} className="h-auto w-full max-w-[520px] text-foreground">
        <defs>
          <Arrowhead id={`${id}-a`} />
        </defs>
        <g stroke="currentColor" fill="none" strokeWidth="1.75" className="text-muted-foreground" strokeLinecap="round">
          <path d="M98 49 H120" markerEnd={`url(#${id}-a)`} />
          <path d="M218 49 H240" markerEnd={`url(#${id}-a)`} />
          <path d="M160 74 V146" markerEnd={`url(#${id}-a)`} />
          <path d="M80 148 Q50 120 50 76" markerEnd={`url(#${id}-a)`} />
          <path d="M290 74 V146" markerEnd={`url(#${id}-a)`} />
        </g>
        <g fontSize="11.5" fill="currentColor" className="text-muted-foreground">
          <text x="109" y="16" textAnchor="middle">hantar</text>
          <text x="229" y="16" textAnchor="middle">lulus</text>
          <text x="166" y="104">pulang</text>
          <text x="166" y="118">+ sebab</text>
          <text x="2" y="116">betulkan</text>
          <text x="296" y="104">2 admin</text>
          <text x="296" y="118">setuju</text>
        </g>
        {nodes.map((n) => {
          const on = n.key === current;
          return (
            <g key={n.key}>
              <rect x={n.x} y={n.y} width={W} height={H} rx="10" strokeWidth="1.5" className={on ? "fill-primary stroke-primary" : "fill-card stroke-border"} />
              <text x={n.x + W / 2} y={n.y + 20} textAnchor="middle" fontSize="13" fontWeight="700" className={on ? "fill-primary-foreground" : "fill-foreground"}>
                {n.bm}
              </text>
              <text x={n.x + W / 2} y={n.y + 36} textAnchor="middle" fontSize="11" className={on ? "fill-primary-foreground" : "fill-muted-foreground"}>
                {n.en}
              </text>
            </g>
          );
        })}
      </svg>
      <figcaption className="text-sm text-muted-foreground">
        One admin records (maker); a <strong className="font-semibold text-foreground">different</strong> admin approves or returns it with a reason (checker). Approved records are never edited, only voided by two admins.
      </figcaption>
    </figure>
  );
}
