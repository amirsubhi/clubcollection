import { useState, type FormEvent } from "react";
import { AlertTriangle, Check, Copy, FileText, Hourglass, ImageIcon, Landmark, Loader2, Lock, Send, ShieldCheck } from "lucide-react";
import { Head, Link, router, useForm } from "@inertiajs/react";
import { MemberShell } from "@/components/app/MemberShell";
import { FlashBanner, HelpLine, useFeeLabel, useServerText } from "@/components/app/MemberParts";
import { Money } from "@/components/app/Money";
import { FileUploader, type UploadItem } from "@/components/app/FileUploader";
import { FpxMark } from "@/components/app/Visuals";
import { Alert } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Input, Label } from "@/components/ui/input";
import { fmtDate, rm } from "@/lib/format";
import { fill, useLang, useT } from "@/lib/i18n";
import { cn } from "@/lib/utils";
import type { MemberPaymentRow, PaymentContext, TransferOption, TransferVerification } from "@/types/member";

interface Props extends PaymentContext {
  payment: MemberPaymentRow;
  gateway: { name: string; label: string; available: boolean };
  /** feeSen is the gateway charge the member pays on top (0 when the club absorbs it). */
  charges: { payer: "club" | "member"; feeSen: number; totalSen: number };
  hasOpenBill: boolean;
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
  return (
    <div className="flex items-center justify-between gap-3 px-4 py-3">
      <div className="min-w-0">
        <dt className="text-sm text-muted-foreground">{label}</dt>
        <dd className={cn("break-words text-base font-semibold", mono && "font-mono tracking-wide")}>{value}</dd>
      </div>
      {copy && <CopyButton value={copyValue ?? value} what={label} />}
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
}: {
  value: Method;
  checked: boolean;
  onChange: (m: Method) => void;
  title: string;
  sub: string;
  aside: React.ReactNode;
}) {
  return (
    <label
      className={cn(
        "flex min-h-[64px] cursor-pointer items-center gap-3 rounded-2xl border-2 px-4 py-3 transition-colors focus-within:ring-2 focus-within:ring-ring",
        checked ? "border-primary bg-accent/60" : "border-border hover:bg-muted/60",
      )}
    >
      <input type="radio" name="method" value={value} checked={checked} onChange={() => onChange(value)} className="h-5 w-5 shrink-0 accent-[rgb(var(--primary))]" />
      <span className="min-w-0 flex-1">
        <span className="block text-base font-semibold leading-snug">{title}</span>
        <span className="block text-sm text-muted-foreground">{sub}</span>
      </span>
      <span className="shrink-0 text-sm font-semibold">{aside}</span>
    </label>
  );
}

/** Bank details, the reference to type, and the slip form. */
function TransferForm({ transfer, amount }: { transfer: TransferOption; amount: number }) {
  const t = useT();
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
    form.post(transfer.submitUrl, {
      forceFormData: true,
      preserveScroll: true,
    });
  };

  const fileError = Object.entries(form.errors).find(([k]) => k === "files" || k.startsWith("files."))?.[1];
  const err = (k: "transfer_date" | "transfer_ref" | "method" | "status") => (form.errors as Record<string, string | undefined>)[k];

  return (
    <form onSubmit={submit} className="flex flex-col gap-5" noValidate>
      <section aria-labelledby="tx-step1" className="flex flex-col gap-3">
        <h2 id="tx-step1" className="flex items-center gap-2 text-base font-semibold">
          <span className="flex h-7 w-7 items-center justify-center rounded-full bg-primary text-sm font-bold text-primary-foreground" aria-hidden>
            1
          </span>
          {fill(t("transferStep1"), { x: rm(amount) })}
        </h2>
        <dl className="flex flex-col divide-y rounded-2xl border">
          <DetailRow label={t("bank")} value={transfer.bankName} />
          <DetailRow label={t("accountName")} value={transfer.accountName} />
          {transfer.accountNo && <DetailRow label={t("accountNo")} value={transfer.accountNo} copyValue={transfer.accountNo.replace(/[\s-]/g, "")} copy mono />}
          {transfer.duitnowId && <DetailRow label={t("duitnowId")} value={transfer.duitnowId} copy mono />}
        </dl>
        <div className="flex flex-col gap-2 rounded-2xl bg-warning-soft p-4 text-warning-foreground ring-1 ring-warning/30">
          <div className="flex items-center justify-between gap-3">
            <div className="min-w-0">
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

      <section aria-labelledby="tx-step2" className="flex flex-col gap-4">
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
          error={tooMany ? fill(t("tooManyFiles"), { n: transfer.maxFiles }) : fileError ? text(fileError) : undefined}
          text={{
            dropPrefix: t("dropPrefix"),
            browse: t("browse"),
            limits: fill(t("slipLimits"), {
              n: transfer.maxFiles,
              mb: transfer.maxMb,
            }),
            takePhoto: t("takePhoto"),
            cameraHint: t("cameraHint"),
            remove: (name) => fill(t("removeFile"), { f: name }),
          }}
        />
        <div className="grid grid-cols-1 gap-4 min-[420px]:grid-cols-2">
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
              lang={lang === "bm" ? "ms-MY" : "en-MY"}
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

      <div className="flex flex-col gap-2">
        <Button type="submit" size="xl" className="w-full rounded-full text-lg" disabled={form.processing || items.length === 0} aria-live="polite">
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
        <p className="text-center text-sm text-muted-foreground">{t("slipNote")}</p>
      </div>
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
                  <span className="truncate">{s.name}</span>
                </a>
              </li>
            ))}
          </ul>
        </div>
      )}
    </section>
  );
}

