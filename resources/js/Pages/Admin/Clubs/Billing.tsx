import { useState, type FormEvent, type ReactNode } from "react";
import { Link, router, useForm } from "@inertiajs/react";
import { ArrowRight, Check, CheckCircle2, Hourglass, Info, Landmark, MessageCircle, Save, ShieldCheck, X } from "lucide-react";
import { AdminLayout } from "@/components/app/AdminLayout";
import { PageHeader } from "@/components/app/PageHeader";
import { Field } from "@/components/app/Field";
import { StatusChip } from "@/components/app/StatusBadges";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from "@/components/ui/dialog";
import { Input, Label } from "@/components/ui/input";
import { fmtDateTime } from "@/lib/format";
import { cn } from "@/lib/utils";
import type { AdminNavProps } from "@/types/admin";

type Plan = "monthly" | "quarterly" | "yearly";

interface Settings {
  allow_monthly: boolean;
  allow_quarterly: boolean;
  allow_yearly: boolean;
  default_plan: Plan;
  gateway_fee_payer: "club" | "member";
  /** Ringgit, 2 decimals, as typed. */
  gateway_fee: string;
  /** Days after the due date before a fee is "Overdue" (Lewat) and reminded; 0–60, as typed. */
  overdue_grace_days: string;
  /** Optional WhatsApp help number, as shown ("+60 12-345 6789"); empty = members get the email link. */
  whatsapp_number: string;
  transfer_enabled: boolean;
  bank_name: string;
  bank_account_name: string;
  bank_account_no: string;
  duitnow_id: string;
}

interface PendingBank {
  requestedBy: string;
  requestedAt: string | null;
  requestedByMe: boolean;
  rows: { field: string; label: string; current: string | null; requested: string | null; changed: boolean }[];
  transferNow: boolean;
  transferRequested: boolean;
  canApprove: boolean;
  blocker: string | null;
  version: string;
}

interface Props {
  nav: AdminNavProps;
  clubName: string;
  gatewayLabel: string;
  memberFeeAllowed: boolean;
  memberFeeReason: string | null;
  plans: { key: Plan; label: string; months: number }[];
  settings: Settings;
  /** Members can pay by transfer right now (on, with complete approved details). */
  transferLive: boolean;
  pendingBank: PendingBank | null;
  urls: { update: string; approve: string | null; cancel: string | null; dashboard: string; verifications: string };
}

function Section({ id, title, sub, children }: { id: string; title: string; sub?: ReactNode; children: ReactNode }) {
  return (
    <section aria-labelledby={id} className="flex min-w-0 flex-col gap-4 rounded-lg border bg-card p-4 sm:p-6">
      <div>
        <h2 id={id} className="font-display text-lg font-bold tracking-tight">
          {title}
        </h2>
        {sub && <p className="mt-0.5 max-w-prose text-sm text-muted-foreground">{sub}</p>}
      </div>
      {children}
    </section>
  );
}

/** A labelled choice row (radio or checkbox) with a hint line; 44px+ target. */
function Choice({ id, title, hint, disabled, children }: { id: string; title: string; hint?: ReactNode; disabled?: boolean; children: ReactNode }) {
  return (
    <div className={cn("flex items-start gap-3 rounded-md border p-3", disabled ? "bg-muted/50" : "bg-card has-[:checked]:border-primary has-[:checked]:bg-accent/40")}>
      {children}
      <Label htmlFor={id} className={cn("flex min-w-0 flex-1 flex-col gap-0.5 leading-snug", disabled ? "cursor-not-allowed text-muted-foreground" : "cursor-pointer")}>
        <span className="font-semibold">{title}</span>
        {hint && <span className="text-xs font-normal text-muted-foreground">{hint}</span>}
      </Label>
    </div>
  );
}

