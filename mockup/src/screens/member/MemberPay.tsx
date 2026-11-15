import { useState } from "react";
import { Check, Loader2, Lock, MessageCircle, ShieldCheck } from "lucide-react";
import { MemberShell } from "@/components/app/MemberShell";
import { Money } from "@/components/app/Money";
import { BankMark, FpxMark } from "@/components/app/Visuals";
import { Button } from "@/components/ui/button";
import { Label } from "@/components/ui/input";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { club, currentMember, fpxBanks } from "@/data/club";
import { amountDue, dueNow } from "@/data/payments";
import { useLang, useT } from "@/i18n";
import { rm } from "@/lib/format";
import { cn } from "@/lib/utils";

/** Review & pay: a bottom-sheet style confirm step. Clearly not a receipt; the amount is on the button. */
export function MemberPay() {
  const t = useT();
  const { lang } = useLang();
  const [bank, setBank] = useState("Maybank2u");
  const [sent, setSent] = useState(false);
  const popular = fpxBanks.filter((b) => b.popular);
  const others = fpxBanks.filter((b) => !b.popular);

  const pay = () => {
    if (sent) return;
    setSent(true);
    window.setTimeout(() => (window.location.hash = "pay-success"), 1400);
  };

  return (
    <MemberShell tab="payments" title={t("reviewPay")} backHash="member-home" hideNav>
      <div className="-mx-1 flex flex-col items-center gap-1 pt-1 text-center">
        <span className="text-base text-muted-foreground">{t("payFeeTitle")}</span>
        <Money sen={amountDue} className="figure font-display text-[44px] font-extrabold leading-none tracking-tight" />
      </div>

      <section aria-labelledby="sheet-h" className="relative flex animate-sheet-up flex-col gap-5 rounded-[28px] border bg-card p-5 shadow-[0_-12px_40px_-24px_rgba(6,61,50,0.45)]">
        <div className="mx-auto -mt-2 h-1.5 w-12 rounded-full bg-border" aria-hidden />
        <h1 id="sheet-h" className="font-display text-2xl font-bold tracking-tight">
          {t("reviewPay")}
        </h1>

        <dl className="flex flex-col divide-y rounded-2xl border">
          <div className="flex items-start gap-3 px-4 py-3">
            <div className="min-w-0 flex-1">
              <dt className="text-sm text-muted-foreground">{t("paidFor")}</dt>
              {dueNow.map((p) => (
                <dd key={p.id} className="flex justify-between gap-2 text-base">
                  <span className="min-w-0">{lang === "bm" ? p.descriptionBm : p.description}</span>
                  <Money sen={p.amount} className="font-semibold" />
                </dd>
              ))}
            </div>
          </div>
          <div className="flex items-center gap-3 px-4 py-3">
            <div className="min-w-0 flex-1">
              <dt className="text-sm text-muted-foreground">{t("member")}</dt>
              <dd className="truncate text-base font-semibold">{currentMember.name}</dd>
              <dd className="font-mono text-sm text-muted-foreground">{currentMember.memberNo}</dd>
            </div>
          </div>
          <div className="flex items-center gap-3 px-4 py-3">
            <div className="min-w-0 flex-1">
              <dt className="text-sm text-muted-foreground">{t("payee")}</dt>
              <dd className="text-base font-semibold">{club.name}</dd>
            </div>
          </div>
          <div className="flex items-center justify-between gap-3 bg-muted/60 px-4 py-3">
            <dt className="text-base font-semibold">{t("total")}</dt>
            <dd>
              <Money sen={amountDue} className="font-display text-2xl font-bold" />
            </dd>
          </div>
        </dl>
        <a href="#member-invoice" className="-mt-3 inline-flex min-h-11 items-center self-start text-base font-semibold text-primary underline-offset-4 hover:underline">
          {t("change")} · {t("invoice")}
        </a>

        <fieldset className="flex flex-col gap-3">
          <legend className="mb-1 flex w-full items-center justify-between gap-2">
            <span className="text-base font-semibold">{t("chooseBank")}</span>
            <FpxMark />
          </legend>
          <div className="text-sm font-medium text-muted-foreground">{t("popularBanks")}</div>
          <div role="radiogroup" aria-label={t("popularBanks")} className="grid grid-cols-1 gap-2">
            {popular.map((b) => {
              const on = bank === b.name;
              return (
                <button
                  key={b.name}
                  type="button"
                  role="radio"
                  aria-checked={on}
                  disabled={b.offline}
                  onClick={() => setBank(b.name)}
                  className={cn(
                    "relative flex min-h-14 items-center gap-2.5 rounded-2xl border px-3 py-2 text-left text-sm font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring",
                    on ? "border-primary bg-accent ring-1 ring-primary" : "hover:bg-muted",
                    b.offline && "cursor-not-allowed bg-muted/50 text-muted-foreground opacity-80 hover:bg-muted/50",
                  )}
                >
                  <BankMark mark={b.mark} className={b.offline ? "opacity-50" : undefined} />
                  <span className="min-w-0">
                    <span className="block">{b.name}</span>
                    {b.offline && <span className="block text-xs font-medium">{t("offlineNow")}</span>}
                  </span>
                  {on && (
                    <span className="absolute right-2 top-2 flex h-5 w-5 items-center justify-center rounded-full bg-primary text-primary-foreground">
                      <Check className="h-3.5 w-3.5" strokeWidth={3} aria-hidden />
                    </span>
                  )}
                </button>
              );
            })}
          </div>
          <div className="flex flex-col gap-1.5">
            <Label htmlFor="otherBank" className="text-sm text-muted-foreground">
              {t("otherBanks")}
            </Label>
            <Select value={others.some((o) => o.name === bank) ? bank : ""} onValueChange={setBank}>
              <SelectTrigger id="otherBank" className="h-12 text-base">
                <SelectValue placeholder={lang === "bm" ? "Pilih bank lain" : "Choose another bank"} />
              </SelectTrigger>
              <SelectContent>
                {others.map((b) => (
                  <SelectItem key={b.name} value={b.name} className="py-2.5 text-base">
                    {b.name}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
        </fieldset>

        <div className="flex items-start gap-3 rounded-2xl bg-accent/70 p-3 text-sm text-accent-foreground">
          <ShieldCheck className="mt-0.5 h-5 w-5 shrink-0" strokeWidth={1.75} aria-hidden />
          <p>
            <span className="font-semibold">{t("secured")}.</span> {t("handoff")} {t("noFees")}
          </p>
        </div>

        <div className="flex flex-col gap-2">
          <Button size="xl" className="w-full rounded-full text-lg" onClick={pay} disabled={sent} aria-live="polite">
            {sent ? (
              <>
                <Loader2 className="animate-spin" aria-hidden /> {t("redirecting")}
              </>
            ) : (
              <>
                <Lock aria-hidden /> {t("payNow")} {rm(amountDue)}
              </>
            )}
          </Button>
          <p className="text-center text-sm text-muted-foreground">
            {bank} · FPX · ToyyibPay
          </p>
        </div>
      </section>

      <a href="https://wa.me/60167789012" className="flex min-h-12 items-center justify-center gap-2 text-base font-semibold text-primary hover:underline">
        <MessageCircle className="h-5 w-5" strokeWidth={1.75} aria-hidden /> {t("helpTitle")} {t("helpBody")}
      </a>
      <p className="text-center text-sm text-mock">
        Mockup: see the other results →{" "}
        <a className="inline-flex min-h-11 items-center font-semibold underline" href="#pay-pending">
          pending
        </a>{" "}
        ·{" "}
        <a className="inline-flex min-h-11 items-center font-semibold underline" href="#pay-failed">
          failed
        </a>
      </p>
    </MemberShell>
  );
}
