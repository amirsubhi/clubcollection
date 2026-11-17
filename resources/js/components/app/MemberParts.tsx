import type { ReactNode } from "react";
import { AlertTriangle, CalendarClock, CheckCircle2, ChevronRight, Hourglass, Mail, MessageCircle, RefreshCw } from "lucide-react";
import { Link, usePage } from "@inertiajs/react";
import { Money } from "./Money";
import { PaymentStatusBadge } from "./StatusBadges";
import { IconBubble } from "./Visuals";
import { Alert } from "@/components/ui/alert";
import { fmtDate, fmtPeriod } from "@/lib/format";
import { fill, isDictKey, textIn, useLang, useT, type DictKey } from "@/lib/i18n";
import { cn } from "@/lib/utils";
import type { CategorySlice, PaymentStatus } from "@/types/domain";
import type { FundCategory, MemberPaymentRow } from "@/types/member";

/** "Yuran keahlian Okt – Dis 2026" for a fee row. */
export function useFeeLabel() {
  const t = useT();
  const { lang } = useLang();
  return (p: Pick<MemberPaymentRow, "periodStart" | "periodEnd">) => `${t("membershipFee")} ${fmtPeriod(p.periodStart, p.periodEnd, lang)}`;
}

/** The one status word (DisplayStatus) as a dictionary key. */
const statusKeys: Record<PaymentStatus, DictKey> = {
  paid: "paid",
  due: "statusDue",
  overdue: "overdueBadge",
  processing: "statusProcessing",
  verifying: "verifyingBadge",
};

export function statusLabelKey(s: PaymentStatus): DictKey {
  return statusKeys[s];
}

/** Translate a "member.*" code from the server (flash or validation error); anything else is returned as-is. */
export function useServerText() {
  const t = useT();
  return (msg: string) => {
    const key = `flash_${msg.replace(/^member\./, "")}`;
    return msg.startsWith("member.") && isDictKey(key) ? t(key) : msg;
  };
}

/** Session flash from the controllers: "member.*" codes are translated, anything else is shown as-is. */
export function FlashBanner() {
  const text = useServerText();
  const { flash, errors } = usePage<{ errors?: Record<string, string> }>().props;
  const firstError = errors ? Object.values(errors)[0] : undefined;
  return (
    <>
      {flash.success && (
        <Alert variant="success" className="text-base">
          <CheckCircle2 aria-hidden />
          <p>{text(flash.success)}</p>
        </Alert>
      )}
      {(flash.error || firstError) && (
        <Alert variant="danger" className="text-base">
          <AlertTriangle aria-hidden />
          <p>{text(flash.error ?? firstError ?? "")}</p>
        </Alert>
      )}
    </>
  );
}

const bubble: Record<PaymentStatus, { Icon: typeof CheckCircle2; tone: "mint" | "danger" | "info" | "warning" }> = {
  paid: { Icon: CheckCircle2, tone: "mint" },
  overdue: { Icon: AlertTriangle, tone: "warning" },
  due: { Icon: CalendarClock, tone: "info" },
  processing: { Icon: RefreshCw, tone: "warning" },
  verifying: { Icon: Hourglass, tone: "warning" },
};

/**
 * One fee as a tappable row: open fees go to Review & pay, paid ones to the receipt,
 * and a fee with an FPX payment in progress to that payment's status (paying again is on Review & pay).
 */
export function PaymentRow({ p, showClub = false }: { p: MemberPaymentRow; showClub?: boolean }) {
  const t = useT();
  const { lang } = useLang();
  const feeLabel = useFeeLabel();
  const b = bubble[p.status];
  const href = p.status === "processing" && p.urls.billStatus ? p.urls.billStatus : p.status === "paid" || !p.urls.review ? p.urls.invoice : p.urls.review;
  return (
    <Link
      href={href}
      className="flex min-h-[68px] items-center gap-3 px-4 py-3 hover:bg-muted/60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring"
    >
      {/* Decorative icons stay icon-sized (px) so large text gets the room. */}
      <IconBubble Icon={b.Icon} tone={b.tone} className="h-[36px] w-[36px]" />
      {/* Label and amount share a line when there is room; with large text the amount wraps below instead of squeezing the label. */}
      <div className="flex min-w-0 flex-1 flex-wrap items-center justify-between gap-x-3 gap-y-1.5">
        <div className="min-w-0 flex-[1_1_8rem]">
          <div className="break-words text-base font-semibold leading-snug">{feeLabel(p)}</div>
          <div className="text-sm text-muted-foreground">
            {p.status === "paid" && p.paidDate
              ? `${t("paidOn")} ${fmtDate(p.paidDate, lang)}`
              : p.status === "verifying"
                ? t("slipBeingChecked")
                : p.status === "processing"
                  ? t("processingRow")
                  : `${t("due")} ${fmtDate(p.dueDate, lang)}`}
            {showClub && p.clubName ? ` · ${p.clubName}` : ""}
          </div>
        </div>
        <div className="ml-auto flex min-w-0 max-w-full flex-col items-end gap-1 text-right">
          <Money sen={p.amount} className="text-base font-bold" />
          <PaymentStatusBadge status={p.status} label={t(statusLabelKey(p.status))} size="sm" className="h-auto min-h-6 max-w-full whitespace-normal py-0.5" />
        </div>
      </div>
      <ChevronRight className="h-[20px] w-[20px] shrink-0 text-muted-foreground" aria-hidden />
    </Link>
  );
}

