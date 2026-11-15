import { useCallback, useEffect, useRef, useState } from "react";
import { Clock, Loader2, MailCheck, RefreshCw, ShieldCheck, XCircle } from "lucide-react";
import { Head, Link, router } from "@inertiajs/react";
import { MemberShell } from "@/components/app/MemberShell";
import { HelpLine, useFeeLabel } from "@/components/app/MemberParts";
import { Money } from "@/components/app/Money";
import { ReceiptCard } from "@/components/app/ReceiptCard";
import { StatusChip } from "@/components/app/StatusBadges";
import { Button } from "@/components/ui/button";
import { useT } from "@/lib/i18n";
import type { MemberPaymentRow, MemberReceipt, PaymentContext } from "@/types/member";

type Outcome = "paid" | "pending" | "failed";

interface Props extends PaymentContext {
  outcome: Outcome;
  payment: MemberPaymentRow;
  receipt: MemberReceipt | null;
  gateway: string | null;
  reference: string | null;
  statusUrl: string;
  reviewUrl: string;
}

/** Poll every 5 s for about 30 s (6 checks), then tell the member we'll email them. */
const POLL_MS = 5000;
const POLL_MAX = 6;

function AnimatedTick() {
  return (
    <div className="relative flex h-24 w-24 items-center justify-center">
      <span className="absolute inset-0 animate-ring-out rounded-full bg-success/30 motion-reduce:hidden" aria-hidden />
      <span className="flex h-24 w-24 animate-pop-in items-center justify-center rounded-full bg-success shadow-[0_14px_30px_-12px_rgb(var(--success)/0.7)] motion-reduce:animate-none">
        <svg viewBox="0 0 48 48" className="h-12 w-12" aria-hidden>
          <path
            d="M13 25 L21 33 L36 16"
            fill="none"
            stroke="rgb(var(--on-success))"
            strokeWidth="5"
            strokeLinecap="round"
            strokeLinejoin="round"
            strokeDasharray="48"
            className="animate-draw-tick motion-reduce:animate-none"
          />
        </svg>
      </span>
    </div>
  );
}

function Details({ payment, gateway, reference }: Pick<Props, "payment" | "gateway" | "reference">) {
  const t = useT();
  const feeLabel = useFeeLabel();
  return (
    <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2.5 rounded-[20px] border bg-card p-4 text-base">
      <dt className="text-muted-foreground">{t("amount")}</dt>
      <dd className="text-right font-semibold">
        <Money sen={payment.amount} />
      </dd>
      <dt className="text-muted-foreground">{t("paidFor")}</dt>
      <dd className="text-right">{feeLabel(payment)}</dd>
      {gateway && (
        <>
          <dt className="text-muted-foreground">{t("method")}</dt>
          <dd className="text-right">FPX · {gateway}</dd>
        </>
      )}
      {reference && (
        <>
          <dt className="text-muted-foreground">{t("reference")}</dt>
          <dd className="break-all text-right font-mono text-sm leading-6">{reference}</dd>
        </>
      )}
    </dl>
  );
}

function usePaymentStatusPoll(statusUrl: string, enabled: boolean) {
  const [checks, setChecks] = useState(0);
  const [checking, setChecking] = useState(false);
  const [checkedAt, setCheckedAt] = useState<Date | null>(null);
  const alive = useRef(true);

  const check = useCallback(async () => {
    setChecking(true);
    try {
      const res = await fetch(statusUrl, { headers: { Accept: "application/json" }, credentials: "same-origin", cache: "no-store" });
      if (res.ok) {
        const data = (await res.json()) as { status?: string };
        // Paid: reload this page so the server renders the confirmed receipt.
        if (data.status === "paid" && alive.current) router.reload();
      }
    } catch {
      // Network hiccup: keep the pending screen; the next check or the email covers it.
    } finally {
      if (alive.current) {
        setChecking(false);
        setCheckedAt(new Date());
        setChecks((n) => n + 1);
      }
    }
  }, [statusUrl]);

  useEffect(() => {
    alive.current = true;
    return () => {
      alive.current = false;
    };
  }, []);

  useEffect(() => {
    if (!enabled || checks >= POLL_MAX) return;
    const id = window.setTimeout(check, POLL_MS);
    return () => window.clearTimeout(id);
  }, [enabled, checks, check]);

  return { check, checking, checkedAt, gaveUp: checks >= POLL_MAX };
}

