import { Hourglass, Lock, Printer } from "lucide-react";
import { Head, Link } from "@inertiajs/react";
import { MemberShell } from "@/components/app/MemberShell";
import { FlashBanner, HelpLine, statusLabelKey, useFeeLabel } from "@/components/app/MemberParts";
import { Money } from "@/components/app/Money";
import { ReceiptCard } from "@/components/app/ReceiptCard";
import { PaymentStatusBadge } from "@/components/app/StatusBadges";
import { Button } from "@/components/ui/button";
import { fmtDate, rm } from "@/lib/format";
import { useLang, useT } from "@/lib/i18n";
import type { MemberPaymentRow, MemberReceipt, PaymentContext } from "@/types/member";

interface Props extends PaymentContext {
  payment: MemberPaymentRow;
  receipt: MemberReceipt | null;
  issuedOn: string | null;
  discount: { name: string; type: "fixed" | "percentage" | string; value: number } | null;
}

function PrintButton() {
  const t = useT();
  return (
    <Button variant="outline" size="lg" type="button" className="h-12 rounded-full print:hidden" onClick={() => window.print()}>
      <Printer aria-hidden /> {t("print")}
    </Button>
  );
}

export default function Invoice({ payment, receipt, issuedOn, discount, member, payClub, paymentsUrl, dashboardUrl }: Props) {
  const t = useT();
  const { lang } = useLang();
  const feeLabel = useFeeLabel();
  const nav = { home: dashboardUrl, payments: paymentsUrl };

  if (receipt) {
    return (
      <MemberShell tab="payments" navHrefs={nav} clubName={payClub.name} title={t("receipt")} backHref={paymentsUrl}>
        <Head title={t("receipt")} />
        <FlashBanner />
        <ReceiptCard
          club={{ name: payClub.name }}
          member={{ name: member.name }}
          receipt={{
            receiptNo: receipt.receiptNo,
            amount: receipt.amount,
            paidAt: receipt.paidAt,
            method: receipt.method === "gateway" ? "FPX" : receipt.method === "transfer" ? t("methodTransferReceipt") : t("recordedByTreasurer"),
            bank: receipt.gateway ?? undefined,
            fpxTxn: receipt.transactionId ?? undefined,
            gatewayRef: receipt.reference ?? undefined,
            items: [{ id: payment.id, description: feeLabel(payment), amount: payment.amount }],
          }}
        />
        <PrintButton />
      </MemberShell>
    );
  }

  return (
    <MemberShell tab="payments" navHrefs={nav} clubName={payClub.name} title={t("invoice")} backHref={paymentsUrl}>
      <Head title={t("invoice")} />
      <FlashBanner />
      <section aria-label={t("invoice")} className="flex flex-col gap-5 rounded-[20px] border bg-card p-5">
        <div className="flex flex-wrap items-start justify-between gap-x-3 gap-y-2">
          <div className="min-w-0">
            <div className="break-words text-sm text-muted-foreground">{payClub.name}</div>
            <div className="break-all font-mono text-lg">{payment.invoiceNo}</div>
          </div>
          <PaymentStatusBadge status={payment.status} label={t(payment.status === "verifying" ? "verifying" : statusLabelKey(payment.status))}
            size="sm"
            className="h-auto min-h-6 whitespace-normal py-0.5"
          />
        </div>
        {/* Two columns while they fit; one under the other with large text. */}
        <dl className="grid grid-cols-[repeat(auto-fit,minmax(min(100%,9rem),1fr))] gap-3 text-base">
          <div className="min-w-0">
            <dt className="text-sm text-muted-foreground">{t("billedTo")}</dt>
            <dd className="break-words font-semibold">{member.name}</dd>
            <dd className="break-all text-sm text-muted-foreground">{member.email}</dd>
          </div>
          <div>
            <dt className="text-sm text-muted-foreground">{t("due")}</dt>
            <dd className={payment.status === "overdue" ? "font-semibold text-danger-foreground" : "font-semibold"}>{fmtDate(payment.dueDate, lang)}</dd>
            {issuedOn && (
              <>
                <dt className="mt-2 text-sm text-muted-foreground">{t("issuedOn")}</dt>
                <dd>{fmtDate(issuedOn, lang)}</dd>
              </>
            )}
          </div>
        </dl>
        <table className="w-full text-base">
          <thead>
            <tr className="border-b text-left text-sm text-muted-foreground">
              <th scope="col" className="py-2 font-medium">
                {t("item")}
              </th>
              <th scope="col" className="py-2 text-right font-medium">
                {t("amount")}
              </th>
            </tr>
          </thead>
          <tbody>
            <tr className="border-b">
              <td className="py-3 pr-2">
                {feeLabel(payment)}
                <span className="block text-sm text-muted-foreground">{t(`freq_${payment.frequency}`)}</span>
              </td>
              <td className="py-3 text-right align-top">
                <Money sen={payment.amount} />
              </td>
            </tr>
            {discount && (
              <tr className="border-b text-sm text-muted-foreground">
                <td className="py-2 pr-2">
                  {t("discount")}: {discount.name}
                </td>
                <td className="py-2 text-right">{discount.type === "fixed" ? `−${rm(discount.value)}` : `−${discount.value}%`}</td>
              </tr>
            )}
          </tbody>
          <tfoot>
            <tr>
              <td className="pt-3 font-semibold">{t("total")}</td>
              <td className="pt-3 text-right">
                <Money sen={payment.amount} className="font-display text-2xl font-bold !whitespace-normal" />
              </td>
            </tr>
          </tfoot>
        </table>
      </section>
      {payment.status === "verifying" && payment.urls.review && (
        <section aria-labelledby="inv-vf" className="flex items-start gap-3 rounded-[20px] bg-warning-soft p-4 text-warning-foreground ring-1 ring-warning/30 print:hidden" data-testid="invoice-verifying">
          <Hourglass className="mt-0.5 h-6 w-6 shrink-0" strokeWidth={1.75} aria-hidden />
          <div className="min-w-0">
            <h2 id="inv-vf" className="text-base font-semibold">
              {t("verifyingTitle")}
            </h2>
            <p className="text-base">{t("verifyingBody")}</p>
            <Link href={payment.urls.review} className="mt-1 inline-flex min-h-11 items-center text-base font-semibold text-primary underline-offset-4 hover:underline">
              {t("yourSlips")}
            </Link>
          </div>
        </section>
      )}
      {payment.urls.review && payment.status !== "verifying" && (
        <Button asChild size="xl" className="h-auto min-h-14 w-full whitespace-normal rounded-full py-3 text-center text-lg print:hidden">
          <Link href={payment.urls.review}>
            <Lock aria-hidden /> {t("reviewPay")} {rm(payment.amount)}
          </Link>
        </Button>
      )}
      <PrintButton />
      <HelpLine email={payClub.email} />
    </MemberShell>
  );
}