function ConfirmButton({
  trigger,
  title,
  body,
  confirm,
  variant = "default",
  onConfirm,
  processing,
}: {
  trigger: ReactNode;
  title: string;
  body: ReactNode;
  confirm: ReactNode;
  variant?: "default" | "outline" | "destructive";
  onConfirm: () => void;
  processing?: boolean;
}) {
  const [open, setOpen] = useState(false);
  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <DialogTrigger asChild>
        <Button variant={variant} disabled={processing}>
          {trigger}
        </Button>
      </DialogTrigger>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{title}</DialogTitle>
          <DialogDescription asChild>
            <div>{body}</div>
          </DialogDescription>
        </DialogHeader>
        <DialogFooter className="sm:justify-between">
          <Button variant="outline" onClick={() => setOpen(false)}>
            Go back
          </Button>
          <Button
            variant={variant === "outline" ? "default" : variant}
            onClick={() => {
              setOpen(false);
              onConfirm();
            }}
          >
            {confirm}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

/** "9 Dec": the first overdue day for a fee due on 1 Dec with this grace period. */
function graceExample(days: number): string {
  const d = new Date(2026, 11, 1 + Math.min(days, 60) + 1);
  return d.toLocaleDateString("en-GB", { day: "numeric", month: "short" });
}

const show = (v: string | null) => (v && v.trim() !== "" ? v : "—");

function PendingBankPanel({ pending, urls }: { pending: PendingBank; urls: Props["urls"] }) {
  const [busy, setBusy] = useState(false);
  const post = (url: string, data: Record<string, string> = {}) =>
    router.post(url, data, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) });
  const req = Object.fromEntries(pending.rows.map((r) => [r.field, r.requested]));
  const summary = [req.bank_name, req.bank_account_name && `holder ${req.bank_account_name}`, req.bank_account_no && `account ${req.bank_account_no}`, req.duitnow_id && `DuitNow ${req.duitnow_id}`]
    .filter(Boolean)
    .join(", ");

  return (
    <section id="bank-change" aria-labelledby="bank-change-title" className="flex min-w-0 flex-col gap-4 rounded-lg border border-warning/40 bg-warning-soft/40 p-4 sm:p-6">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0">
          <h2 id="bank-change-title" className="flex items-center gap-2 font-display text-lg font-bold tracking-tight">
            <Hourglass className="h-5 w-5 shrink-0 text-warning-foreground" aria-hidden /> Bank details change waiting for approval
          </h2>
          <p className="mt-0.5 text-sm text-muted-foreground">
            Requested by <strong className="text-foreground">{pending.requestedByMe ? `you (${pending.requestedBy})` : pending.requestedBy}</strong>
            {pending.requestedAt ? ` on ${fmtDateTime(pending.requestedAt)}` : ""}. Members keep seeing the current details until another admin approves.
          </p>
        </div>
        <StatusChip Icon={Hourglass} tone="warning" size="md">
          Awaiting approval
        </StatusChip>
      </div>

      <div className="-mx-4 overflow-x-auto focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring sm:mx-0" tabIndex={0} role="region" aria-label="Current and new bank details (scrolls sideways)">
        <table className="w-full min-w-[30rem] text-sm">
          <thead>
            <tr className="border-b text-left text-xs text-muted-foreground">
              <th scope="col" className="px-4 py-2 font-semibold sm:pl-0">
                <span className="sr-only">Detail</span>
              </th>
              <th scope="col" className="px-2 py-2 font-semibold">Now (members see)</th>
              <th scope="col" className="px-4 py-2 font-semibold sm:pr-0">Requested</th>
            </tr>
          </thead>
          <tbody>
            {pending.rows.map((r) => (
              <tr key={r.field} className="border-b">
                <th scope="row" className="px-4 py-2 text-left font-medium sm:pl-0">
                  {r.label}
                </th>
                <td className="px-2 py-2 text-muted-foreground">{show(r.current)}</td>
                <td className="px-4 py-2 sm:pr-0">
                  <span className={cn(r.changed && "font-semibold")}>{show(r.requested)}</span>
                  {r.changed && (
                    <span className="ml-2 inline-flex items-center rounded-full border border-warning/40 bg-warning-soft px-1.5 text-xs font-semibold text-warning-foreground">Changed</span>
                  )}
                </td>
              </tr>
            ))}
            <tr>
              <th scope="row" className="px-4 py-2 text-left font-medium sm:pl-0">
                Bank transfer
              </th>
              <td className="px-2 py-2 text-muted-foreground">{pending.transferNow ? "On" : "Off"}</td>
              <td className="px-4 py-2 sm:pr-0">
                <span className={cn(pending.transferNow !== pending.transferRequested && "font-semibold")}>{pending.transferRequested ? "On" : "Off"}</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        {pending.canApprove && urls.approve ? (
          <ConfirmButton
            processing={busy}
            trigger={
              <>
                <Check aria-hidden /> Approve new details
              </>
            }
            title="Approve these bank details?"
            body={
              <>
                <p>Members will be told to pay into: {summary || "no details"}. Bank transfer will be {pending.transferRequested ? "on" : "off"}.</p>
                <p className="mt-2">Check them against the club's bank statement or letter first. Every club admin is emailed when you approve.</p>
              </>
            }
            confirm={
              <>
                <Check aria-hidden /> Approve
              </>
            }
            onConfirm={() => post(urls.approve!, { version: pending.version })}
          />
        ) : (
          <p className="flex items-start gap-2 text-sm text-muted-foreground">
            <Info className="mt-0.5 h-4 w-4 shrink-0" aria-hidden />
            <span>{pending.blocker}</span>
          </p>
        )}
        {urls.cancel && (
          <ConfirmButton
            processing={busy}
            variant="outline"
            trigger={
              <>
                <X aria-hidden /> {pending.requestedByMe ? "Cancel my request" : "Cancel this change"}
              </>
            }
            title="Cancel this bank details change?"
            body={<p>Members keep the current details. To change them later, save the new details again and another admin approves them.</p>}
            confirm={
              <>
                <X aria-hidden /> Cancel the change
              </>
            }
            onConfirm={() => post(urls.cancel!)}
          />
        )}
      </div>
    </section>
  );
}

