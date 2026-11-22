import { useEffect, useState, type FormEvent } from "react";
import { Head, router, useForm, usePage } from "@inertiajs/react";
import { AlertTriangle, Check, CheckCircle2, Copy, Download, KeyRound, Loader2, ShieldCheck, ShieldOff, Smartphone } from "lucide-react";
import { MemberShell } from "@/components/app/MemberShell";
import { useServerText } from "@/components/app/MemberParts";
import { Alert } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Input, Label } from "@/components/ui/input";
import { fill, useT, type DictKey } from "@/lib/i18n";
import { cn } from "@/lib/utils";

interface Props {
  clubName: string;
  enabled: boolean;
  /** Present while a setup this session started is waiting for its first code. */
  setup: { qrSvg: string; secret: string } | null;
  /** Shown once, right after 2FA is turned on. */
  recoveryCodes: string[] | null;
  urls: { home: string; enable: string; confirm: string; disable: string };
}

/** After a failed submit, focus the first field marked invalid (once React has rendered the errors). */
function focusFirstInvalid(formId: string) {
  window.requestAnimationFrame(() => document.querySelector<HTMLElement>(`#${formId} [aria-invalid="true"]`)?.focus());
}

/** A field error: the server sends member.* codes, shown in the page language. */
function ErrorText({ id, msg }: { id: string; msg: string }) {
  const text = useServerText();
  return (
    <p id={id} className="text-sm font-medium text-danger-foreground">
      {text(msg)}
    </p>
  );
}

function Password({ id, error, hint, value, onChange }: { id: string; error?: string; hint: DictKey; value: string; onChange: (v: string) => void }) {
  const t = useT();
  return (
    <div className="flex flex-col gap-1.5">
      <Label htmlFor={id} className="text-base">
        {t("secPassword")}
      </Label>
      <Input
        id={id}
        type="password"
        className="h-12 text-base"
        autoComplete="current-password"
        required
        value={value}
        invalid={!!error}
        aria-describedby={`${id}-note`}
        onChange={(e) => onChange(e.target.value)}
      />
      {error ? (
        <ErrorText id={`${id}-note`} msg={error} />
      ) : (
        <p id={`${id}-note`} className="text-sm text-muted-foreground">
          {t(hint)}
        </p>
      )}
    </div>
  );
}

function SubmitButton({ busy, children, variant = "default" }: { busy: boolean; children: React.ReactNode; variant?: "default" | "outline" }) {
  return (
    <Button type="submit" size="lg" variant={variant} className="h-auto min-h-12 w-full whitespace-normal rounded-full py-2.5 text-base" disabled={busy}>
      {busy && <Loader2 className="animate-spin" aria-hidden />}
      {children}
    </Button>
  );
}

function RecoveryCodes({ codes, onDone }: { codes: string[]; onDone: () => void }) {
  const t = useT();
  const [copied, setCopied] = useState(false);
  const text = codes.join("\n");
  const copy = async () => {
    try {
      await navigator.clipboard.writeText(text);
      setCopied(true);
      window.setTimeout(() => setCopied(false), 1600);
    } catch {
      setCopied(false);
    }
  };
  const download = () => {
    const url = URL.createObjectURL(new Blob([`${t("secCodesLabel")}\n\n${text}\n`], { type: "text/plain" }));
    const a = document.createElement("a");
    a.href = url;
    a.download = "recovery-codes.txt";
    a.click();
    URL.revokeObjectURL(url);
  };
  return (
    <section aria-labelledby="codes-h" className="flex flex-col gap-3 rounded-[20px] bg-warning-soft p-4 text-warning-foreground ring-1 ring-warning/30" data-testid="recovery-codes">
      <h2 id="codes-h" className="flex items-center gap-2 text-base font-semibold">
        <AlertTriangle className="h-5 w-5 shrink-0" aria-hidden /> {t("secCodesTitle")}
      </h2>
      <p className="text-sm">{t("secCodesBody")}</p>
      <ul aria-label={t("secCodesLabel")} className="grid grid-cols-2 gap-2 rounded-2xl bg-card p-3 font-mono text-base text-foreground">
        {codes.map((c) => (
          <li key={c}>{c}</li>
        ))}
      </ul>
      <div className="grid grid-cols-2 gap-2">
        <Button type="button" variant="outline" size="lg" className="h-auto min-h-12 whitespace-normal rounded-full bg-card" onClick={copy}>
          {copied ? <Check aria-hidden /> : <Copy aria-hidden />}
          <span aria-live="polite">{copied ? t("copied") : t("secCopyCodes")}</span>
        </Button>
        <Button type="button" variant="outline" size="lg" className="h-auto min-h-12 whitespace-normal rounded-full bg-card" onClick={download}>
          <Download aria-hidden /> {t("secDownloadCodes")}
        </Button>
      </div>
      <Button type="button" size="lg" className="h-auto min-h-12 w-full whitespace-normal rounded-full" onClick={onDone}>
        <Check aria-hidden /> {t("secCodesSaved")}
      </Button>
    </section>
  );
}

