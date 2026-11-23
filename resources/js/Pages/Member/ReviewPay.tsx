import { useState, type FormEvent } from "react";
import { AlertTriangle, Check, Copy, FileText, Hourglass, ImageIcon, Landmark, Loader2, Lock, RefreshCw, Send, ShieldCheck } from "lucide-react";
import { Head, Link, router, useForm } from "@inertiajs/react";
import { MemberShell } from "@/components/app/MemberShell";
import { DueNote, FlashBanner, HelpLine, useFeeLabel, useServerText } from "@/components/app/MemberParts";
import { Money } from "@/components/app/Money";
import { FileUploader, type UploadItem } from "@/components/app/FileUploader";
import { FpxMark } from "@/components/app/Visuals";
import { Alert } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Input, Label } from "@/components/ui/input";
import { fmtDate, rm } from "@/lib/format";
import { fill, localeTag, useLang, useT, useCountText } from "@/lib/i18n";
import { cn } from "@/lib/utils";
import { useStickyFit } from "@/lib/useStickyFit";
import type { MemberPaymentRow, PaymentContext, PaymentGroup, TransferOption, TransferVerification } from "@/types/member";

interface Props extends PaymentContext {
  /** The fee being paid; null for "Bayar semua", which sends `group` instead. */
  payment: MemberPaymentRow | null;
  /** "Bayar semua": every open fee in the club, paid with one bill or one slip. */
  group?: PaymentGroup | null;
  gateway: { name: string; label: string; available: boolean };
  /** feeSen is the gateway charge the member pays on top (0 when the club absorbs it). */
  charges: { payer: "club" | "member"; feeSen: number; totalSen: number };
  hasOpenBill: boolean;
  /** Where to check the payment already started (single fee's pay result, or the group's result page). */
  openBillUrl?: string | null;
  /** An FPX payment for this fee (or set) is in progress (DisplayStatus "processing"): checking it is the main action. */
  processing?: boolean;
  /** "Bayar semua": a fee of the set is being paid through another bill, so paying the set again is refused. */
  payAgainBlocked?: boolean;
  payUrl: string;
  /** Bank / DuitNow transfer details; null when the club doesn't take transfers. */
  transfer: TransferOption | null;
  /** A slip is waiting for the treasurer. */
  verification: TransferVerification | null;
  /** The last slip was rejected. */
  rejection: { reason: string; at: string | null } | null;
}

type Method = "fpx" | "transfer";

/** Copy one value; if the clipboard is blocked the value stays on screen to type by hand. */
function CopyButton({ value, what }: { value: string; what: string }) {
  const t = useT();
  const [done, setDone] = useState(false);
  const copy = async () => {
    try {
      await navigator.clipboard.writeText(value);
      setDone(true);
      window.setTimeout(() => setDone(false), 1600);
    } catch {
      setDone(false);
    }
  };
  return (
    <Button type="button" variant="outline" size="sm" className="h-10 shrink-0 rounded-full px-3" onClick={copy} aria-label={fill(t("copyLabel"), { x: what })}>
      {done ? <Check aria-hidden /> : <Copy aria-hidden />}
      <span aria-live="polite">{done ? t("copied") : t("copy")}</span>
    </Button>
  );
}

/** copyValue: what goes to the clipboard when it differs from what is shown (e.g. digits only). */
function DetailRow({ label, value, copy, copyValue, mono = false }: { label: string; value: string; copy?: boolean; copyValue?: string; mono?: boolean }) {
  // One div per dt/dd pair, straight under the <dl>; the copy button sits inside the dd it copies.
  return (
    <div className="flex flex-col gap-0.5 px-4 py-3">
      <dt className="text-sm text-muted-foreground">{label}</dt>
      <dd className="flex flex-wrap items-center justify-between gap-x-3 gap-y-2">
        <span className={cn("min-w-0 flex-[1_1_8rem] break-words text-base font-semibold", mono && "font-mono tracking-wide")}>{value}</span>
        {copy && <CopyButton value={copyValue ?? value} what={label} />}
      </dd>
    </div>
  );
}