const sliceTokens = ["--chart-1", "--chart-2", "--chart-3", "--chart-4"];

/** Expense categories to at most four bar slices (the chart palette has four validated colours). */
export function toSlices(categories: FundCategory[], othersLabel: { bm: string; en: string }): CategorySlice[] {
  const sorted = [...categories].sort((a, b) => b.amount - a.amount);
  const top = sorted.length > 4 ? sorted.slice(0, 3) : sorted;
  const rest = sorted.length > 4 ? sorted.slice(3).reduce((s, c) => s + c.amount, 0) : 0;
  const slices = top.map((c, i) => ({ key: `c${i}`, bm: c.nameMs ?? c.name, en: c.name, amount: c.amount }));
  if (rest > 0) slices.push({ key: "others", ...othersLabel, amount: rest });
  return slices.map((s, i) => ({ ...s, colorVar: sliceTokens[i], onVar: `--on-chart-${i + 1}` }));
}

/** Where "Perlu bantuan?" goes: the club's WhatsApp (with the reference typed in) when set, else its email. */
export interface HelpContact {
  name: string;
  email?: string | null;
  /** Digits for wa.me (E.164 without "+"), or null. */
  whatsapp?: string | null;
}

/**
 * wa.me link with a BM message the member can still edit before sending. Only the club, the fee period and the
 * member's own reference go in (no name, email or phone).
 */
export function whatsAppHelpHref(digits: string, club: string, about?: { fee?: Pick<MemberPaymentRow, "periodStart" | "periodEnd"> | null; reference?: string | null }) {
  const lines = [fill(textIn("waHelloMsg", "bm"), { club })];
  if (about?.fee) lines.push(fill(textIn("waFeeMsg", "bm"), { fee: `${textIn("membershipFee", "bm")} ${fmtPeriod(about.fee.periodStart, about.fee.periodEnd, "bm")}` }));
  if (about?.reference) lines.push(fill(textIn("waRefMsg", "bm"), { ref: about.reference }));
  return `https://wa.me/${digits.replace(/\D/g, "")}?text=${encodeURIComponent(lines.join("\n"))}`;
}

/** Help line on every payment screen: WhatsApp the club when it set a number, else mail it when it has an address. */
export function HelpLine({
  club,
  fee,
  reference,
  className,
}: {
  club: HelpContact;
  /** The fee the member is looking at, for the WhatsApp message. */
  fee?: Pick<MemberPaymentRow, "periodStart" | "periodEnd"> | null;
  /** The member's own invoice / receipt / transfer reference, for the WhatsApp message. */
  reference?: string | null;
  className?: string;
}) {
  const t = useT();
  const linkCls = cn("flex min-h-12 flex-wrap items-center justify-center gap-x-2 gap-y-0.5 text-center text-base font-semibold text-primary hover:underline", className);
  if (club.whatsapp) {
    return (
      <a href={whatsAppHelpHref(club.whatsapp, club.name, { fee, reference })} target="_blank" rel="noopener noreferrer" className={linkCls} data-testid="help-whatsapp">
        <MessageCircle className="h-5 w-5 shrink-0" strokeWidth={1.75} aria-hidden /> {t("helpTitle")} {t("helpWhatsApp")}
        <span className="sr-only"> {t("helpOpensWhatsApp")}</span>
      </a>
    );
  }
  const body = (
    <>
      <Mail className="h-5 w-5 shrink-0" strokeWidth={1.75} aria-hidden /> {t("helpTitle")} {t("helpAdmin")}
    </>
  );
  return club.email ? (
    <a href={`mailto:${club.email}`} className={linkCls} data-testid="help-email">
      {body}
    </a>
  ) : (
    <p className={cn("flex min-h-12 flex-wrap items-center justify-center gap-2 text-center text-base text-muted-foreground", className)}>{body}</p>
  );
}

export function SectionCard({ title, action, children, id }: { title: string; action?: ReactNode; children: ReactNode; id: string }) {
  return (
    <section aria-labelledby={id} className="rounded-[20px] border bg-card">
      {/* With large text the action ("Lihat semua") drops under the title instead of running off the card. */}
      <div className="flex flex-wrap items-center justify-between gap-x-2 px-4 pb-1 pt-4">
        <h2 id={id} className="min-w-0 break-words font-display text-xl font-bold tracking-tight">
          {title}
        </h2>
        {action}
      </div>
      {children}
    </section>
  );
}