function StartForm({ url }: { url: string }) {
  const t = useT();
  const form = useForm({ current_password: "" });
  const submit = (e: FormEvent) => {
    e.preventDefault();
    form.post(url, { preserveScroll: true, onError: () => focusFirstInvalid("start-2fa") });
  };
  return (
    <form id="start-2fa" onSubmit={submit} noValidate className="flex flex-col gap-4">
      <Password id="start_password" hint="secPasswordStartHint" error={form.errors.current_password} value={form.data.current_password} onChange={(v) => form.setData("current_password", v)} />
      <SubmitButton busy={form.processing}>
        <ShieldCheck aria-hidden /> {t("secStart")}
      </SubmitButton>
    </form>
  );
}

function SetupForm({ setup, url }: { setup: NonNullable<Props["setup"]>; url: string }) {
  const t = useT();
  const [copied, setCopied] = useState(false);
  const form = useForm({ code: "", current_password: "" });
  const submit = (e: FormEvent) => {
    e.preventDefault();
    form.post(url, { preserveScroll: true, onError: () => focusFirstInvalid("confirm-2fa"), onFinish: () => form.reset("code") });
  };
  const copyKey = async () => {
    try {
      await navigator.clipboard.writeText(setup.secret);
      setCopied(true);
      window.setTimeout(() => setCopied(false), 1600);
    } catch {
      setCopied(false);
    }
  };
  // Group the key in fours so it is easier to type.
  const spaced = setup.secret.replace(/(.{4})/g, "$1 ").trim();
  return (
    <form id="confirm-2fa" onSubmit={submit} noValidate className="flex flex-col gap-5" data-testid="2fa-setup">
      <div className="flex flex-col gap-3">
        <h3 className="flex items-start gap-2 text-base font-semibold">
          <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-primary text-sm font-bold text-primary-foreground" aria-hidden>
            1
          </span>
          {t("secStep1")}
        </h3>
        {/* Server-made SVG (BaconQrCode), on white so every app can read it in dark mode too. */}
        <div
          role="img"
          aria-label={t("secQrAlt")}
          className="mx-auto w-[200px] max-w-full rounded-2xl bg-white p-2 [&_svg]:h-auto [&_svg]:w-full"
          dangerouslySetInnerHTML={{ __html: setup.qrSvg }}
        />
        <p className="text-sm text-muted-foreground">{t("secManual")}</p>
        <div className="flex flex-wrap items-center justify-between gap-2 rounded-2xl bg-muted p-3">
          <code className="min-w-0 flex-[1_1_10rem] break-all font-mono text-base font-semibold tracking-wide" data-testid="2fa-secret">
            {spaced}
          </code>
          <Button type="button" variant="outline" size="sm" className="h-11 shrink-0 rounded-full px-3" onClick={copyKey} aria-label={fill(t("copyLabel"), { x: t("secKey") })}>
            {copied ? <Check aria-hidden /> : <Copy aria-hidden />}
            <span aria-live="polite">{copied ? t("copied") : t("copy")}</span>
          </Button>
        </div>
      </div>
      <div className="flex flex-col gap-3">
        <h3 className="flex items-start gap-2 text-base font-semibold">
          <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-primary text-sm font-bold text-primary-foreground" aria-hidden>
            2
          </span>
          {t("secStep2")}
        </h3>
        <div className="flex flex-col gap-1.5">
          <Label htmlFor="code" className="text-base">
            {t("secCode")}
          </Label>
          <Input
            id="code"
            className="h-12 text-center font-mono text-xl tracking-[0.3em]"
            inputMode="numeric"
            autoComplete="one-time-code"
            pattern="[0-9]*"
            maxLength={6}
            required
            value={form.data.code}
            invalid={!!form.errors.code}
            aria-describedby={form.errors.code ? "code-note" : undefined}
            onChange={(e) => form.setData("code", e.target.value.replace(/\D/g, ""))}
          />
          {form.errors.code && <ErrorText id="code-note" msg={form.errors.code} />}
        </div>
        <Password id="confirm_password" hint="secPasswordStartHint" error={form.errors.current_password} value={form.data.current_password} onChange={(v) => form.setData("current_password", v)} />
      </div>
      <SubmitButton busy={form.processing}>
        <ShieldCheck aria-hidden /> {t("secConfirm")}
      </SubmitButton>
    </form>
  );
}

