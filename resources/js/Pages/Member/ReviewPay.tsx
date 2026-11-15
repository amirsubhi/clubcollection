import { useState } from "react";
import { AlertTriangle, Loader2, Lock, ShieldCheck } from "lucide-react";
import { Head, Link, router } from "@inertiajs/react";
import { MemberShell } from "@/components/app/MemberShell";
import { FlashBanner, HelpLine, useFeeLabel } from "@/components/app/MemberParts";
import { Money } from "@/components/app/Money";
import { FpxMark } from "@/components/app/Visuals";
import { Alert } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { fmtDate, rm } from "@/lib/format";
import { fill, useLang, useT } from "@/lib/i18n";
import type { MemberPaymentRow, PaymentContext } from "@/types/member";

interface Props extends PaymentContext {
  payment: MemberPaymentRow;
  gateway: { name: string; label: string; available: boolean };
  hasOpenBill: boolean;
  payUrl: string;
}

/** Review & pay: a confirm step that is clearly not a receipt; the amount is on the one primary button. */
export default function ReviewPay({ payment, gateway, hasOpenBill, payUrl, member, payClub, paymentsUrl, dashboardUrl }: Props) {
  const t = useT();
  const { lang } = useLang();
  const feeLabel = useFeeLabel();
  const [sent, setSent] = useState(false);
  const gw = { gw: gateway.label };

  const pay = () => {
    if (sent || !gateway.available) return;
    setSent(true);
    // The server answers with an external redirect (X-Inertia-Location) to the gateway,
    // or back here with a flash error, in which case the button is enabled again.
    router.post(payUrl, {}, { onError: () => setSent(false), onSuccess: () => setSent(false) });
  };

  return (
    <MemberShell tab="payments" navHrefs={{ home: dashboardUrl, payments: paymentsUrl }} clubName={payClub.name} title={t("reviewPay")} backHref={payment.urls.invoice} hideNav>
      <Head title={t("reviewPay")} />
      <div className="flex flex-col items-center gap-1 pt-1 text-center">
        <span className="text-base text-muted-foreground">{t("payFeeTitle")}</span>
        <Money sen={payment.amount} className="figure font-display text-[44px] font-extrabold leading-none tracking-tight" />
      </div>

      <FlashBanner />

      <section aria-labelledby="sheet-h" className="relative flex animate-sheet-up flex-col gap-5 rounded-[28px] border bg-card p-5 shadow-[0_-12px_40px_-24px_rgba(30,27,58,0.45)] motion-reduce:animate-none">
        <h1 id="sheet-h" className="font-display text-2xl font-bold tracking-tight">
          {t("reviewPay")}
        </h1>

        <dl className="flex flex-col divide-y rounded-2xl border">
          <div className="px-4 py-3">
            <dt className="text-sm text-muted-foreground">{t("paidFor")}</dt>
            <dd className="flex justify-between gap-2 text-base">
              <span className="min-w-0">
                {feeLabel(payment)}
                <span className="block text-sm text-muted-foreground">
                  {t(`freq_${payment.frequency}`)} · {t("due")} {fmtDate(payment.dueDate, lang)}
                </span>
              </span>
              <Money sen={payment.amount} className="font-semibold" />
            </dd>
          </div>
          <div className="px-4 py-3">
            <dt className="text-sm text-muted-foreground">{t("member")}</dt>
            <dd className="truncate text-base font-semibold">{member.name}</dd>
          </div>
          <div className="px-4 py-3">
            <dt className="text-sm text-muted-foreground">{t("payee")}</dt>
            <dd className="text-base font-semibold">{payClub.name}</dd>
          </div>
          <div className="flex items-center justify-between gap-3 bg-muted/60 px-4 py-3">
            <dt className="text-base font-semibold">{t("total")}</dt>
            <dd>
              <Money sen={payment.amount} className="font-display text-2xl font-bold" />
            </dd>
          </div>
        </dl>
        <Link href={payment.urls.invoice} className="-mt-3 inline-flex min-h-11 items-center self-start text-base font-semibold text-primary underline-offset-4 hover:underline">
          {t("invoice")} {payment.invoiceNo}
        </Link>

        <div className="flex flex-col gap-3">
          <div className="flex items-center justify-between gap-2">
            <span className="text-base font-semibold">{t("payWith")}</span>
            <FpxMark />
          </div>
          <div className="flex items-start gap-3 rounded-2xl bg-accent/70 p-3 text-sm text-accent-foreground">
            <ShieldCheck className="mt-0.5 h-5 w-5 shrink-0" strokeWidth={1.75} aria-hidden />
            <p>
              <span className="font-semibold">{fill(t("securedGw"), gw)}.</span> {fill(t("handoffGw"), gw)}
            </p>
          </div>
        </div>

        {hasOpenBill && (
          <Alert variant="warning" className="text-base">
            <AlertTriangle aria-hidden />
            <p>{t("openBillWarn")}</p>
          </Alert>
        )}
        {!gateway.available && (
          <Alert variant="danger" className="text-base">
            <AlertTriangle aria-hidden />
            <p>{t("gatewayUnavailable")}</p>
          </Alert>
        )}

        <div className="flex flex-col gap-2">
          <Button size="xl" className="w-full rounded-full text-lg" onClick={pay} disabled={sent || !gateway.available} aria-live="polite">
            {sent ? (
              <>
                <Loader2 className="animate-spin" aria-hidden /> {fill(t("redirectingGw"), gw)}
              </>
            ) : (
              <>
                <Lock aria-hidden /> {t("payNow")} {rm(payment.amount)}
              </>
            )}
          </Button>
          <p className="text-center text-sm text-muted-foreground">FPX · {gateway.label}</p>
        </div>
      </section>

      <HelpLine email={payClub.email} />
    </MemberShell>
  );
}
