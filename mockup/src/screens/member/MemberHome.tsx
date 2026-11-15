import { AlertTriangle, CalendarClock, CheckCircle2, ChevronRight, HandHeart, MessageCircle, PieChart, ReceiptText, Wallet } from "lucide-react";
import { MemberShell } from "@/components/app/MemberShell";
import { Money } from "@/components/app/Money";
import { PaymentStatusBadge } from "@/components/app/StatusBadges";
import { IconBubble, NodePattern, type Tone } from "@/components/app/Visuals";
import { CategoryBar } from "@/components/app/Charts";
import { currentMember } from "@/data/club";
import { amountDue, dueNow, paidHistory, upcoming } from "@/data/payments";
import { ytd } from "@/data/report";
import { fmtDate, rm } from "@/lib/format";
import { useLang, useT, type DictKey } from "@/i18n";

const quick: { hash: string; label: DictKey; Icon: typeof Wallet; tone: Tone }[] = [
  { hash: "member-pay", label: "qaPay", Icon: Wallet, tone: "marigold" },
  { hash: "member-history", label: "qaReceipts", Icon: ReceiptText, tone: "pine" },
  { hash: "member-fund", label: "qaFund", Icon: PieChart, tone: "info" },
  { hash: "member-fund", label: "qaWelfare", Icon: HandHeart, tone: "clay" },
];

