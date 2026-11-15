import { useState, type FormEvent } from "react";
import { CalendarPlus, ChevronDown } from "lucide-react";
import { Head, Link, router, useForm } from "@inertiajs/react";
import { MemberShell } from "@/components/app/MemberShell";
import { FlashBanner, PaymentRow } from "@/components/app/MemberParts";
import { Money } from "@/components/app/Money";
import { Button } from "@/components/ui/button";
import { FieldError, Input, Label } from "@/components/ui/input";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { fill, useT } from "@/lib/i18n";
import type { MemberPaymentRow } from "@/types/member";

interface Props {
  club: { id: number; name: string };
  selectedYear: number;
  years: number[];
  summary: { paid: number; pending: number; overdue: number; paidCount: number; total: number };
  payments: MemberPaymentRow[];
  pagination: { currentPage: number; lastPage: number; total: number; prevUrl: string | null; nextUrl: string | null };
  futureForm: { action: string; defaultStart: string };
  paymentsUrl: string;
  dashboardUrl: string;
}

function FutureForm({ action, defaultStart }: Props["futureForm"]) {
  const t = useT();
  const [open, setOpen] = useState(false);
  const form = useForm({ period_start: defaultStart, frequency: "monthly" });
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
          <div className="flex flex-col gap-1.5">
            <Label htmlFor="period_start" className="text-base">
              {t("futureStart")}
            </Label>
            <Input
              id="period_start"
              type="date"
              className="h-12 text-base"
              value={form.data.period_start}
              invalid={!!form.errors.period_start}
              onChange={(e) => form.setData("period_start", e.target.value)}
            />
            {form.errors.period_start && <FieldError>{form.errors.period_start}</FieldError>}
          </div>
          <div className="flex flex-col gap-1.5">
            <Label htmlFor="frequency" className="text-base">
              {t("futureType")}
            </Label>
            <Select value={form.data.frequency} onValueChange={(v) => form.setData("frequency", v)}>
              <SelectTrigger id="frequency" className="h-12 text-base">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {(["monthly", "quarterly", "yearly"] as const).map((f) => (
                  <SelectItem key={f} value={f} className="py-2.5 text-base">
                    {t(`freq_${f}`)}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
            {form.errors.frequency && <FieldError>{form.errors.frequency}</FieldError>}
          </div>
          <Button type="submit" size="lg" className="h-12 rounded-full" disabled={form.processing}>
            {t("futureSubmit")}
          </Button>
        </form>
      )}
    </section>
  );
}

export default function Payments({ club, selectedYear, years, summary, payments, pagination, futureForm, paymentsUrl, dashboardUrl }: Props) {
  const t = useT();
  const open = payments.filter((p) => p.status !== "paid");
  const paid = payments.filter((p) => p.status === "paid");
  const groups = [
    { key: "open", title: t("stillToPay"), items: open },
    { key: "paid", title: t("paidGroup"), items: paid },
  ].filter((g) => g.items.length > 0);

  const changeYear = (y: string) => router.get(paymentsUrl, { year: y }, { preserveScroll: true, preserveState: false });

  return (
    <MemberShell tab="payments" navHrefs={{ home: dashboardUrl, payments: paymentsUrl }} clubName={club.name} title={t("history")}>
      <Head title={t("history")} />
      <FlashBanner />

      <div className="flex items-center justify-between gap-3">
        <Label htmlFor="year" className="text-base font-semibold">
          {t("year")}
        </Label>
        <Select value={String(selectedYear)} onValueChange={changeYear}>
          <SelectTrigger id="year" className="h-12 w-32 text-base">
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

      <div className="grid grid-cols-2 gap-2">
        <div className="rounded-[20px] bg-accent p-4">
          <div className="text-sm font-medium text-accent-foreground">{fill(t("paidInYear"), { y: selectedYear })}</div>
          <Money sen={summary.paid} className="figure font-display text-[26px] font-extrabold leading-tight" />
        </div>
        <div className="rounded-[20px] border bg-card p-4">
          <div className="text-sm font-medium text-muted-foreground">{fill(t("owingInYear"), { y: selectedYear })}</div>
          <Money sen={summary.pending + summary.overdue} className="figure font-display text-[26px] font-extrabold leading-tight" />
        </div>
      </div>

      {groups.length === 0 && (
        <p className="rounded-[20px] border bg-card p-5 text-center text-base text-muted-foreground">{fill(t("noPaymentsYear"), { y: selectedYear })}</p>
      )}
      {groups.map((g) => (
        <section key={g.key} aria-labelledby={`h-${g.key}`} className="flex flex-col gap-2">
          <h2 id={`h-${g.key}`} className="px-1 font-display text-xl font-bold tracking-tight">
            {g.title}
          </h2>
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
