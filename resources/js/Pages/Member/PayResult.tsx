import { useCallback, useEffect, useRef, useState } from "react";
import { Clock, FileText, Hourglass, ImageIcon, Loader2, MailCheck, RefreshCw, ShieldCheck, XCircle } from "lucide-react";
import { Head, Link, router } from "@inertiajs/react";
import { MemberShell } from "@/components/app/MemberShell";
import { FlashBanner, HelpLine, useFeeLabel } from "@/components/app/MemberParts";
import { Money } from "@/components/app/Money";
import { ReceiptActions } from "@/components/app/ReceiptActions";
import { ReceiptCard } from "@/components/app/ReceiptCard";
import { StatusChip } from "@/components/app/StatusBadges";
import { Button } from "@/components/ui/button";
import { fmtDate } from "@/lib/format";
import { fill, useLang, useT } from "@/lib/i18n";
import type { GroupReceipt, MemberPaymentRow, MemberReceipt, PaymentContext, PaymentGroup, TransferVerification } from "@/types/member";

/** "verifying": a "Bayar semua" slip the treasurer is still checking. */
type Outcome = "paid" | "pending" | "failed" | "verifying";

interface Props extends PaymentContext {
  outcome: Outcome;
  /** The fee; null for "Bayar semua", which sends `group`. */
  payment: MemberPaymentRow | null;
  group?: PaymentGroup | null;
  /** A group receipt carries one line per fee. */
  receipt: MemberReceipt | GroupReceipt | null;
  /** "Bayar semua" by transfer: what was sent. */
  verification?: TransferVerification | null;
  gateway: string | null;
  reference: string | null;
  statusUrl: string;
  reviewUrl: string;
  /** "Bayar semua" paid: the group receipt's clean URL (PayAllController::result). */
  receiptUrl?: string | null;
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

function Details({ payment, group, gateway, reference }: Pick<Props, "payment" | "group" | "gateway" | "reference">) {
  const t = useT();
  const feeLabel = useFeeLabel();
  return (
    <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2.5 rounded-[20px] border bg-card p-4 text-base">
      <dt className="text-muted-foreground">{t("amount")}</dt>
      <dd className="text-right font-semibold">
        <Money sen={group ? group.sumSen : (payment?.amount ?? 0)} />
      </dd>
      <dt className="text-muted-foreground">{t("paidFor")}</dt>
      <dd className="text-right">
        {group ? (
          <ul className="flex flex-col gap-1" data-testid="group-fees">
            {group.fees.map((f) => (
              <li key={f.id}>{feeLabel(f)}</li>
            ))}
          </ul>
        ) : (
          payment && feeLabel(payment)
        )}
      </dd>
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

export default function PayResult({ outcome, payment, group, receipt, verification, gateway, reference, statusUrl, reviewUrl, receiptUrl, member, payClub, paymentsUrl, dashboardUrl }: Props) {
  const t = useT();
  const { lang } = useLang();
  const feeLabel = useFeeLabel();
  const nav = { home: dashboardUrl, payments: paymentsUrl };
  const poll = usePaymentStatusPoll(statusUrl, outcome === "pending");

  if (outcome === "verifying" && group) {
    return (
      <MemberShell tab="payments" navHrefs={nav} clubName={payClub.name} titleIsHeading={false} title={t("paymentStatus")}>
        <Head title={t("paymentStatus")} />
        <h1 className="sr-only">{t("paymentStatus")}</h1>
        <FlashBanner />
        <section aria-labelledby="vf-h" className="flex flex-col gap-4 rounded-2xl bg-warning-soft p-4 text-warning-foreground ring-1 ring-warning/30" data-testid="verifying">
          <div className="flex items-start gap-3">
            <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-card text-warning-foreground ring-1 ring-warning/30">
              <Hourglass className="h-6 w-6" strokeWidth={1.75} aria-hidden />
            </span>
            <div className="min-w-0">
              <h2 id="vf-h" className="font-display text-xl font-bold leading-tight tracking-tight">
                {t("verifyingTitle")}
              </h2>
              <p className="mt-1 text-base">{fill(t("sentTogether"), { n: group.fees.length })}</p>
              <p className="mt-1 text-base">{t("verifyingBody")}</p>
            </div>
          </div>
          {verification && (
            <dl className="grid grid-cols-2 gap-3 rounded-xl bg-card/70 p-3 text-foreground">
              {verification.transferDate && (
                <div className="min-w-0">
                  <dt className="text-sm text-muted-foreground">{t("transferDateLbl")}</dt>
                  <dd className="text-base font-semibold">{fmtDate(verification.transferDate, lang)}</dd>
                </div>
              )}
              {reference && (
                <div className="min-w-0">
                  <dt className="text-sm text-muted-foreground">{t("reference")}</dt>
                  <dd className="break-words font-mono text-base">{reference}</dd>
                </div>
              )}
            </dl>
          )}
          {verification && verification.slips.length > 0 && (
            <ul className="flex flex-col gap-2">
              {verification.slips.map((sl) => (
                <li key={sl.id}>
                  <a
                    href={sl.url}
                    target="_blank"
                    rel="noopener"
                    className="flex min-h-11 items-center gap-2 rounded-xl bg-card px-3 py-2 text-base font-semibold text-foreground ring-1 ring-border hover:bg-muted"
                    aria-label={fill(t("openFile"), { f: sl.name })}
                  >
                    {sl.isPdf ? <FileText className="h-5 w-5 shrink-0 text-danger" aria-hidden /> : <ImageIcon className="h-5 w-5 shrink-0 text-info" aria-hidden />}
                    <span className="min-w-0 break-all">{sl.name}</span>
                  </a>
                </li>
              ))}
            </ul>
          )}
        </section>
        <Details payment={null} group={group} gateway={null} reference={null} />
        <Button asChild size="xl" className="w-full rounded-full">
          <Link href={dashboardUrl}>{t("backHome")}</Link>
        </Button>
        <HelpLine club={payClub} reference={reference} />
      </MemberShell>
    );
  }

  if (outcome === "paid" && receipt) {
    const lines = "lines" in receipt ? receipt.lines : null;
    const viaTransfer = receipt.method === "transfer";
    return (
      <MemberShell tab="payments" navHrefs={nav} clubName={payClub.name} titleIsHeading={false} title={t("successChip")}>
        <Head title={t("successTitle")} />
        <div className="flex flex-col items-center gap-3 pt-2 text-center" aria-live="polite">
          <AnimatedTick />
          <StatusChip Icon={ShieldCheck} tone="success" size="md">
            {t("successChip")}
          </StatusChip>
          <h1 className="font-display text-[1.75rem] font-extrabold leading-tight tracking-tight">{t("successTitle")}</h1>
          <p className="text-base text-muted-foreground">{t("receiptEmailed")}</p>
        </div>
        {lines && <p className="-mt-2 text-center text-base font-semibold">{fill(t("paidTogether"), { n: lines.length })}</p>}
        <ReceiptCard
          club={{ name: payClub.name }}
          member={{ name: member.name }}
          receipt={{
            receiptNo: receipt.receiptNo,
            amount: receipt.amount,
            paidAt: receipt.paidAt,
            method: viaTransfer ? t("methodTransferReceipt") : receipt.gateway ? "FPX" : t("recordedByTreasurer"),
            bank: viaTransfer ? undefined : (receipt.gateway ?? undefined),
            fpxTxn: receipt.transactionId ?? undefined,
            gatewayRef: receipt.reference ?? undefined,
            // A group lists every fee with its own receipt number.
            items: lines
              ? lines.map((l) => ({ id: l.id, description: feeLabel(l), note: l.receiptNo, amount: l.amount }))
              : payment
                ? [{ id: payment.id, description: feeLabel(payment), amount: payment.amount }]
                : [],
          }}
        />
        {"paidOnline" in receipt && receipt.paidOnline.length > 0 && (
          <section className="rounded-[20px] border bg-card p-4 text-base" data-testid="paid-online-note">
            <p>{t("paidOnlineNote")}</p>
            <ul className="mt-2 flex flex-col gap-1">
              {receipt.paidOnline.map((f) => (
                <li key={f.id} className="flex flex-wrap justify-between gap-x-3">
                  <Link href={f.invoiceUrl} className="font-semibold text-primary underline-offset-4 hover:underline">
                    {feeLabel(f)}
                  </Link>
                  <span className="text-muted-foreground">
                    <Money sen={f.amount} /> · {t("paidOnlineInstead")}
                  </span>
                </li>
              ))}
            </ul>
          </section>
        )}
        <section className="rounded-[20px] bg-accent p-4 text-accent-foreground">
          <h2 className="font-display text-lg font-bold">{t("whatsNext")}</h2>
          <p className="mt-1 text-base">{t("whatsNextBody")}</p>
        </section>
        <ReceiptActions receiptNo={receipt.receiptNo} amount={receipt.amount} paidAt={receipt.paidAt} clubName={payClub.name} url={payment?.urls.invoice ?? receiptUrl ?? undefined} />
        <div className="flex flex-col gap-2">
          <Button asChild variant="outline" size="xl" className="w-full rounded-full">
            <Link href={dashboardUrl}>{t("backHome")}</Link>
          </Button>
          {payment ? (
            <Button asChild variant="ghost" size="lg" className="h-12 rounded-full">
              <Link href={payment.urls.invoice}>{t("viewReceipt")}</Link>
            </Button>
          ) : (
            <Button asChild variant="ghost" size="lg" className="h-12 rounded-full">
              <Link href={paymentsUrl}>{t("viewPayments")}</Link>
            </Button>
          )}
        </div>
        <HelpLine club={payClub} fee={payment} reference={receipt.receiptNo} />
      </MemberShell>
    );
  }

  if (outcome === "failed") {
    return (
      <MemberShell tab="payments" navHrefs={nav} clubName={payClub.name} titleIsHeading={false} title={t("failedTitle")} hideNav>
        <Head title={t("failedTitle")} />
        <div className="flex flex-col items-center gap-3 pt-2 text-center" aria-live="polite">
          <span className="flex h-24 w-24 items-center justify-center rounded-full bg-danger-soft text-danger ring-8 ring-danger-soft/50">
            <XCircle className="h-12 w-12" strokeWidth={1.75} aria-hidden />
          </span>
          <StatusChip Icon={XCircle} tone="danger" size="md">
            {t("failedChip")}
          </StatusChip>
          <h1 className="font-display text-[1.75rem] font-extrabold leading-tight tracking-tight">{t("failedTitle")}</h1>
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
        <Details payment={payment} group={group} gateway={gateway} reference={reference} />
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
        <HelpLine club={payClub} fee={payment} reference={reference ?? payment?.invoiceNo} />
      </MemberShell>
    );
  }

  // Pending (also the fallback when the redirect back says nothing conclusive).
  return (
    <MemberShell tab="payments" navHrefs={nav} clubName={payClub.name} titleIsHeading={false} title={t("processing")} hideNav>
      <Head title={t("processing")} />
      <div className="flex flex-col items-center gap-3 pt-2 text-center" aria-live="polite">
        <span className="flex h-24 w-24 items-center justify-center rounded-full bg-warning-soft text-warning ring-8 ring-warning-soft/50">
          <Clock className="h-12 w-12" strokeWidth={1.75} aria-hidden />
        </span>
        <StatusChip Icon={Clock} tone="warning" size="md">
          {t("processing")}
        </StatusChip>
        <h1 className="font-display text-[1.75rem] font-extrabold leading-tight tracking-tight">{t("pendingTitle")}</h1>
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
      <Details payment={payment} group={group} gateway={gateway} reference={reference} />
      <div className="flex flex-col gap-2">
        <Button size="xl" className="w-full rounded-full" type="button" onClick={() => void poll.check()} disabled={poll.checking}>
          {poll.checking ? <Loader2 className="animate-spin" aria-hidden /> : <RefreshCw aria-hidden />}
          {poll.checking ? t("checking") : t("checkAgain")}
        </Button>
        {poll.checkedAt && (
          <p className="text-center text-sm text-muted-foreground" aria-live="polite">
            {t("checkedAt")} {poll.checkedAt.toLocaleTimeString(lang === "bm" ? "ms-MY" : "en-MY", { hour: "numeric", minute: "2-digit", second: "2-digit" })}
          </p>
        )}
        <Button asChild variant="ghost" size="lg" className="h-12 rounded-full">
          <Link href={dashboardUrl}>{t("backHome")}</Link>
        </Button>
      </div>
      <HelpLine club={payClub} fee={payment} reference={reference ?? payment?.invoiceNo} />
    </MemberShell>
  );
}