export function MemberHome() {
  const t = useT();
  const { lang } = useLang();
  const first = currentMember.name.split(" ")[1] ?? currentMember.name;
  return (
    <MemberShell tab="home" title={`${lang === "bm" ? "Hai" : "Hi"}, ${first}`}>
      {/* Hero: the one number that matters */}
      <section aria-labelledby="due-h" className="relative overflow-hidden rounded-[24px] bg-deep p-5 text-deep-foreground shadow-[0_22px_44px_-22px_rgba(6,61,50,0.75)]">
        <NodePattern />
        <div className="relative flex flex-col gap-4">
          <div className="flex items-center justify-between gap-3">
            <h2 id="due-h" className="font-sans text-base font-semibold text-white/85">
              {t("amountDue")}
            </h2>
            <span className="inline-flex h-7 items-center gap-1 rounded-full bg-marigold px-2.5 text-sm font-bold text-marigold-foreground">
              <AlertTriangle className="h-4 w-4" strokeWidth={2} aria-hidden /> {t("lateCount")}
            </span>
          </div>
          <div>
            <div className="figure font-display text-[52px] font-extrabold leading-none tracking-tight text-white">{rm(amountDue)}</div>
            <div className="mt-2 text-base text-white/85">
              {t("includes")}: {t("forMonths")}
            </div>
          </div>
          <div className="flex gap-2">
            <a
              href="#member-pay"
              className="inline-flex h-14 flex-1 items-center justify-center gap-2 rounded-full bg-marigold px-5 text-lg font-bold text-marigold-foreground shadow-[0_6px_16px_-6px_rgba(244,182,63,0.8)] transition-transform hover:brightness-105 active:scale-[0.98] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white focus-visible:ring-offset-2 focus-visible:ring-offset-deep"
            >
              {t("payNow")} {rm(amountDue)}
            </a>
            <a
              href="#member-invoice"
              className="inline-flex h-14 items-center justify-center rounded-full border border-white/35 px-4 text-base font-semibold text-white hover:bg-white/10 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white"
            >
              {t("viewInvoice")}
            </a>
          </div>
        </div>
      </section>

      {/* Quick actions */}
      <nav aria-label={t("quickActions")}>
        <ul className="grid grid-cols-4 gap-2">
          {quick.map((q) => (
            <li key={q.label}>
              <a
                href={`#${q.hash}`}
                className="flex min-h-[88px] flex-col items-center justify-center gap-2 rounded-[18px] bg-card px-1 py-3 text-center text-sm font-semibold ring-1 ring-border transition-colors hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
              >
                <IconBubble Icon={q.Icon} tone={q.tone} size={44} />
                {t(q.label)}
              </a>
            </li>
          ))}
        </ul>
      </nav>

      {/* What's due */}
      <section aria-labelledby="up-h" className="rounded-[20px] border bg-card">
        <div className="flex items-center justify-between px-4 pb-1 pt-4">
          <h2 id="up-h" className="font-display text-xl font-bold tracking-tight">
            {t("upcoming")}
          </h2>
          <a href="#member-history" className="inline-flex min-h-11 items-center gap-0.5 text-base font-semibold text-primary hover:underline">
            {t("viewAll")} <ChevronRight className="h-5 w-5" aria-hidden />
          </a>
        </div>
        <ul className="divide-y px-4 pb-2">
          {[...dueNow, ...upcoming]
            .sort((a, b) => a.dueDate.localeCompare(b.dueDate))
            .map((p) => (
              <li key={p.id} className="flex min-h-[64px] items-center gap-3 py-2.5">
                <IconBubble Icon={p.status === "overdue" ? AlertTriangle : CalendarClock} tone={p.status === "overdue" ? "danger" : "neutral"} />
                <div className="min-w-0 flex-1">
                  <div className="line-clamp-2 text-base font-semibold leading-snug">{lang === "bm" ? p.descriptionBm : p.description}</div>
                  <div className="text-sm text-muted-foreground">
                    {t("due")} {fmtDate(p.dueDate, lang)}
                  </div>
                </div>
                <div className="flex flex-col items-end gap-1">
                  <Money sen={p.amount} className="text-base font-bold" />
                  {p.status === "overdue" ? (
                    <PaymentStatusBadge status="overdue" label={t("overdueBadge")} size="sm" />
                  ) : (
                    <PaymentStatusBadge status="pending" label={t("pending")} size="sm" />
                  )}
                </div>
              </li>
            ))}
        </ul>
      </section>

      {/* Recent activity */}
      <section aria-labelledby="rc-h" className="flex flex-col gap-2">
        <h2 id="rc-h" className="px-1 font-display text-xl font-bold tracking-tight">
          {t("recent")}
        </h2>
        <ul className="flex flex-col gap-2">
          {paidHistory.slice(0, 3).map((p) => (
            <li key={p.id}>
              <a href="#member-receipt" className="flex min-h-14 items-center gap-3 rounded-[18px] bg-card px-3 py-2.5 ring-1 ring-border hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring">
                <IconBubble Icon={CheckCircle2} tone="pine" />
                <div className="min-w-0 flex-1">
                  <div className="line-clamp-2 text-base font-semibold leading-snug">{lang === "bm" ? p.descriptionBm : p.description}</div>
                  <div className="text-sm text-muted-foreground">
                    {p.paidDate ? fmtDate(p.paidDate, lang) : ""} · {p.bank ?? p.method}
                  </div>
                </div>
                <span className="money text-base font-bold">−{rm(p.amount)}</span>
                <ChevronRight className="h-5 w-5 shrink-0 text-muted-foreground" aria-hidden />
              </a>
            </li>
          ))}
        </ul>
      </section>

      {/* Fund teaser */}
      <a href="#member-fund" className="group flex flex-col gap-3 rounded-[20px] border bg-card p-4 hover:border-primary/50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring">
        <div className="flex items-start justify-between gap-3">
          <div>
            <h2 className="font-display text-xl font-bold tracking-tight">{t("moneyGoes")}</h2>
            <p className="text-sm text-muted-foreground">{t("moneyGoesSub")}</p>
          </div>
          <ChevronRight className="mt-1 h-5 w-5 shrink-0 text-muted-foreground group-hover:text-primary" aria-hidden />
        </div>
        <div className="grid grid-cols-2 gap-2">
          <div className="rounded-2xl bg-accent px-3 py-2.5">
            <div className="text-sm text-accent-foreground">{t("collected")}</div>
            <Money sen={ytd.collected} className="font-display text-lg font-bold" />
          </div>
          <div className="rounded-2xl bg-muted px-3 py-2.5">
            <div className="text-sm text-muted-foreground">{t("spent")}</div>
            <Money sen={ytd.spent + ytd.welfarePaid + ytd.bankCharges} className="font-display text-lg font-bold" />
          </div>
        </div>
        <CategoryBar lang={lang} interactive={false} />
      </a>

      {/* Help */}
      <a
        href="https://wa.me/60167789012"
        className="flex min-h-14 items-center gap-3 rounded-[18px] border border-dashed bg-transparent px-3 py-2.5 hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
      >
        <IconBubble Icon={MessageCircle} tone="pine" />
        <span className="min-w-0 flex-1">
          <span className="block text-base font-semibold">{t("helpTitle")}</span>
          <span className="block text-sm text-muted-foreground">{t("helpBody")}</span>
        </span>
        <ChevronRight className="h-5 w-5 shrink-0 text-muted-foreground" aria-hidden />
      </a>
    </MemberShell>
  );
}