function MethodOption({
  value,
  checked,
  onChange,
  title,
  sub,
  aside,
  disabled = false,
}: {
  value: Method;
  checked: boolean;
  onChange: (m: Method) => void;
  title: string;
  /** With disabled, the reason it can't be chosen. */
  sub: string;
  aside: React.ReactNode;
  disabled?: boolean;
}) {
  return (
    <label
      className={cn(
        "flex min-h-[64px] flex-wrap items-center gap-x-3 gap-y-1 rounded-2xl border-2 px-4 py-3 transition-colors focus-within:ring-2 focus-within:ring-ring",
        disabled ? "cursor-not-allowed border-dashed border-border bg-muted/60" : "cursor-pointer",
        !disabled && (checked ? "border-primary bg-accent/60" : "border-border hover:bg-muted/60"),
      )}
      data-testid={`method-${value}`}
    >
      <input
        type="radio"
        name="method"
        value={value}
        checked={checked}
        disabled={disabled}
        onChange={() => onChange(value)}
        aria-describedby={`method-${value}-sub`}
        className="h-5 w-5 shrink-0 accent-[rgb(var(--primary))]"
      />
      {/* With large text on a narrow phone, the charge note drops under the title instead of squeezing it. */}
      <span className="min-w-0 flex-[1_1_9rem] break-words">
        <span className={cn("block text-base font-semibold leading-snug", disabled && "text-muted-foreground")}>{title}</span>
        <span id={`method-${value}-sub`} className={cn("block text-sm", disabled ? "font-medium text-foreground" : "text-muted-foreground")}>
          {sub}
        </span>
      </span>
      {!disabled && aside && <span className="ml-auto max-w-full text-right text-sm font-semibold">{aside}</span>}
    </label>
  );
}

