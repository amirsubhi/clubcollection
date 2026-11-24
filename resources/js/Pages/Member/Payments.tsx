import { useState, type FormEvent } from "react";
import { CalendarPlus, ChevronDown, PiggyBank, RefreshCw } from "lucide-react";
import { Head, Link, router, useForm } from "@inertiajs/react";
import { MemberShell } from "@/components/app/MemberShell";
import { FlashBanner, PaymentRow, useServerText } from "@/components/app/MemberParts";
import { Money } from "@/components/app/Money";
import { Button } from "@/components/ui/button";
import { FieldError, Label } from "@/components/ui/input";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { fmtDate, fmtMonth, rm } from "@/lib/format";
import { fill, useLang, useT, useCountText } from "@/lib/i18n";
import type { MemberPaymentRow, PayAllOffer } from "@/types/member";

interface Props {
  club: { id: number; name: string };
  selectedYear: number;
  years: number[];
  /** Sen by DisplayStatus; processing = an FPX payment the bank has not confirmed (not in what is owed). */
  summary: { paid: number; due: number; overdue: number; processing: number; verifying: number; paidCount: number; total: number };
  payments: MemberPaymentRow[];
  pagination: { currentPage: number; lastPage: number; total: number; prevUrl: string | null; nextUrl: string | null };
  futureForm: {
    action: string;
    freeMonths: string[];
    defaultStart: string | null;
    maxEnd: string;
    paidUntil: string | null;
    plans: ("monthly" | "quarterly" | "yearly")[];
    defaultPlan: "monthly" | "quarterly" | "yearly";
    /** Rate for the first offered month; null = no rate set yet. */
    monthlySen: number | null;
    /** The member's rate in force in each month that can be paid ahead (first-of-month ISO date → sen; null = no rate). */
    monthRates: Record<string, number | null>;
    feePayer: "club" | "member";
    feeSen: number;
  };
  /** "Bayar semua": every fee still owed in the club (any year); null with fewer than two. */
  payAll: PayAllOffer | null;
  paymentsUrl: string;
  dashboardUrl: string;
}

/** Oldest first: due date, then period start, then invoice number. */
const oldestFirst = (a: MemberPaymentRow, b: MemberPaymentRow) =>
  a.dueDate.localeCompare(b.dueDate) || a.periodStart.localeCompare(b.periodStart) || a.id - b.id;

type Plan = "monthly" | "quarterly" | "yearly";
const PLAN_MONTHS: Record<Plan, 1 | 3 | 12> = { monthly: 1, quarterly: 3, yearly: 12 };

/** ISO date of the last day of a period of `months` starting on the first-of-month `start`. */
function periodEnd(start: string, months: number): string {
  const [y, m] = start.slice(0, 7).split("-").map(Number);
  const last = new Date(Date.UTC(y, m - 1 + months, 0));
  return last.toISOString().slice(0, 10);
}

/** ISO date of the first day of the month `i` months after the first-of-month `start`. */
function monthAfter(start: string, i: number): string {
  const [y, m] = start.slice(0, 7).split("-").map(Number);
  return new Date(Date.UTC(y, m - 1 + i, 1)).toISOString().slice(0, 10);
}