/** Review & pay: a confirm step that is clearly not a receipt; the amount is on the one primary button. */
export default function ReviewPay({ payment, gateway, charges, hasOpenBill, payUrl, transfer, verification, rejection, member, payClub, paymentsUrl, dashboardUrl }: Props) {
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
  const total = verification || isTransfer ? payment.amount : charges.totalSen;

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
        <Money sen={total} className="figure font-display text-[44px] font-extrabold leading-none tracking-tight" />
        {memberCharge && (
          <span className="text-sm text-muted-foreground" data-testid="fee-line">
            {fill(t("feeLine"), {
              a: rm(payment.amount),
              b: rm(charges.feeSen),
              c: rm(total),
            })}
          </span>
        )}
      </div>

      <FlashBanner />

      <section
        aria-labelledby="sheet-h"
        className="relative flex animate-sheet-up flex-col gap-5 rounded-[28px] border bg-card p-5 shadow-[0_-12px_40px_-24px_rgba(30,27,58,0.45)] motion-reduce:animate-none"
      >
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
          {memberCharge && (
            <div className="px-4 py-3">
              <dt className="text-sm text-muted-foreground">{t("gatewayCharge")}</dt>
              <dd className="flex justify-between gap-2 text-base">
                <span className="min-w-0 text-sm text-muted-foreground">{t("chargeByMember")}</span>
                <Money sen={charges.feeSen} className="font-semibold" />
              </dd>
            </div>
          )}
          <div className="flex items-center justify-between gap-3 bg-muted/60 px-4 py-3">
            <dt className="text-base font-semibold">{t("total")}</dt>
            <dd>
              <Money sen={total} className="font-display text-2xl font-bold" />
            </dd>
          </div>
        </dl>
        <Link href={payment.urls.invoice} className="-mt-3 inline-flex min-h-11 items-center self-start text-base font-semibold text-primary underline-offset-4 hover:underline">
          {t("invoice")} {payment.invoiceNo}
        </Link>

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
                  {rejection.at && <p className="mt-1 text-sm opacity-80">{fmtDate(rejection.at, lang)}</p>}
                </div>
              </Alert>
            )}

            {transfer && (
              <fieldset className="flex flex-col gap-2">
                <legend className="mb-2 text-base font-semibold">{t("chooseMethod")}</legend>
                <MethodOption
                  value="fpx"
                  checked={method === "fpx"}
                  onChange={setMethod}
                  title={t("methodFpx")}
                  sub={fill(t("methodFpxSub"), gw)}
                  aside={fpxCharge ? fill(t("plusChargeShort"), { x: rm(charges.feeSen) }) : null}
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
              <TransferForm transfer={transfer} amount={payment.amount} />
            ) : (
              <>
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
                        <Lock aria-hidden /> {t("payNow")} {rm(total)}
                      </>
                    )}
                  </Button>
                  <p className="text-center text-sm text-muted-foreground">FPX · {gateway.label}</p>
                </div>
              </>
            )}
          </>
        )}
      </section>

      <HelpLine email={payClub.email} />
    </MemberShell>
  );
}