/** Bank details, the reference to type, and the slip form. */
function TransferForm({ transfer, amount, group }: { transfer: TransferOption; amount: number; group?: PaymentGroup | null }) {
  const t = useT();
  const tn = useCountText();
  const { lang } = useLang();
  const text = useServerText();
  const [items, setItems] = useState<UploadItem[]>([]);
  const [tooMany, setTooMany] = useState(false);
  const form = useForm<{
    files: File[];
    transfer_date: string;
    transfer_ref: string;
  }>({
    files: [],
    transfer_date: transfer.today,
    transfer_ref: "",
  });

  const setFiles = (next: UploadItem[]) => {
    setTooMany(next.length > transfer.maxFiles);
    const kept = next.slice(0, transfer.maxFiles);
    setItems(kept);
    form.setData(
      "files",
      kept.map((i) => i.file).filter((f): f is File => !!f),
    );
  };

  const submit = (e: FormEvent) => {
    e.preventDefault();
    if (form.processing || items.length === 0) return;
    // "Bayar semua": the exact set and total the member saw, so the server can refuse a stale page.
    if (group) form.transform((d) => ({ ...d, payment_ids: group.paymentIds, total_sen: group.sumSen }));
    form.post(transfer.submitUrl, {
      forceFormData: true,
      preserveScroll: true,
      // Take the member to the first field that needs fixing (after React has marked it aria-invalid),
      // or to the error box when the problem belongs to no field.
      onError: () =>
        window.requestAnimationFrame(() => {
          const target =
            document.querySelector<HTMLElement>('#transfer-form [aria-invalid="true"]') ?? document.querySelector<HTMLElement>('[data-testid="transfer-error"]');
          target?.focus();
        }),
    });
  };

  const fileError = Object.entries(form.errors).find(([k]) => k === "files" || k.startsWith("files."))?.[1];
  const err = (k: "transfer_date" | "transfer_ref" | "method" | "status") => (form.errors as Record<string, string | undefined>)[k];

  return (
    // "contents": the steps sit straight in the page column, so the sticky send bar stays in reach across the whole page.
    <form id="transfer-form" onSubmit={submit} className="contents" noValidate>
      <section aria-labelledby="tx-step1" className="flex flex-col gap-3 rounded-[20px] border bg-card p-4">
        <h2 id="tx-step1" className="flex items-center gap-2 text-base font-semibold">
          <span className="flex h-7 w-7 items-center justify-center rounded-full bg-primary text-sm font-bold text-primary-foreground" aria-hidden>
            1
          </span>
          {fill(t("transferStep1"), { x: rm(amount) })}
        </h2>
        {group && <p className="text-sm text-muted-foreground">{t("payAllTransferNote")}</p>}
        <dl className="-mx-4 flex flex-col divide-y border-y">
          <DetailRow label={t("bank")} value={transfer.bankName} />
          <DetailRow label={t("accountName")} value={transfer.accountName} />
          {transfer.accountNo && <DetailRow label={t("accountNo")} value={transfer.accountNo} copyValue={transfer.accountNo.replace(/[\s-]/g, "")} copy mono />}
          {transfer.duitnowId && <DetailRow label={t("duitnowId")} value={transfer.duitnowId} copy mono />}
        </dl>
        <div className="flex flex-col gap-2 rounded-2xl bg-warning-soft p-4 text-warning-foreground ring-1 ring-warning/30">
          <div className="flex flex-wrap items-center justify-between gap-x-3 gap-y-2">
            <div className="min-w-0 flex-[1_1_8rem]">
              <div className="text-sm font-semibold">{t("transferRefLbl")}</div>
              <div className="font-mono text-xl font-bold tracking-wide" data-testid="transfer-ref">
                {transfer.reference}
              </div>
            </div>
            <CopyButton value={transfer.reference} what={t("reference")} />
          </div>
          <p className="text-sm">{t("refHint")}</p>
        </div>
      </section>

      <section aria-labelledby="tx-step2" className="flex flex-col gap-4 rounded-[20px] border bg-card p-4">
        <h2 id="tx-step2" className="flex items-center gap-2 text-base font-semibold">
          <span className="flex h-7 w-7 items-center justify-center rounded-full bg-primary text-sm font-bold text-primary-foreground" aria-hidden>
            2
          </span>
          {t("transferStep2")}
        </h2>
        <FileUploader
          files={items}
          onAdd={(added) => setFiles([...items, ...added])}
          onRemove={(id) => setFiles(items.filter((i) => i.id !== id))}
          showKind={false}
          comfortable
          maxFiles={transfer.maxFiles}
          maxKb={transfer.maxMb * 1024}
          error={tooMany ? tn("tooManyFiles", transfer.maxFiles) : fileError ? text(fileError) : undefined}
          text={{
            dropPrefix: t("dropPrefix"),
            browse: t("browse"),
            limits: tn("slipLimits", transfer.maxFiles, { mb: transfer.maxMb }),
            takePhoto: t("takePhotoSlip"),
            cameraHint: t("cameraHint"),
            chooseFile: t("chooseFile"),
            remove: (name) => fill(t("removeFile"), { f: name }),
            tooLarge: (names, mb) => fill(t("fileTooLarge"), { f: names, mb }),
          }}
        />
        <div className="grid grid-cols-1 gap-4 min-[26.25em]:grid-cols-2">
          <div className="flex min-w-0 flex-col gap-1.5">
            <Label htmlFor="transfer_date" className="text-base">
              {t("transferDateLbl")}
            </Label>
            <Input
              id="transfer_date"
              type="date"
              className="h-12 text-base"
              value={form.data.transfer_date}
              min={transfer.minDate}
              max={transfer.today}
              required
              invalid={!!err("transfer_date")}
              lang={localeTag(lang)}
              aria-describedby="transfer_date_hint"
              onChange={(e) => form.setData("transfer_date", e.target.value)}
            />
            {err("transfer_date") ? (
              <p id="transfer_date_hint" className="text-sm font-medium text-danger-foreground">
                {text(err("transfer_date")!)}
              </p>
            ) : (
              <p id="transfer_date_hint" className="text-sm text-muted-foreground">
                {/^\d{4}-\d{2}-\d{2}$/.test(form.data.transfer_date) ? fmtDate(form.data.transfer_date, lang) : "\u00a0"}
              </p>
            )}
          </div>
          <div className="flex min-w-0 flex-col gap-1.5">
            <Label htmlFor="transfer_ref" className="text-base">
              {t("bankRefLbl")}
            </Label>
            <Input
              id="transfer_ref"
              className="h-12 text-base"
              value={form.data.transfer_ref}
              maxLength={60}
              autoComplete="off"
              invalid={!!err("transfer_ref")}
              aria-describedby="transfer_ref_hint"
              onChange={(e) => form.setData("transfer_ref", e.target.value)}
            />
            <p id="transfer_ref_hint" className={err("transfer_ref") ? "text-sm font-medium text-danger-foreground" : "text-sm text-muted-foreground"}>
              {err("transfer_ref") ? text(err("transfer_ref")!) : t("bankRefHint")}
            </p>
          </div>
        </div>
      </section>

      {/* Errors that belong to no field: transfers switched off, or the fee changed meanwhile. */}
      {(err("method") || err("status")) && (
        <Alert variant="danger" className="text-base" data-testid="transfer-error" tabIndex={-1}>
          <AlertTriangle aria-hidden />
          <p>{text((err("method") ?? err("status"))!)}</p>
        </Alert>
      )}

      <p className="px-1 text-sm text-muted-foreground">{t("slipNote")}</p>

      {/* The send button sits in the sticky bar at the bottom; it submits this form by id. */}
      <StickyBar>
        {/* Why the send button is off, right above it inside the bar, so it is seen wherever the bar is. */}
        {items.length === 0 && (
          <p id="slip-needed" className="px-1 text-center text-sm font-medium text-foreground" data-testid="slip-needed">
            {t("slipNeeded")}
          </p>
        )}
        <Button
          type="submit"
          form="transfer-form"
          size="xl"
          className="h-auto min-h-14 w-full whitespace-normal rounded-full py-3 text-lg"
          disabled={form.processing || items.length === 0}
          aria-describedby={items.length === 0 ? "slip-needed" : undefined}
          aria-live="polite"
        >
          {form.processing ? (
            <>
              <Loader2 className="animate-spin" aria-hidden /> {t("sendingSlip")}
            </>
          ) : (
            <>
              <Send aria-hidden /> {t("submitSlip")}
            </>
          )}
        </Button>
      </StickyBar>
    </form>
  );
}

