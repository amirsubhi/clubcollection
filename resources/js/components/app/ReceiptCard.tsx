import { CheckCircle2 } from "lucide-react";
import { ClubMark } from "./MemberShell";
import { Money } from "./Money";
import type { Sen } from "@/types/domain";
import { fmtDateTime } from "@/lib/format";
import { useLang, useT } from "@/lib/i18n";
import { cn } from "@/lib/utils";

/** Official receipt as a light paper slip (stays light in dark mode so it prints and shares well). */
export interface ReceiptData {
  receiptNo: string;
  amount: Sen;
  /** ISO datetime yyyy-mm-ddTHH:MM */
  paidAt: string;
  method?: string;
  bank?: string;
  fpxTxn?: string;
  gatewayRef?: string;
  /** note: a second, smaller line (e.g. the fee's own receipt no. on a "Bayar semua" receipt). */
  items: { id: number; description: string; descriptionBm?: string; note?: string; amount: Sen }[];
}

export function ReceiptCard({
  club,
  member,
  receipt: r,
  className,
  compact = false,
}: {
  club: { name: string; regNo?: string };
  member: { name: string; memberNo?: string };
  receipt: ReceiptData;
  className?: string;
  compact?: boolean;
}) {
  const t = useT();
  const { lang } = useLang();
  return (
    <article aria-label={t("receipt")} className={cn("paper perforated-b rounded-t-[20px] px-5 pb-8 pt-5 shadow-[0_10px_30px_-18px_rgba(30,27,58,0.5)]", className)}>
      <header className="flex items-center gap-3 border-b border-dashed pb-4">
        <ClubMark className="h-10 w-10" />
        <div className="min-w-0 flex-1">
          <h2 className="font-display text-base font-bold leading-snug">{club.name}</h2>
          {club.regNo && <div className="text-xs text-muted-foreground">No. pendaftaran {club.regNo}</div>}
        </div>
        <span className="inline-flex h-7 items-center gap-1 rounded-full border border-success/30 bg-success-soft px-2.5 text-sm font-semibold text-success-foreground">
          <CheckCircle2 className="h-4 w-4" aria-hidden /> {t("paid")}
        </span>
      </header>
      {/* Wraps with large text: the amount drops under the receipt number instead of running off the card. */}
      <div className="flex flex-wrap items-end justify-between gap-x-3 gap-y-1 py-4">
        <div>
          <div className="text-xs font-semibold uppercase tracking-[0.14em] text-muted-foreground">{t("receipt")}</div>
          <div className="font-mono text-base">{r.receiptNo}</div>
        </div>
        <Money sen={r.amount} className="figure font-display text-3xl font-extrabold" />
      </div>
      <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-sm">
        <dt className="text-muted-foreground">{t("receivedFrom")}</dt>
        <dd className="text-right font-semibold">
          {member.name}
          {member.memberNo && <span className="block font-mono text-xs font-normal text-muted-foreground">{member.memberNo}</span>}
        </dd>
        <dt className="text-muted-foreground">{t("paidOn")}</dt>
        <dd className="text-right">{fmtDateTime(r.paidAt, lang)}</dd>
        <dt className="text-muted-foreground">{t("method")}</dt>
        <dd className="text-right">{[r.method ?? "FPX", r.bank].filter(Boolean).join(" · ")}</dd>
        {r.fpxTxn && (
          <>
            <dt className="text-muted-foreground">{t("fpxTxn")}</dt>
            <dd className="text-right font-mono">{r.fpxTxn}</dd>
          </>
        )}
        {r.gatewayRef && (
          <>
            <dt className="text-muted-foreground">{t("reference")}</dt>
            <dd className="text-right font-mono">{r.gatewayRef}</dd>
          </>
        )}
      </dl>
      {!compact && (
        <table className="mt-4 w-full text-sm">
          <tbody>
            {r.items.map((p) => (
              <tr key={p.id} className="border-t border-dashed">
                <td className="py-2 pr-2">
                  {lang === "bm" ? (p.descriptionBm ?? p.description) : p.description}
                  {p.note && <span className="block font-mono text-xs text-muted-foreground">{p.note}</span>}
                </td>
                <td className="py-2 text-right">
                  <Money sen={p.amount} className="font-mono" />
                </td>
              </tr>
            ))}
            <tr>
              <td className="pt-2 font-semibold">{t("total")}</td>
              <td className="pt-2 text-right">
                <span className="inline-block border-b-[3px] border-t border-double border-foreground/80 py-0.5">
                  <Money sen={r.amount} className="font-mono font-bold" />
                </span>
              </td>
            </tr>
          </tbody>
        </table>
      )}
      <p className="mt-4 text-xs text-muted-foreground">{t("receiptFoot")}</p>
    </article>
  );
}