export default function PayResult({ outcome, payment, receipt, gateway, reference, statusUrl, reviewUrl, member, payClub, paymentsUrl, dashboardUrl }: Props) {
  const t = useT();
  const feeLabel = useFeeLabel();
  const nav = { home: dashboardUrl, payments: paymentsUrl };
  const poll = usePaymentStatusPoll(statusUrl, outcome === "pending");

  if (outcome === "paid" && receipt) {
    return (
      <MemberShell tab="payments" navHrefs={nav} clubName={payClub.name} title={t("successChip")}>
        <Head title={t("successTitle")} />
        <div className="flex flex-col items-center gap-3 pt-2 text-center" aria-live="polite">
          <AnimatedTick />
          <StatusChip Icon={ShieldCheck} tone="success" size="md">
            {t("successChip")}
          </StatusChip>
          <h1 className="font-display text-[28px] font-extrabold leading-tight tracking-tight">{t("successTitle")}</h1>
          <p className="text-base text-muted-foreground">{t("receiptEmailed")}</p>
        </div>
        <ReceiptCard
          club={{ name: payClub.name }}
          member={{ name: member.name }}
          receipt={{
            receiptNo: receipt.receiptNo,
            amount: receipt.amount,
            paidAt: receipt.paidAt,
            method: receipt.gateway ? "FPX" : t("recordedByTreasurer"),
            bank: receipt.gateway ?? undefined,
            fpxTxn: receipt.transactionId ?? undefined,
            gatewayRef: receipt.reference ?? undefined,
            items: [{ id: payment.id, description: feeLabel(payment), amount: payment.amount }],
          }}
        />
        <section className="rounded-[20px] bg-accent p-4 text-accent-foreground">
          <h2 className="font-display text-lg font-bold">{t("whatsNext")}</h2>
          <p className="mt-1 text-base">{t("whatsNextBody")}</p>
        </section>
        <div className="flex flex-col gap-2">
          <Button asChild size="xl" className="w-full rounded-full">
            <Link href={dashboardUrl}>{t("backHome")}</Link>
          </Button>
          <Button asChild variant="ghost" size="lg" className="h-12 rounded-full">
            <Link href={payment.urls.invoice}>{t("viewReceipt")}</Link>
          </Button>
        </div>
      </MemberShell>
    );
  }

  if (outcome === "failed") {
    return (
      <MemberShell tab="payments" navHrefs={nav} clubName={payClub.name} title={t("failedTitle")} hideNav>
        <Head title={t("failedTitle")} />
        <div className="flex flex-col items-center gap-3 pt-2 text-center" aria-live="polite">
          <span className="flex h-24 w-24 items-center justify-center rounded-full bg-danger-soft text-danger ring-8 ring-danger-soft/50">
            <XCircle className="h-12 w-12" strokeWidth={1.75} aria-hidden />
          </span>
          <StatusChip Icon={XCircle} tone="danger" size="md">
            {t("failedChip")}
          </StatusChip>
          <h1 className="font-display text-[28px] font-extrabold leading-tight tracking-tight">{t("failedTitle")}</h1>
          <p className="text-base text-muted-foreground">{t("failedReason")}</p>
        </div>
        {/* The return URL is unverified: say what we know (not received), never "no money was taken". */}
        <div className="flex items-start gap-3 rounded-[20px] border-2 border-warning bg-warning-soft p-4">
          <ShieldCheck className="mt-0.5 h-6 w-6 shrink-0 text-warning" strokeWidth={1.75} aria-hidden />
          <div>
            <p className="font-display text-xl font-bold text-warning-foreground">{t("notReceivedTitle")}</p>
            <p className="mt-1 text-base text-foreground">{t("failedMoneyLeftAdvice")}</p>
          </div>
        </div>
        <Details payment={payment} gateway={gateway} reference={reference} />
        <div className="flex flex-col gap-2">
          <Button asChild size="xl" className="w-full rounded-full">
            <Link href={reviewUrl}>
              <RefreshCw aria-hidden /> {t("tryAgain")}
            </Link>
          </Button>
          <Button asChild variant="ghost" size="lg" className="h-12 rounded-full">
            <Link href={dashboardUrl}>{t("backHome")}</Link>
          </Button>
        </div>
        <HelpLine email={payClub.email} />
      </MemberShell>
    );
  }

  // Pending (also the fallback when the redirect back says nothing conclusive).
  return (
    <MemberShell tab="payments" navHrefs={nav} clubName={payClub.name} title={t("processing")} hideNav>
      <Head title={t("processing")} />
      <div className="flex flex-col items-center gap-3 pt-2 text-center" aria-live="polite">
        <span className="flex h-24 w-24 items-center justify-center rounded-full bg-warning-soft text-warning ring-8 ring-warning-soft/50">
          <Clock className="h-12 w-12" strokeWidth={1.75} aria-hidden />
        </span>
        <StatusChip Icon={Clock} tone="warning" size="md">
          {t("processing")}
        </StatusChip>
        <h1 className="font-display text-[28px] font-extrabold leading-tight tracking-tight">{t("pendingTitle")}</h1>
        <p className="text-base text-muted-foreground">{t("pendingBody")}</p>
      </div>
      <div role="alert" className="flex items-start gap-3 rounded-[20px] border-2 border-warning bg-warning-soft p-4">
        <ShieldCheck className="mt-0.5 h-6 w-6 shrink-0 text-warning" strokeWidth={1.75} aria-hidden />
        <div>
          <p className="font-display text-xl font-bold text-warning-foreground">
            {t("processing")}, {t("dontPayAgain").toLowerCase()}
          </p>
          <p className="mt-1 text-base text-foreground">{t("pendingHint")}</p>
        </div>
      </div>
      {poll.gaveUp && (
        <div className="flex items-start gap-3 rounded-[20px] border bg-card p-4" aria-live="polite">
          <MailCheck className="mt-0.5 h-6 w-6 shrink-0 text-primary" strokeWidth={1.75} aria-hidden />
          <p className="text-base">{t("stillWaiting")}</p>
        </div>
      )}
      <Details payment={payment} gateway={gateway} reference={reference} />
      <div className="flex flex-col gap-2">
        <Button size="xl" className="w-full rounded-full" type="button" onClick={() => void poll.check()} disabled={poll.checking}>
          {poll.checking ? <Loader2 className="animate-spin" aria-hidden /> : <RefreshCw aria-hidden />}
          {poll.checking ? t("checking") : t("checkAgain")}
        </Button>
        {poll.checkedAt && (
          <p className="text-center text-sm text-muted-foreground" aria-live="polite">
            {t("checkedAt")} {poll.checkedAt.toLocaleTimeString([], { hour: "numeric", minute: "2-digit", second: "2-digit" })}
          </p>
        )}
        <Button asChild variant="ghost" size="lg" className="h-12 rounded-full">
          <Link href={dashboardUrl}>{t("backHome")}</Link>
        </Button>
      </div>
      <HelpLine email={payClub.email} />
    </MemberShell>
  );
}