/** Slip already sent: what was sent, and no pay buttons. */
function VerifyingCard({ v }: { v: TransferVerification }) {
  const t = useT();
  const { lang } = useLang();
  return (
    <section aria-labelledby="vf-h" className="flex flex-col gap-4 rounded-2xl bg-warning-soft p-4 text-warning-foreground ring-1 ring-warning/30" data-testid="verifying">
      <div className="flex items-start gap-3">
        <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-card text-warning-foreground ring-1 ring-warning/30">
          <Hourglass className="h-6 w-6" strokeWidth={1.75} aria-hidden />
        </span>
        <div className="min-w-0">
          <h2 id="vf-h" className="font-display text-xl font-bold leading-tight tracking-tight">
            {t("verifyingTitle")}
          </h2>
          <p className="mt-1 text-base">{t("verifyingBody")}</p>
        </div>
      </div>
      <dl className="grid grid-cols-2 gap-3 rounded-xl bg-card/70 p-3 text-foreground">
        {v.submittedAt && (
          <div className="min-w-0">
            <dt className="text-sm text-muted-foreground">{t("slipSentOn")}</dt>
            <dd className="text-base font-semibold">{fmtDate(v.submittedAt, lang)}</dd>
          </div>
        )}
        {v.transferDate && (
          <div className="min-w-0">
            <dt className="text-sm text-muted-foreground">{t("transferDateLbl")}</dt>
            <dd className="text-base font-semibold">{fmtDate(v.transferDate, lang)}</dd>
          </div>
        )}
        {v.transferRef && (
          <div className="col-span-2 min-w-0">
            <dt className="text-sm text-muted-foreground">{t("reference")}</dt>
            <dd className="break-words font-mono text-base">{v.transferRef}</dd>
          </div>
        )}
      </dl>
      {v.slips.length > 0 && (
        <div className="flex flex-col gap-2">
          <h3 className="text-sm font-semibold">{t("yourSlips")}</h3>
          <ul className="flex flex-col gap-2">
            {v.slips.map((s) => (
              <li key={s.id}>
                <a
                  href={s.url}
                  target="_blank"
                  rel="noopener"
                  className="flex min-h-11 items-center gap-2 rounded-xl bg-card px-3 py-2 text-base font-semibold text-foreground ring-1 ring-border hover:bg-muted"
                  aria-label={fill(t("openFile"), {
                    f: s.name,
                  })}
                >
                  {s.isPdf ? <FileText className="h-5 w-5 shrink-0 text-danger" aria-hidden /> : <ImageIcon className="h-5 w-5 shrink-0 text-info" aria-hidden />}
                  <span className="min-w-0 break-all">{s.name}</span>
                </a>
              </li>
            ))}
          </ul>
        </div>
      )}
    </section>
  );
}

