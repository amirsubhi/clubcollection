import { Clock, Download, MessageCircle, RefreshCw, ShieldCheck, XCircle } from "lucide-react";
import { MemberShell } from "@/components/app/MemberShell";
import { Money } from "@/components/app/Money";
import { ReceiptCard } from "@/components/app/ReceiptCard";
import { StatusChip } from "@/components/app/StatusBadges";
import { Button } from "@/components/ui/button";
import { latestReceipt } from "@/data/payments";
import { fmtDateTime } from "@/lib/format";
import { useLang, useT } from "@/i18n";

export type PayOutcome = "success" | "pending" | "failed";

function AnimatedTick() {
  return (
    <div className="relative flex h-24 w-24 items-center justify-center">
      <span className="absolute inset-0 rounded-full bg-success/30 animate-ring-out" aria-hidden />
      <span className="flex h-24 w-24 animate-pop-in items-center justify-center rounded-full bg-success shadow-[0_14px_30px_-12px_rgb(var(--success)/0.7)]">
        <svg viewBox="0 0 48 48" className="h-12 w-12" aria-hidden>
          <path d="M13 25 L21 33 L36 16" fill="none" stroke="rgb(var(--on-success))" strokeWidth="5" strokeLinecap="round" strokeLinejoin="round" strokeDasharray="48" className="animate-draw-tick" />
        </svg>
      </span>
    </div>
  );
}

function HelpLink() {
  const t = useT();
  return (
    <a href="https://wa.me/60167789012" className="flex min-h-12 items-center justify-center gap-2 text-base font-semibold text-primary hover:underline">
      <MessageCircle className="h-5 w-5" strokeWidth={1.75} aria-hidden /> {t("helpTitle")} {t("helpBody")}
    </a>
  );
}

function Details() {
  const t = useT();
  const r = latestReceipt;
  return (
    <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2.5 rounded-[20px] border bg-card p-4 text-base">
      <dt className="text-muted-foreground">{t("amount")}</dt>
      <dd className="text-right font-semibold">
        <Money sen={r.amount} />
      </dd>
      <dt className="text-muted-foreground">{t("paidOn")}</dt>
      <dd className="text-right">{fmtDateTime(r.paidAt)}</dd>
      <dt className="text-muted-foreground">{t("bank")}</dt>
      <dd className="text-right">FPX · {r.bank}</dd>
      <dt className="text-muted-foreground">{t("reference")}</dt>
      <dd className="text-right font-mono text-sm leading-6">{r.gatewayRef}</dd>
    </dl>
  );
}

export function PayResult({ outcome }: { outcome: PayOutcome }) {
  const t = useT();
  const { lang } = useLang();
  const r = latestReceipt;

  if (outcome === "success") {
    return (
      <MemberShell tab="payments" title={t("successTitle")}>
        <div className="flex flex-col items-center gap-3 pt-2 text-center" aria-live="polite">
          <AnimatedTick />
          <h1 className="font-display text-[28px] font-extrabold leading-tight tracking-tight">{t("successTitle")}</h1>
          <p className="text-base text-muted-foreground">{t("successBody")}</p>
        </div>
        <ReceiptCard />
        <div className="grid grid-cols-1 gap-2 min-[400px]:grid-cols-2">
          <Button variant="outline" size="lg" type="button" className="h-12">
            <Download aria-hidden /> {t("download")}
          </Button>
          <Button variant="outline" size="lg" type="button" className="h-12">
            <MessageCircle aria-hidden /> {t("shareWa")}
          </Button>
        </div>
        <section className="rounded-[20px] bg-accent p-4 text-accent-foreground">
          <h2 className="font-display text-lg font-bold">{t("whatsNext")}</h2>
          <p className="mt-1 text-base">{t("whatsNextBody")}</p>
        </section>
        <Button asChild size="xl" className="w-full rounded-full">
          <a href="#member-home">{t("backHome")}</a>
        </Button>
      </MemberShell>
    );
  }

  if (outcome === "pending") {
    return (
      <MemberShell tab="payments" title={lang === "bm" ? "Dalam proses" : "Processing"} hideNav>
        <div className="flex flex-col items-center gap-3 pt-2 text-center" aria-live="polite">
          <span className="flex h-24 w-24 items-center justify-center rounded-full bg-warning-soft text-warning ring-8 ring-warning-soft/50">
            <Clock className="h-12 w-12" strokeWidth={1.75} aria-hidden />
          </span>
          <StatusChip Icon={Clock} tone="warning" size="md">
            {lang === "bm" ? "Dalam proses" : "Processing"}
          </StatusChip>
          <h1 className="font-display text-[28px] font-extrabold leading-tight tracking-tight">{t("pendingTitle")}</h1>
          <p className="text-base text-muted-foreground">{t("pendingBody")}</p>
        </div>
        <div role="alert" className="flex items-start gap-3 rounded-[20px] border-2 border-warning bg-warning-soft p-4">
          <ShieldCheck className="mt-0.5 h-6 w-6 shrink-0 text-warning" strokeWidth={1.75} aria-hidden />
          <div>
            <p className="font-display text-xl font-bold text-warning-foreground">{t("dontPayAgain")}</p>
            <p className="mt-1 text-base text-foreground">{t("pendingHint")}</p>
          </div>
        </div>
        <Details />
        <div className="flex flex-col gap-2">
          <Button size="xl" className="w-full rounded-full" type="button">
            <RefreshCw aria-hidden /> {t("checkAgain")}
          </Button>
          <p className="text-center text-sm text-muted-foreground">
            {t("checkedAt")} {fmtDateTime(r.paidAt)}
          </p>
          <Button asChild variant="ghost" size="lg" className="h-12 rounded-full">
            <a href="#member-home">{t("backHome")}</a>
          </Button>
        </div>
        <HelpLink />
      </MemberShell>
    );
  }

  return (
    <MemberShell tab="payments" title={t("failedTitle")} hideNav>
      <div className="flex flex-col items-center gap-3 pt-2 text-center" aria-live="polite">
        <span className="flex h-24 w-24 items-center justify-center rounded-full bg-danger-soft text-danger ring-8 ring-danger-soft/50">
          <XCircle className="h-12 w-12" strokeWidth={1.75} aria-hidden />
        </span>
        <StatusChip Icon={XCircle} tone="danger" size="md">
          {lang === "bm" ? "Gagal" : "Failed"}
        </StatusChip>
        <h1 className="font-display text-[28px] font-extrabold leading-tight tracking-tight">{t("failedTitle")}</h1>
        <p className="text-base text-muted-foreground">{t("failedReason")}</p>
      </div>
      <div className="flex items-start gap-3 rounded-[20px] border-2 border-success/40 bg-success-soft p-4">
        <ShieldCheck className="mt-0.5 h-6 w-6 shrink-0 text-success" strokeWidth={1.75} aria-hidden />
        <div>
          <p className="font-display text-xl font-bold text-success-foreground">{t("noMoneyTaken")}</p>
          <p className="mt-1 text-base text-foreground">{t("failedMoneyLeft")}</p>
        </div>
      </div>
      <Details />
      <div className="flex flex-col gap-2">
        <Button asChild size="xl" className="w-full rounded-full">
          <a href="#member-pay">
            <RefreshCw aria-hidden /> {t("tryAgain")}
          </a>
        </Button>
        <Button asChild variant="outline" size="lg" className="h-12 rounded-full">
          <a href="https://wa.me/60167789012">
            <MessageCircle aria-hidden /> {t("contactTreasurer")}
          </a>
        </Button>
      </div>
    </MemberShell>
  );
}