/** Pay ahead: 1, 3 or 12 months (as the club allows), with the gateway-charge saving vs paying monthly. */
function FutureForm({ action, freeMonths, defaultStart, maxEnd, paidUntil, plans, defaultPlan, monthlySen, monthRates, feePayer, feeSen }: Props["futureForm"]) {
  const t = useT();
  const serverText = useServerText();
  const { lang } = useLang();
  const [open, setOpen] = useState(false);
  // Default plan, or the longest allowed plan that still fits the cap from the default start.
  const firstFit = (start: string | null, preferred: Plan): Plan => {
    const ok = (p: Plan) => !!start && periodEnd(start, PLAN_MONTHS[p]) <= maxEnd;
    return ok(preferred) ? preferred : ([...plans].reverse().find(ok) ?? preferred);
  };
  const form = useForm<{ period_start: string; frequency: Plan }>({ period_start: defaultStart ?? "", frequency: firstFit(defaultStart, defaultPlan) });
  // The fee for a plan from the chosen start: each month's rate added up, as the server bills it
  // (the same rate × months unless a new rate starts inside the period). Null when a month has no rate.
  const priceFor = (p: Plan): number | null => {
    if (!form.data.period_start) return null;
    let sum = 0;
    for (let i = 0; i < PLAN_MONTHS[p]; i++) {
      const r = monthRates[monthAfter(form.data.period_start, i)];
      if (r === null || r === undefined) return null;
      sum += r;
    }
    return sum;
  };
  // A plan is offered only if its period ends within the 12-month cap from the chosen start, every month has a
  // rate, and it costs something (RM 0 months are waived; a period of only those has nothing to pay).
  const fits = (p: Plan) => !!form.data.period_start && periodEnd(form.data.period_start, PLAN_MONTHS[p]) <= maxEnd && (priceFor(p) ?? 0) > 0;
  const chooseStart = (v: string) => {
    form.setData((d) => ({ period_start: v, frequency: firstFit(v, d.frequency) }));
  };
  const memberPays = feePayer === "member" && feeSen > 0;
  const chosen = PLAN_MONTHS[form.data.frequency];
  const totalFor = (p: Plan) => (priceFor(p) ?? 0) + (memberPays ? feeSen : 0);
  const saving = feeSen * (chosen - 1);

  const submit = (e: FormEvent) => {
    e.preventDefault();
    form.post(action, { preserveScroll: true });
  };
  return (
    <section aria-labelledby="future-h" className="rounded-[20px] border bg-card">
      <button
        type="button"
        aria-expanded={open}
        aria-controls="future-body"
        onClick={() => setOpen((o) => !o)}
        className="flex min-h-14 w-full items-center gap-3 rounded-[20px] px-4 py-3 text-left hover:bg-muted/60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
      >
        <CalendarPlus className="h-5 w-5 shrink-0 text-primary" strokeWidth={1.75} aria-hidden />
        <span className="min-w-0 flex-1">
          <span id="future-h" className="block text-base font-semibold">
            {t("futureTitle")}
          </span>
          <span className="block text-sm text-muted-foreground">{t("futureBody")}</span>
        </span>
        <ChevronDown className={`h-5 w-5 shrink-0 text-muted-foreground transition-transform ${open ? "rotate-180" : ""}`} aria-hidden />
      </button>
      {open && (
        <form id="future-body" onSubmit={submit} className="flex flex-col gap-4 border-t px-4 py-4" noValidate>
          {monthlySen === null ? (
            <p className="text-base text-muted-foreground">{t("noFeeRateYet")}</p>
          ) : freeMonths.length === 0 ? (
            <p className="text-base text-muted-foreground" data-testid="paid-up-to">
              {fill(t("paidUpTo"), { d: paidUntil ? fmtDate(paidUntil, lang) : "" })}
            </p>
          ) : (
            <>
              <fieldset className="flex flex-col gap-2">
                <legend className="mb-1.5 text-base font-medium">{t("futurePlan")}</legend>
                {/* One card per row below 22.5em (360 px): three columns of about 77 px broke longer words and the price. */}
                <div className={`grid grid-cols-1 gap-2 ${plans.length === 3 ? "min-[22.5em]:grid-cols-3" : plans.length === 2 ? "min-[22.5em]:grid-cols-2" : ""}`}>
                  {plans.map((p) => {
                    const checked = form.data.frequency === p;
                    const disabled = !fits(p);
                    return (
                      <label
                        key={p}
                        title={disabled ? t("beyondCap") : undefined}
                        className={`relative flex min-h-[88px] flex-col justify-between gap-1 rounded-2xl border p-3 has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-ring ${
                          disabled ? "cursor-not-allowed opacity-50" : checked ? "cursor-pointer border-primary bg-accent/70 text-accent-foreground" : "cursor-pointer hover:bg-muted/60"
                        }`}
                      >
                        <input type="radio" name="frequency" value={p} checked={checked} disabled={disabled} onChange={() => form.setData("frequency", p)} className="sr-only" />
                        {p === defaultPlan && (
                          <span className="absolute -top-2.5 left-2 max-w-[calc(100%-1rem)] truncate rounded-full bg-primary px-2 py-0.5 text-xs font-semibold text-primary-foreground" title={t("recommended")}>{t("recommended")}</span>
                        )}
                        <span className="text-base font-semibold">{t(`months_${PLAN_MONTHS[p]}`)}</span>
                        <span className="text-xs text-muted-foreground">{t(`freq_${p}`)}</span>
                        <span className="money text-sm font-bold">{priceFor(p) === null ? "–" : rm(priceFor(p) ?? 0)}</span>
                      </label>
                    );
                  })}
                </div>
                {form.errors.frequency && <FieldError>{serverText(form.errors.frequency)}</FieldError>}
              </fieldset>

              {chosen > 1 && saving > 0 && (
                <p className="flex items-start gap-2 rounded-2xl bg-success-soft p-3 text-sm font-medium text-success-foreground" data-testid="saving-hint">
                  <PiggyBank className="mt-0.5 h-4 w-4 shrink-0" aria-hidden />
                  <span>
                    {memberPays
                      ? fill(t(form.data.frequency === "yearly" ? "saveVsMonthly_yearly" : "saveVsMonthly_quarterly"), { x: rm(saving) })
                      : t("helpsClubSave")}
                  </span>
                </p>
              )}

              <div className="flex flex-col gap-1.5">
                <Label htmlFor="period_start" className="text-base">
                  {t("futureStart")}
                </Label>
                <Select value={form.data.period_start} onValueChange={chooseStart}>
                  <SelectTrigger id="period_start" className="h-12 text-base">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {freeMonths.map((m) => (
                      <SelectItem key={m} value={m} className="py-2.5 text-base">
                        {fmtMonth(m, lang)}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
                {form.errors.period_start && <FieldError>{serverText(form.errors.period_start)}</FieldError>}
              </div>

              <div className="flex flex-col gap-1">
                <Button type="submit" size="lg" className="h-12 rounded-full" disabled={form.processing || !fits(form.data.frequency)}>
                  {fill(t("futureSubmitAmt"), { x: rm(totalFor(form.data.frequency)) })}
                </Button>
                {memberPays && <p className="text-center text-sm text-muted-foreground">{fill(t("plusCharge"), { x: rm(feeSen) })}</p>}
              </div>
            </>
          )}
        </form>
      )}
    </section>
  );
}

export default function Payments({ club, selectedYear, years, summary, payments, pagination, futureForm, payAll, paymentsUrl, dashboardUrl }: Props) {
  const t = useT();
  const tn = useCountText();
  // Fees still owed, oldest first (the order to pay them in); history stays newest first.
  const open = payments.filter((p) => p.status === "due" || p.status === "overdue").sort(oldestFirst);
  // Past its due date: "Perlu dibayar"; not yet due: "Akan datang".
  const late = open.filter((p) => p.pastDue);
  const notYet = open.filter((p) => !p.pastDue);
  const processing = payments.filter((p) => p.status === "processing").sort(oldestFirst);
  const verifying = payments.filter((p) => p.status === "verifying");
  const paid = payments.filter((p) => p.status === "paid");
  const groups = [
    { key: "processing", title: t("processingGroup"), items: processing },
    { key: "late", title: t("needToPay"), items: late },
    { key: "open", title: t("upcoming"), items: notYet },
    { key: "verifying", title: t("verifying"), items: verifying },
    { key: "paid", title: t("paidGroup"), items: paid },
  ].filter((g) => g.items.length > 0);

  const changeYear = (y: string) => router.get(paymentsUrl, { year: y }, { preserveScroll: true, preserveState: false });

  return (
    <MemberShell tab="payments" navHrefs={{ home: dashboardUrl, payments: paymentsUrl }} clubName={club.name} title={t("history")}>
      <Head title={t("history")} />
      <FlashBanner />

      <div className="flex flex-wrap items-center justify-between gap-x-3 gap-y-2">
        <Label htmlFor="year" className="text-base font-semibold">
          {t("year")}
        </Label>
        <Select value={String(selectedYear)} onValueChange={changeYear}>
          <SelectTrigger id="year" className="h-12 w-max min-w-32 max-w-full text-base">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            {years.map((y) => (
              <SelectItem key={y} value={String(y)} className="py-2.5 text-base">
                {y}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>

      <div className="grid grid-cols-[repeat(auto-fit,minmax(min(100%,10rem),1fr))] gap-2">
        <div className="min-w-0 rounded-[20px] bg-accent p-4">
          <div className="text-sm font-medium text-accent-foreground">{fill(t("paidInYear"), { y: selectedYear })}</div>
          <Money sen={summary.paid} className="figure font-display text-[1.625rem] font-extrabold leading-tight !whitespace-normal [overflow-wrap:anywhere]" />
        </div>
        <div className="min-w-0 rounded-[20px] border bg-card p-4">
          <div className="text-sm font-medium text-muted-foreground">{fill(t("owingInYear"), { y: selectedYear })}</div>
          <Money sen={summary.due + summary.overdue} className="figure font-display text-[1.625rem] font-extrabold leading-tight !whitespace-normal [overflow-wrap:anywhere]" />
        </div>
      </div>

      {groups.length === 0 && (
        <p className="rounded-[20px] border bg-card p-5 text-center text-base text-muted-foreground">{fill(t("noPaymentsYear"), { y: selectedYear })}</p>
      )}
      {payAll && open.length > 0 && (
        <Button asChild size="xl" className="h-auto min-h-14 w-full whitespace-normal rounded-full py-3 text-lg">
          <Link href={payAll.url} data-testid="payments-pay-all">
            {fill(t("payAllAmt"), { x: rm(payAll.totalSen) })}
            <span className="text-base font-semibold opacity-90">· {tn("feesN", payAll.count)}</span>
          </Link>
        </Button>
      )}
      {groups.map((g) => (
        <section key={g.key} aria-labelledby={`h-${g.key}`} className="flex flex-col gap-2">
          <h2 id={`h-${g.key}`} className="px-1 font-display text-xl font-bold tracking-tight">
            {g.title}
          </h2>
          {g.key === "processing" && (
            <p className="flex items-start gap-2 rounded-2xl bg-warning-soft px-4 py-3 text-base text-warning-foreground ring-1 ring-warning/25" data-testid="processing-note">
              <RefreshCw className="mt-1 h-4 w-4 shrink-0" strokeWidth={2} aria-hidden />
              <span>{t("processingGroupNote")}</span>
            </p>
          )}
          <ul className="divide-y rounded-[20px] border bg-card">
            {g.items.map((p) => (
              <li key={p.id}>
                <PaymentRow p={p} />
              </li>
            ))}
          </ul>
        </section>
      ))}

      {pagination.lastPage > 1 && (
        <nav aria-label={fill(t("pageOf"), { a: pagination.currentPage, b: pagination.lastPage })} className="flex items-center justify-between gap-2">
          {pagination.prevUrl ? (
            <Button asChild variant="outline" size="lg" className="h-12 rounded-full">
              <Link href={pagination.prevUrl} preserveScroll>
                {t("prevPage")}
              </Link>
            </Button>
          ) : (
            <span />
          )}
          <span className="text-sm text-muted-foreground">{fill(t("pageOf"), { a: pagination.currentPage, b: pagination.lastPage })}</span>
          {pagination.nextUrl ? (
            <Button asChild variant="outline" size="lg" className="h-12 rounded-full">
              <Link href={pagination.nextUrl} preserveScroll>
                {t("nextPage")}
              </Link>
            </Button>
          ) : (
            <span />
          )}
        </nav>
      )}

      <FutureForm {...futureForm} />
    </MemberShell>
  );
}