/**
 * The page's one action, kept in reach at the bottom of the screen: only the button, and only while it is
 * small next to the screen (useStickyFit); with large text or a short screen it stays in the page flow.
 * Sticky, not fixed, so it never covers the last lines.
 */
function StickyBar({ children }: { children: React.ReactNode }) {
  const [ref, pinned] = useStickyFit<HTMLDivElement>("bottom", { maxFraction: 0.18, minViewport: 560 });
  return (
    <div
      ref={ref}
      // Same box pinned or not (border, padding, safe-area inset): only position and background change, so its
      // measured height never moves across the threshold and it cannot flip between the two states.
      className={cn(
        "bleed-x z-10 flex flex-col gap-2 border-t border-transparent pb-[calc(0.75rem+env(safe-area-inset-bottom,0px))] pt-3",
        pinned && "sticky bottom-0 border-border bg-background/95 backdrop-blur supports-[backdrop-filter]:bg-background/85",
      )}
      data-testid="pay-bar"
      data-pinned={pinned}
    >
      {children}
    </div>
  );
}

/** "Bayar semua": each fee on its own line, oldest first, then the fees total. */
function GroupLines({ group }: { group: PaymentGroup }) {
  const t = useT();
  const tn = useCountText();
  const { lang } = useLang();
  const feeLabel = useFeeLabel();
  return (
    <div className="px-4 py-3" data-testid="group-fees">
      <dt className="text-sm text-muted-foreground">{tn("feesN", group.fees.length)}</dt>
      <dd>
        <ul className="mt-1 flex flex-col divide-y divide-dashed">
          {group.fees.map((f) => (
            <li key={f.id} className="flex flex-wrap justify-between gap-x-3 gap-y-1 py-2 text-base">
              <span className="min-w-0 flex-[1_1_10rem] break-words">
                {feeLabel(f)}
                <span className="block text-sm text-muted-foreground">
                  {f.invoiceNo} · {fill(t("dueOn"), { d: fmtDate(f.dueDate, lang) })}
                  <DueNote p={f} />
                </span>
              </span>
              <Money sen={f.amount} className="font-semibold" />
            </li>
          ))}
        </ul>
        <div className="flex flex-wrap justify-between gap-x-3 border-t pt-2 text-base">
          <span className="text-muted-foreground">{t("subtotal")}</span>
          <Money sen={group.sumSen} className="font-semibold" />
        </div>
      </dd>
    </div>
  );
}