function DisableForm({ url }: { url: string }) {
  const t = useT();
  const form = useForm({ password: "", code: "" });
  const submit = (e: FormEvent) => {
    e.preventDefault();
    form.delete(url, { preserveScroll: true, onError: () => focusFirstInvalid("disable-2fa"), onFinish: () => form.reset("code") });
  };
  return (
    <form id="disable-2fa" onSubmit={submit} noValidate className="flex flex-col gap-4 border-t pt-4">
      <Password id="disable_password" hint="secTurnOffHint" error={form.errors.password} value={form.data.password} onChange={(v) => form.setData("password", v)} />
      <div className="flex flex-col gap-1.5">
        <Label htmlFor="disable_code" className="text-base">
          {t("secTurnOffCode")}
        </Label>
        <Input
          id="disable_code"
          className="h-12 font-mono text-base tracking-wider"
          autoComplete="one-time-code"
          autoCapitalize="characters"
          spellCheck={false}
          maxLength={64}
          required
          value={form.data.code}
          invalid={!!form.errors.code}
          aria-describedby="disable_code-note"
          onChange={(e) => form.setData("code", e.target.value)}
        />
        {form.errors.code ? (
          <ErrorText id="disable_code-note" msg={form.errors.code} />
        ) : (
          <p id="disable_code-note" className="text-sm text-muted-foreground">
            {t("secTurnOffCodeHint")}
          </p>
        )}
      </div>
      <SubmitButton busy={form.processing} variant="outline">
        <ShieldOff aria-hidden /> {t("secTurnOff")}
      </SubmitButton>
    </form>
  );
}

export default function Security({ clubName, enabled, setup, recoveryCodes, urls }: Props) {
  const t = useT();
  const text = useServerText();
  const { flash } = usePage().props;
  // The codes live only in this component: the history entry is rewritten without them straight away,
  // so Back / restore can never show them again (the page history is also encrypted).
  const [codes, setCodes] = useState(recoveryCodes);
  useEffect(() => {
    if (recoveryCodes && recoveryCodes.length > 0) {
      setCodes(recoveryCodes); // the page may not remount after the confirm redirect
      router.replaceProp("recoveryCodes", null);
    }
  }, [recoveryCodes]);
  return (
    <MemberShell tab="account" navHrefs={{ home: urls.home }} clubName={clubName} title={t("secTitle")} backHref={urls.home} hideNav>
      <Head title={t("secTitle")} />
      {flash.success && (
        <Alert variant="success" className="text-base">
          <CheckCircle2 aria-hidden />
          <p>{text(flash.success)}</p>
        </Alert>
      )}
      {flash.error && (
        <Alert variant="danger" className="text-base">
          <AlertTriangle aria-hidden />
          <p>{text(flash.error)}</p>
        </Alert>
      )}

      {codes && codes.length > 0 && <RecoveryCodes codes={codes} onDone={() => setCodes(null)} />}

      <section aria-labelledby="what-h" className="flex flex-col gap-2 rounded-[20px] border bg-card p-4">
        <h2 id="what-h" className="flex items-center gap-2 text-base font-semibold">
          <Smartphone className="h-5 w-5 shrink-0 text-primary" strokeWidth={1.75} aria-hidden /> {t("secWhatTitle")}
        </h2>
        <p className="text-base">{t("secWhatBody")}</p>
        <p className="text-sm text-muted-foreground">{t("secAppHint")}</p>
      </section>

      <section aria-labelledby="status-h" className="flex flex-col gap-4 rounded-[20px] border bg-card p-4">
        <div className="flex items-start gap-3">
          <span
            className={cn(
              "flex h-11 w-11 shrink-0 items-center justify-center rounded-full",
              enabled ? "bg-success-soft text-success-foreground" : "bg-muted text-muted-foreground",
            )}
          >
            {enabled ? <ShieldCheck className="h-6 w-6" strokeWidth={1.75} aria-hidden /> : <KeyRound className="h-6 w-6" strokeWidth={1.75} aria-hidden />}
          </span>
          <div className="min-w-0">
            <h2 id="status-h" className="text-base font-semibold" data-testid="2fa-status">
              {t(enabled ? "secStatusOn" : "secStatusOff")}
            </h2>
            <p className="text-sm text-muted-foreground">{t(enabled ? "secOnBody" : "secOffBody")}</p>
          </div>
        </div>
        {enabled ? <DisableForm url={urls.disable} /> : setup ? <SetupForm setup={setup} url={urls.confirm} /> : <StartForm url={urls.enable} />}
      </section>
    </MemberShell>
  );
}
