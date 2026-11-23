import { CheckCircle2 } from "lucide-react";
import { ClubMark } from "./MemberShell";
import { Money } from "./Money";
import type { Sen } from "@/types/domain";
import type { ReceiptIssuer } from "@/types/member";
import { fmtDateTime } from "@/lib/format";
import { localText, useLang, useT } from "@/lib/i18n";
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

type IssuerLike = { name: string; registrationNo?: string | null; address?: string | null; logoUrl?: string | null; footer?: string | null };

/** The club a receipt names: its issuer snapshot, else (older receipts) the club as it is now. */
export function receiptClub(issuer: ReceiptIssuer | null | undefined, live: IssuerLike) {
  const c: IssuerLike = issuer ?? live;
  return { name: c.name, regNo: c.registrationNo ?? null, address: c.address ?? null, logoUrl: c.logoUrl ?? null, footer: c.footer ?? null };
}

export function ReceiptCard({
  club,
  member,
  receipt: r,
  className,
  compact = false,
}: {
  /** The issuing club (receipt.issuer: as it was when the fee was paid). */
  club: { name: string; regNo?: string | null; address?: string | null; logoUrl?: string | null; footer?: string | null };
  member: { name: string; memberNo?: string };
  receipt: ReceiptData;
  className?: string;
  compact?: boolean;
}) {
  const t = useT();
  const { lang } = useLang();
  return (
    <article aria-label={t("receipt")} className={cn("paper perforated-b rounded-t-[20px] px-5 pb-8 pt-5 shadow-[0_10px_30px_-18px_rgb(var(--shadow-color)/0.5)]", className)}>
      <header className="flex flex-wrap items-center gap-3 border-b border-dashed pb-4">
        <ClubMark className="h-12 w-12" src={club.logoUrl} alt={club.name} />
        <div className="min-w-0 flex-[1_1_8rem]">
          <h2 className="break-words font-display text-base font-bold leading-snug">{club.name}</h2>
          {club.regNo && (
            <div className="break-words text-xs text-muted-foreground" data-testid="receipt-reg-no">
              {t("regNo")} {club.regNo}
            </div>
          )}
          {club.address && (
            <div className="whitespace-pre-line break-words text-xs text-muted-foreground" data-testid="receipt-address">
              {club.address}
            </div>
          )}
        </div>
        <span className="inline-flex min-h-7 items-center gap-1 rounded-full border border-success/30 bg-success-soft px-2.5 text-sm font-semibold text-success-foreground">
          <CheckCircle2 className="h-4 w-4 shrink-0" aria-hidden /> {t("paid")}
        </span>
      </header>
      {/* Wraps with large text: the amount drops under the receipt number instead of running off the card. */}
      <div className="flex flex-wrap items-end justify-between gap-x-3 gap-y-1 py-4">
        <div className="min-w-0">
          <div className="text-xs font-semibold uppercase tracking-[0.14em] text-muted-foreground">{t("receipt")}</div>
          <div className="break-all font-mono text-base">{r.receiptNo}</div>
        </div>
        <Money sen={r.amount} className="figure max-w-full font-display text-3xl font-extrabold !whitespace-normal" />
      </div>
      {/* Label and value share a line while they fit; with large text the value drops under its label. */}
      <dl className="flex flex-col gap-2 text-sm">
        {(
          [
            [t("receivedFrom"), <>
              <span className="font-semibold">{member.name}</span>
              {member.memberNo && <span className="block font-mono text-xs font-normal text-muted-foreground">{member.memberNo}</span>}
            </>, false],
            [t("paidOn"), fmtDateTime(r.paidAt, lang), false],
            [t("method"), [r.method ?? "FPX", r.bank].filter(Boolean).join(" · "), false],
            r.fpxTxn ? [t("fpxTxn"), r.fpxTxn, true] : null,
            r.gatewayRef ? [t("reference"), r.gatewayRef, true] : null,
          ] as ([string, React.ReactNode, boolean] | null)[]
        )
          .filter((x): x is [string, React.ReactNode, boolean] => x !== null)
          .map(([label, value, mono]) => (
            <div key={label} className="flex flex-wrap justify-between gap-x-4">
              <dt className="text-muted-foreground">{label}</dt>
              <dd className={cn("ml-auto min-w-0 break-words text-right", mono && "break-all font-mono")}>{value}</dd>
            </div>
          ))}
      </dl>
      {!compact && (
        <table className="mt-4 w-full text-sm">
          <tbody>
            {r.items.map((p) => (
              <tr key={p.id} className="border-t border-dashed">
                <td className="py-2 pr-2">
                  {localText(lang, { ms: p.descriptionBm, en: p.description })}
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
      {/* The club's own footer (Club profile), as issued: plain text, React escapes it; line breaks kept. */}
      {club.footer && (
        <p className="mt-1 whitespace-pre-line break-words text-xs text-muted-foreground" data-testid="receipt-footer">
          {club.footer}
        </p>
      )}
    </article>
  );
}