/** Review & pay: a confirm step that is clearly not a receipt. The amount is in the summary and on the one pay button. */
export default function ReviewPay({ payment, group, gateway, charges, hasOpenBill, openBillUrl, processing = false, payAgainBlocked = false, payUrl, transfer, verification, rejection, member, payClub, paymentsUrl, dashboardUrl }: Props) {
  const t = useT();
  const { lang } = useLang();
  const feeLabel = useFeeLabel();
  const [sent, setSent] = useState(false);
  // FPX first when it works; transfer first when FPX is unavailable or the last slip was rejected.
  const [method, setMethod] = useState<Method>(transfer && (!gateway.available || rejection) ? "transfer" : "fpx");
  const gw = { gw: gateway.label };
  const isTransfer = !!transfer && method === "transfer";
  // FPX costs the member a charge: only then is "no charge" worth saying on the transfer option.
  const fpxCharge = charges.payer === "member" && charges.feeSen > 0;
  const memberCharge = !isTransfer && fpxCharge;
  const feesSen = group ? group.sumSen : (payment?.amount ?? 0);
  const total = verification || isTransfer ? feesSen : charges.totalSen;
  // A slip is with the treasurer: this page is now about where the payment stands, not paying.
  const title = verification ? t("paymentStatus") : group ? t("reviewPayAll") : t("reviewPay");
  // An FPX payment is still with the bank: the main button checks it; paying again is secondary.
  const checkFirst = processing && !!openBillUrl;

  const pay = () => {
    if (sent || !gateway.available) return;
    setSent(true);
    // The server answers with an external redirect (X-Inertia-Location) to the gateway,
    // or back here with a flash error, in which case the button is enabled again.
    // "Bayar semua" sends the exact set and total shown, so a stale page is refused.
    const data = group ? { payment_ids: group.paymentIds, total_sen: group.sumSen } : {};
    router.post(payUrl, data, { onError: () => setSent(false), onSuccess: () => setSent(false) });
  };

  return (
    <MemberShell tab="payments" navHrefs={{ home: dashboardUrl, payments: paymentsUrl }} clubName={payClub.name} title={title} backHref={payment ? payment.urls.invoice : dashboardUrl} hideNav>
      <Head title={title} />

      <FlashBanner />

      <section aria-label={group ? t("feesInSet") : t("paidFor")} className="flex flex-col">
        <dl className="flex flex-col divide-y rounded-[20px] border bg-card">
          {group ? (
            <GroupLines group={group} />
          ) : (
            payment && (
              <div className="px-4 py-3">
                <dt className="text-sm text-muted-foreground">{t("paidFor")}</dt>
                <dd className="flex flex-wrap justify-between gap-x-3 gap-y-1 text-base">
                  <span className="min-w-0 flex-[1_1_10rem] break-words">
                    {feeLabel(payment)}
                    <span className="block text-sm text-muted-foreground">
                      {t(`freq_${payment.frequency}`)} · {fill(t("dueOn"), { d: fmtDate(payment.dueDate, lang) })}
                      <DueNote p={payment} />
                    </span>
                  </span>
                  <Money sen={payment.amount} className="font-semibold" />
                </dd>
              </div>
            )
          )}
          <div className="px-4 py-3">
            <dt className="text-sm text-muted-foreground">{t("member")}</dt>
            <dd className="break-words text-base font-semibold">{member.name}</dd>
          </div>
          <div className="px-4 py-3">
            <dt className="text-sm text-muted-foreground">{t("payee")}</dt>
            <dd className="break-words text-base font-semibold">{payClub.name}</dd>
          </div>
          {memberCharge && (
            <div className="px-4 py-3" data-testid="fee-line">
              <dt className="text-sm text-muted-foreground">{t("gatewayCharge")}</dt>
              <dd className="flex flex-wrap justify-between gap-x-3 gap-y-1 text-base">
                <span className="min-w-0 flex-[1_1_10rem] text-sm text-muted-foreground">{group ? t("payAllOneCharge") : t("chargeByMember")}</span>
                <Money sen={charges.feeSen} className="font-semibold" />
              </dd>
            </div>
          )}
          <div className="flex flex-wrap items-center justify-between gap-x-3 gap-y-1 rounded-b-[20px] bg-muted/60 px-4 py-3">
            <dt className="text-base font-semibold">{t("total")}</dt>
            <dd data-testid="review-total">
              <Money sen={total} className="font-display text-2xl font-bold" />
            </dd>
          </div>
        </dl>
        {payment && (
          <Link href={payment.urls.invoice} className="inline-flex min-h-11 items-center self-start px-1 text-base font-semibold text-primary underline-offset-4 hover:underline">
            {t("invoice")} {payment.invoiceNo}
          </Link>
        )}
      </section>

      {verification ? (
        <VerifyingCard v={verification} />
      ) : (
        <>
          {rejection && (
            <Alert variant="warning" className="text-base" data-testid="rejection">
              <AlertTriangle aria-hidden />
              <div>
                <p className="font-semibold">{t("rejectedTitle")}</p>
                <p>
                  {t("reason")}: {rejection.reason}
                </p>
                <p className="mt-1">{t("rejectedBody")}</p>
                {rejection.at && <p className="mt-1 text-sm">{fmtDate(rejection.at, lang)}</p>}
                {(payClub.whatsapp || payClub.email) && (
                  <HelpLine club={payClub} fee={payment} reference={transfer?.reference ?? payment?.invoiceNo} className="mt-1 min-h-11 justify-start text-left" />
                )}
              </div>
            </Alert>
          )}

          {/* A payment was already started: said before the member picks how to pay (again). */}
          {hasOpenBill && (
            <Alert variant="warning" className="text-base" data-testid={isTransfer ? "transfer-open-bill" : "open-bill-warning"} id="open-bill-warning">
              {processing ? <RefreshCw aria-hidden /> : <AlertTriangle aria-hidden />}
              <div>
                {processing && <p className="font-semibold">{t("processingWarnTitle")}</p>}
                <p>{processing ? t("processingWarnBody") : isTransfer ? t("openBillTransferWarn") : t("openBillWarn")}</p>
                {/* No longer counted as in progress: if it did go through, the treasurer can confirm it. */}
                {!processing && <p className="mt-1" data-testid="stale-bill-hint">{t("staleBillHint")}</p>}
                {openBillUrl && !processing && (
                  <Link href={openBillUrl} className="mt-1 inline-flex min-h-11 items-center font-semibold underline underline-offset-4" data-testid="open-bill-status">
                    {t("checkEarlierPayment")}
                  </Link>
                )}
              </div>
            </Alert>
          )}

          {transfer && (
            <fieldset className="flex flex-col gap-2">
              <legend className="mb-2 px-1 text-base font-semibold">{t("chooseMethod")}</legend>
              <MethodOption
                value="fpx"
                checked={method === "fpx"}
                onChange={setMethod}
                title={t("methodFpx")}
                sub={gateway.available ? fill(t("methodFpxSub"), gw) : t("fpxUnavailableShort")}
                aside={fpxCharge ? fill(t("plusChargeShort"), { x: rm(charges.feeSen) }) : null}
                disabled={!gateway.available}
              />
              <MethodOption
                value="transfer"
                checked={method === "transfer"}
                onChange={setMethod}
                title={t("methodTransfer")}
                sub={t("methodTransferSub")}
                aside={fpxCharge ? t("noCharge") : <Landmark className="h-5 w-5 text-primary" strokeWidth={1.75} aria-hidden />}
              />
            </fieldset>
          )}

          {isTransfer && transfer ? (
            <TransferForm transfer={transfer} amount={feesSen} group={group} />
          ) : (
            <>
              <div className="flex flex-col gap-3 rounded-[20px] border bg-card p-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
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

              {!gateway.available && (
                <Alert variant="danger" className="text-base" id="gateway-unavailable">
                  <AlertTriangle aria-hidden />
                  <p>{t("gatewayUnavailable")}</p>
                </Alert>
              )}

              {checkFirst && openBillUrl ? (
                <>
                  {/* Paying again stays possible, but as the second choice, with the warning attached. */}
                  {!payAgainBlocked && (
                  <div className="flex flex-col gap-2 rounded-[20px] border bg-card p-4" data-testid="pay-again-box">
                    <p id="pay-again-warn" className="text-base">
                      {t("payAgainWarn")}
                    </p>
                    <Button
                      type="button"
                      variant="outline"
                      size="xl"
                      className="h-auto min-h-14 w-full whitespace-normal rounded-full py-3 text-lg"
                      onClick={pay}
                      disabled={sent || !gateway.available}
                      aria-describedby={!gateway.available ? "gateway-unavailable pay-again-warn" : "pay-again-warn open-bill-warning"}
                      aria-live="polite"
                      data-testid="pay-again"
                    >
                      {sent ? (
                        <>
                          <Loader2 className="animate-spin" aria-hidden /> {fill(t("redirectingGw"), gw)}
                        </>
                      ) : (
                        <>
                          {fill(t("payAgainAmt"), { x: rm(total) })}
                        </>
                      )}
                    </Button>
                  </div>
                  )}
                  <StickyBar>
                    <Button asChild size="xl" className="h-auto min-h-14 w-full whitespace-normal rounded-full py-3 text-lg">
                      <Link href={openBillUrl} data-testid="check-status">
                        <RefreshCw aria-hidden /> {t("checkPaymentStatus")}
                      </Link>
                    </Button>
                  </StickyBar>
                </>
              ) : (
              <StickyBar>
                <Button
                  size="xl"
                  className="h-auto min-h-14 w-full whitespace-normal rounded-full py-3 text-lg"
                  onClick={pay}
                  disabled={sent || !gateway.available}
                  aria-describedby={!gateway.available ? "gateway-unavailable" : undefined}
                  aria-live="polite"
                >
                  {sent ? (
                    <>
                      <Loader2 className="animate-spin" aria-hidden /> {fill(t("redirectingGw"), gw)}
                    </>
                  ) : (
                    <>
                      <Lock aria-hidden /> {fill(t("payNowAmt"), { x: rm(total) })}
                    </>
                  )}
                </Button>
              </StickyBar>
              )}
            </>
          )}
        </>
      )}

      <HelpLine club={payClub} fee={payment} reference={transfer?.reference ?? payment?.invoiceNo} />
    </MemberShell>
  );
}
