import { Hourglass, Lock, Printer, RefreshCw } from "lucide-react";
import { Head, Link } from "@inertiajs/react";
import { ClubMark, MemberShell } from "@/components/app/MemberShell";
import { CancelledBanner, DueNote, FlashBanner, HelpLine, statusLabelKey, useFeeLabel } from "@/components/app/MemberParts";
import { Money } from "@/components/app/Money";
import { ReceiptActions } from "@/components/app/ReceiptActions";
import { ReceiptCard, receiptClub } from "@/components/app/ReceiptCard";
import { PaymentStatusBadge } from "@/components/app/StatusBadges";
import { Button } from "@/components/ui/button";
import { fmtDate, rm } from "@/lib/format";
import { fill, useLang, useT, useCountText } from "@/lib/i18n";
import type { MemberPaymentRow, MemberReceipt, PaymentCancellationInfo, PaymentContext } from "@/types/member";

interface Props extends PaymentContext {
  payment: MemberPaymentRow;
  receipt: MemberReceipt | null;
  /** The latest cancelled payment of this fee ("Cancel payment"): a banner while unpaid, a note once paid again. */
  cancellation?: PaymentCancellationInfo | null;
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

export default function Invoice({ payment, receipt, cancellation, issuedOn, discount, member, payClub, paymentsUrl, dashboardUrl }: Props) {
  const t = useT();
  const tn = useCountText();
  const { lang } = useLang();
  const feeLabel = useFeeLabel();
  const nav = { home: dashboardUrl, payments: paymentsUrl };

  if (receipt) {
    return (
      <MemberShell tab="payments" navHrefs={nav} clubName={payClub.name} title={t("receipt")} backHref={paymentsUrl}>
        <Head title={t("receipt")} />
        <FlashBanner />
        {receipt.partOf && (
          <p className="mb-3 rounded-2xl border bg-card px-4 py-3 text-base" data-testid="receipt-part-of">
            {tn("receiptPartOf", receipt.partOf.count, { no: receipt.partOf.receiptNo, total: rm(receipt.partOf.amount) })}{" "}
            <Link href={receipt.partOf.url} className="font-semibold text-primary underline-offset-4 hover:underline">
              {t("receiptPartOfLink")}
            </Link>
          </p>
        )}
        <ReceiptCard
          club={receiptClub(receipt.issuer, payClub)}
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
        {/* A fee paid in a set: sharing sends the set's receipt (its total and link), never this fee's amount under the shared number. */}
        <ReceiptActions
          receiptNo={receipt.receiptNo}
          amount={receipt.partOf?.amount ?? receipt.amount}
          paidAt={receipt.paidAt}
          clubName={payClub.name}
          url={receipt.partOf?.url ?? payment.urls.invoice}
        />
        {/* Paid again after a cancelled payment: the earlier number stays on record. */}
        {cancellation && cancellation.receiptNo !== receipt.receiptNo && (
          <p className="text-center text-sm text-muted-foreground" data-testid="cancelled-earlier">
            {fill(t("cancelledEarlier"), { no: cancellation.receiptNo })}
          </p>
        )}
        <HelpLine club={payClub} fee={payment} reference={receipt.receiptNo} />
      </MemberShell>
    );
  }

  return (
    <MemberShell tab="payments" navHrefs={nav} clubName={payClub.name} title={t("invoice")} backHref={paymentsUrl}>
      <Head title={t("invoice")} />
      <FlashBanner />
      {cancellation && payment.status !== "paid" && <CancelledBanner c={cancellation} verifying={payment.status === "verifying"} />}
      <section aria-label={t("invoice")} className="flex flex-col gap-5 rounded-[20px] border bg-card p-5">
        {/* The club as it is now (Club profile): an invoice is not issued yet, so it is never a snapshot. */}
        <div className="flex flex-wrap items-start gap-3 border-b pb-4" data-testid="invoice-issuer">
          {payClub.logoUrl && <ClubMark src={payClub.logoUrl} alt={payClub.name} className="h-12 w-12" />}
          <div className="min-w-0 flex-[1_1_10rem]">
            <div className="break-words font-display text-base font-bold leading-snug">{payClub.name}</div>
            {payClub.registrationNo && (
              <div className="break-words text-xs text-muted-foreground">
                {t("regNo")} {payClub.registrationNo}
              </div>
            )}
            {payClub.address && <div className="whitespace-pre-line break-words text-xs text-muted-foreground">{payClub.address}</div>}
          </div>
        </div>
        <div className="flex flex-wrap items-start justify-between gap-x-3 gap-y-2">
          <div className="min-w-0">
            <div className="text-sm text-muted-foreground">{t("invoice")}</div>
            <h2 className="break-all font-mono text-lg font-normal">{payment.invoiceNo}</h2>
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
            <dd className="font-semibold">
              {fmtDate(payment.dueDate, lang)}
              <DueNote p={payment} />
            </dd>
            {issuedOn && (
              <>
                <dt className="mt-2 text-sm text-muted-foreground">{t("issuedOn")}</dt>
                <dd>{fmtDate(issuedOn, lang)}</dd>
              </>
            )}
          </div>
        </dl>
        {/* Item rows wrap (amount under the item) with large text instead of running off a narrow screen. */}
        <div className="text-base" role="table" aria-label={t("invoice")}>
          <div role="rowgroup">
            <div role="row" className="flex flex-wrap justify-between gap-x-3 border-b py-2 text-sm text-muted-foreground">
              <span role="columnheader" className="font-medium">
                {t("item")}
              </span>
              <span role="columnheader" className="text-right font-medium">
                {t("amount")}
              </span>
            </div>
          </div>
          <div role="rowgroup">
            <div role="row" className="flex flex-wrap items-start justify-between gap-x-3 gap-y-1 border-b py-3">
              <span role="cell" className="min-w-0 flex-[1_1_10rem] break-words">
                {feeLabel(payment)}
                <span className="block text-sm text-muted-foreground">{t(`freq_${payment.frequency}`)}</span>
              </span>
              <span role="cell" className="ml-auto text-right">
                <Money sen={payment.amount} />
              </span>
            </div>
            {discount && (
              <div role="row" className="flex flex-wrap justify-between gap-x-3 gap-y-1 border-b py-2 text-sm text-muted-foreground">
                <span role="cell" className="min-w-0 flex-[1_1_10rem] break-words">
                  {t("discount")}: {discount.name}
                </span>
                <span role="cell" className="ml-auto text-right">
                  {discount.type === "fixed" ? `−${rm(discount.value)}` : `−${discount.value}%`}
                </span>
              </div>
            )}
            <div role="row" className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1 pt-3">
              <span role="rowheader" className="font-semibold">
                {t("total")}
              </span>
              <span role="cell" className="ml-auto min-w-0 text-right">
                <Money sen={payment.amount} className="font-display text-2xl font-bold !whitespace-normal" />
              </span>
            </div>
          </div>
        </div>
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
      {payment.status === "processing" && payment.urls.billStatus && (
        <section aria-labelledby="inv-pr" className="flex flex-col gap-3 rounded-[20px] bg-warning-soft p-4 text-warning-foreground ring-1 ring-warning/30 print:hidden" data-testid="invoice-processing">
          <div className="flex items-start gap-3">
            <RefreshCw className="mt-0.5 h-6 w-6 shrink-0" strokeWidth={1.75} aria-hidden />
            <div className="min-w-0">
              <h2 id="inv-pr" className="text-base font-semibold">
                {t("processingWarnTitle")}
              </h2>
              <p className="text-base">{t("processingWarnBody")}</p>
            </div>
          </div>
          <Button asChild size="xl" className="h-auto min-h-14 w-full whitespace-normal rounded-full py-3 text-center text-lg">
            <Link href={payment.urls.billStatus}>{t("checkPaymentStatus")}</Link>
          </Button>
        </section>
      )}
      {payment.urls.review && payment.status === "processing" && (
        <Button asChild variant="outline" size="xl" className="h-auto min-h-14 w-full whitespace-normal rounded-full py-3 text-center text-lg print:hidden">
          <Link href={payment.urls.review}>{t("payAgain")}</Link>
        </Button>
      )}
      {payment.urls.review && payment.status !== "verifying" && payment.status !== "processing" && (
        <Button asChild size="xl" className="h-auto min-h-14 w-full whitespace-normal rounded-full py-3 text-center text-lg print:hidden">
          <Link href={payment.urls.review}>
            <Lock aria-hidden /> {fill(t("reviewPayAmt"), { x: rm(payment.amount) })}
          </Link>
        </Button>
      )}
      <PrintButton />
      <HelpLine club={payClub} fee={payment} reference={payment.invoiceNo} />
    </MemberShell>
  );
}