export default function Billing({ nav, clubName, gatewayLabel, memberFeeAllowed, memberFeeReason, plans, settings, transferLive, pendingBank, urls }: Props) {
  const form = useForm<Settings>(settings);
  const { data, setData, errors, processing, isDirty } = form;
  const allowed: Record<Plan, boolean> = { monthly: data.allow_monthly, quarterly: data.allow_quarterly, yearly: data.allow_yearly };

  const submit = (e: FormEvent) => {
    e.preventDefault();
    form.put(urls.update, { preserveScroll: true });
  };

  const setAllowed = (plan: Plan, on: boolean) => {
    setData((d) => {
      const next = { ...d, [`allow_${plan}`]: on } as Settings;
      // Keep the default on an allowed plan where possible.
      if (!on && d.default_plan === plan) {
        const fallback = (["yearly", "quarterly", "monthly"] as Plan[]).find((p) => p !== plan && next[`allow_${p}` as const]);
        if (fallback) next.default_plan = fallback;
      }
      return next;
    });
  };

  return (
    <AdminLayout nav={nav} active="billing" title="Billing settings" wide={false}>
      <PageHeader
        eyebrow={`Billing settings · ${clubName}`}
        title="Billing settings"
        description="How members can pay: the plans they may choose, who pays the online gateway fee, and the club's bank details for transfers."
      />

      {pendingBank && <PendingBankPanel pending={pendingBank} urls={urls} />}

      <form onSubmit={submit} className="flex flex-col gap-4" noValidate>
        <Section id="plans" title="Plans members can choose" sub="Members use these when paying ahead from their Payments page. Longer plans mean fewer bills, so fewer gateway fees.">
          <fieldset className="flex flex-col gap-2">
            <legend className="mb-2 text-sm font-medium">Allowed plans</legend>
            <div className="grid gap-2 sm:grid-cols-3">
              {plans.map((p) => (
                <Choice key={p.key} id={`allow_${p.key}`} title={p.label} hint={`${p.months} month${p.months === 1 ? "" : "s"} per bill`}>
                  <Checkbox id={`allow_${p.key}`} checked={allowed[p.key]} onCheckedChange={(c) => setAllowed(p.key, c === true)} aria-invalid={!!errors.allow_monthly || undefined} />
                </Choice>
              ))}
            </div>
            {errors.allow_monthly && <p className="text-xs font-medium text-danger-foreground">{errors.allow_monthly}</p>}
          </fieldset>

          <fieldset className="flex flex-col gap-2">
            <legend className="mb-2 text-sm font-medium">Default plan</legend>
            <div className="grid gap-2 sm:grid-cols-3" role="radiogroup" aria-describedby="default-plan-hint">
              {plans.map((p) => (
                <Choice key={p.key} id={`default_${p.key}`} title={p.label} hint={!allowed[p.key] ? "Allow this plan first" : data.default_plan === p.key ? "Members see it marked “Disyorkan” (Recommended)" : undefined} disabled={!allowed[p.key]}>
                  <input
                    type="radio"
                    id={`default_${p.key}`}
                    name="default_plan"
                    value={p.key}
                    checked={data.default_plan === p.key}
                    disabled={!allowed[p.key]}
                    onChange={() => setData("default_plan", p.key)}
                    className="mt-0.5 h-5 w-5 shrink-0 accent-primary"
                  />
                </Choice>
              ))}
            </div>
            <p id="default-plan-hint" className={cn("text-xs", errors.default_plan ? "font-medium text-danger-foreground" : "text-muted-foreground")}>
              {errors.default_plan ?? "Pre-selected for members when they pay ahead."}
            </p>
          </fieldset>
        </Section>

        <Section id="gateway" title={`Gateway fee (${gatewayLabel})`} sub="The online gateway charges a fee for each bill (about RM 1.00 for FPX). Choose who pays it.">
          <fieldset className="flex flex-col gap-2">
            <legend className="sr-only">Who pays the gateway fee</legend>
            <Choice id="payer_club" title="The club absorbs it" hint="Members pay the fee amount only.">
              <input
                type="radio"
                id="payer_club"
                name="gateway_fee_payer"
                value="club"
                checked={data.gateway_fee_payer === "club"}
                onChange={() => setData("gateway_fee_payer", "club")}
                className="mt-0.5 h-5 w-5 shrink-0 accent-primary"
              />
            </Choice>
            <Choice
              id="payer_member"
              title="The member pays it"
              hint={memberFeeAllowed ? "Shown on the review page and added to what they pay." : "Not available for this gateway yet."}
              disabled={!memberFeeAllowed}
            >
              <input
                type="radio"
                id="payer_member"
                name="gateway_fee_payer"
                value="member"
                checked={data.gateway_fee_payer === "member"}
                disabled={!memberFeeAllowed}
                aria-describedby={memberFeeAllowed ? undefined : "payer_member_help"}
                onChange={() => setData("gateway_fee_payer", "member")}
                className="mt-0.5 h-5 w-5 shrink-0 accent-primary"
              />
            </Choice>
            {!memberFeeAllowed && memberFeeReason && (
              <p id="payer_member_help" className="flex items-start gap-2 rounded-md bg-muted px-3 py-2 text-sm text-muted-foreground">
                <Info className="mt-0.5 h-4 w-4 shrink-0" aria-hidden />
                <span>{memberFeeReason}</span>
              </p>
            )}
            {errors.gateway_fee_payer && <p className="text-xs font-medium text-danger-foreground">{errors.gateway_fee_payer}</p>}
          </fieldset>
          <Field id="gateway_fee" label="Gateway fee per bill (RM)" error={errors.gateway_fee} hint="Used on the review page, in savings hints and for the dashboard's fee total. Check your gateway plan.">
            <Input
              id="gateway_fee"
              inputMode="decimal"
              value={data.gateway_fee}
              onChange={(e) => setData("gateway_fee", e.target.value)}
              invalid={!!errors.gateway_fee}
              className="sm:w-40"
            />
          </Field>
        </Section>

        <Section
          id="grace"
          title="When a fee counts as overdue"
          sub="Until then members see “Perlu dibayar” (Due). After it, they see “Lewat” (Overdue), get one reminder email, and the fee is counted as overdue on the admin pages."
        >
          <Field
            id="overdue_grace_days"
            label="Grace period after the due date (days)"
            error={errors.overdue_grace_days}
            hint={
              /^\d+$/.test(data.overdue_grace_days)
                ? Number(data.overdue_grace_days) === 0
                  ? "0 days: overdue the day after the due date."
                  : `A fee due on 1 Dec becomes overdue on ${graceExample(Number(data.overdue_grace_days))}. Between 0 and 60 days.`
                : "A whole number of days, from 0 to 60."
            }
          >
            <Input
              id="overdue_grace_days"
              type="number"
              inputMode="numeric"
              min={0}
              max={60}
              step={1}
              value={data.overdue_grace_days}
              onChange={(e) => setData("overdue_grace_days", e.target.value)}
              invalid={!!errors.overdue_grace_days}
              className="sm:w-40"
            />
          </Field>
        </Section>

        <Section
          id="help"
          title="Member help by WhatsApp (optional)"
          sub="When a number is set, the “Perlu bantuan?” links on members' payment pages, receipts and rejected slips open WhatsApp with the fee and reference already typed in. Leave it empty to keep the email link."
        >
          <Field
            id="whatsapp_number"
            label="WhatsApp number"
            error={errors.whatsapp_number}
            hint="A Malaysian number like 012-345 6789, or any number with its country code (+65…); it is saved in the +60 form. Members see it, so use the club's or treasurer's work number."
          >
            <div className="flex items-center gap-2">
              <MessageCircle className="h-5 w-5 shrink-0 text-muted-foreground" strokeWidth={1.75} aria-hidden />
              <Input
                id="whatsapp_number"
                type="tel"
                inputMode="tel"
                autoComplete="off"
                maxLength={30}
                value={data.whatsapp_number}
                onChange={(e) => setData("whatsapp_number", e.target.value)}
                invalid={!!errors.whatsapp_number}
                placeholder="e.g. 012-345 6789"
                className="sm:w-64"
              />
            </div>
          </Field>
        </Section>

        <Section
          id="transfer"
          title="Bank transfer / DuitNow"
          sub={
            <>
              Members can pay by bank transfer or DuitNow with no gateway fee. They upload the slip and an admin checks it in{" "}
              <Link href={urls.verifications} className="font-semibold text-primary hover:underline">
                Verifications
              </Link>{" "}
              before the fee counts as paid. Members see these details, so use the club's own account.
            </>
          }
        >
          <div className="flex flex-wrap items-center gap-2 text-sm">
            <span className="text-muted-foreground">Members right now:</span>
            {transferLive ? (
              <StatusChip Icon={CheckCircle2} tone="success">
                Can pay by transfer
              </StatusChip>
            ) : (
              <StatusChip Icon={X} tone="neutral">
                Transfer not offered
              </StatusChip>
            )}
          </div>

          <Alert variant="info">
            <ShieldCheck aria-hidden />
            <AlertTitle>Changes to the bank details need a second admin</AlertTitle>
            <AlertDescription>
              A new bank name, holder, account number or DuitNow ID only reaches members after another admin of this club (or the super admin) approves it. All club admins are emailed.
              Switching transfers off takes effect at once.
            </AlertDescription>
          </Alert>

          <div>
            <Choice id="transfer_enabled" title="Offer bank transfer / DuitNow to members" hint="Needs the bank name, the account holder, and an account number or DuitNow ID.">
              <Checkbox id="transfer_enabled" checked={data.transfer_enabled} onCheckedChange={(c) => setData("transfer_enabled", c === true)} aria-invalid={!!errors.transfer_enabled || undefined} />
            </Choice>
            {errors.transfer_enabled && <p className="mt-2 text-xs font-medium text-danger-foreground">{errors.transfer_enabled}</p>}
          </div>

          <div className="grid gap-4 sm:grid-cols-2">
            <Field id="bank_name" label="Bank name" error={errors.bank_name}>
              <Input id="bank_name" maxLength={100} autoComplete="off" value={data.bank_name} onChange={(e) => setData("bank_name", e.target.value)} invalid={!!errors.bank_name} placeholder="e.g. Bank Contoh" />
            </Field>
            <Field id="bank_account_name" label="Account holder" error={errors.bank_account_name}>
              <Input
                id="bank_account_name"
                maxLength={150}
                autoComplete="off"
                value={data.bank_account_name}
                onChange={(e) => setData("bank_account_name", e.target.value)}
                invalid={!!errors.bank_account_name}
                placeholder="e.g. Kelab Contoh"
              />
            </Field>
            <Field id="bank_account_no" label="Account number" error={errors.bank_account_no} hint="Digits, spaces or dashes.">
              <Input
                id="bank_account_no"
                maxLength={20}
                inputMode="numeric"
                autoComplete="off"
                value={data.bank_account_no}
                onChange={(e) => setData("bank_account_no", e.target.value)}
                invalid={!!errors.bank_account_no}
                placeholder="e.g. 1234 5678 9012"
              />
            </Field>
            <Field id="duitnow_id" label="DuitNow ID (optional)" error={errors.duitnow_id} hint="Needed only if there is no account number.">
              <Input
                id="duitnow_id"
                maxLength={100}
                autoComplete="off"
                value={data.duitnow_id}
                onChange={(e) => setData("duitnow_id", e.target.value)}
                invalid={!!errors.duitnow_id}
                placeholder="Phone, business reg. no. or other ID"
              />
            </Field>
          </div>
          {pendingBank && (
            <p className="flex items-start gap-2 text-sm text-muted-foreground">
              <Landmark className="mt-0.5 h-4 w-4 shrink-0" aria-hidden />
              <span>These fields show the details members see now. Saving different details replaces the change waiting above.</span>
            </p>
          )}
        </Section>

        <div className="sticky bottom-0 z-10 -mx-4 flex flex-wrap items-center gap-3 border-t bg-background/95 px-4 py-3 backdrop-blur supports-[backdrop-filter]:bg-background/80 sm:mx-0 sm:rounded-lg sm:border">
          <Button type="submit" disabled={processing}>
            <Save aria-hidden /> {processing ? "Saving…" : "Save billing settings"}
          </Button>
          <Button asChild variant="ghost">
            <Link href={urls.dashboard}>
              Back to dashboard <ArrowRight aria-hidden />
            </Link>
          </Button>
          <span className="text-sm text-muted-foreground" aria-live="polite">
            {isDirty ? "You have unsaved changes." : ""}
          </span>
        </div>
      </form>
    </AdminLayout>
  );
}
