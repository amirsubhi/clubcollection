import { useEffect, useRef, useState, type FormEvent, type KeyboardEvent, type ReactNode } from "react";
import { Link, useForm } from "@inertiajs/react";
import { AlertCircle, FileText, HandHeart, ImageIcon, Lock, Receipt, ShieldCheck, Trash2, Undo2 } from "lucide-react";
import { AdminLayout } from "@/components/app/AdminLayout";
import { PageHeader } from "@/components/app/PageHeader";
import { Field } from "@/components/app/Field";
import { FileUploader, type UploadItem } from "@/components/app/FileUploader";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Checkbox } from "@/components/ui/checkbox";
import { Input, Label } from "@/components/ui/input";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Textarea } from "@/components/ui/textarea";
import { attachmentKinds } from "@/lib/fund-meta";
import { cn } from "@/lib/utils";
import type { AdminNavProps, FundDetail, Option } from "@/types/admin";
import type { FundType } from "@/types/domain";

interface Props {
  nav: AdminNavProps;
  type: FundType;
  item: FundDetail | null;
  categories: Option[];
  members: Option[];
  admins: Option[];
  relationships: string[];
  limits: { maxFiles: number; maxKb: number };
  urls: { index: string; action: string; show: string | null; createExpense: string; createWelfare: string };
}

interface WelfareForm {
  beneficiary_user_id: string;
  subject_name: string;
  relationship: string;
  recipient_name: string;
  certificate_sighted_by: string;
  certificate_sighted_at: string;
}

interface FormData {
  intent: "draft" | "submit";
  type: FundType;
  expense_category_id: string;
  description: string;
  payee: string;
  invoice_no: string;
  amount: string;
  expense_date: string;
  paid_date: string;
  minute_ref: string;
  remove_attachment_ids: number[];
  welfare: WelfareForm;
}

const ALLOWED = ["application/pdf", "image/jpeg", "image/png", "image/webp"];
const today = () => new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10);
const toAmount = (sen: number) => (sen / 100).toFixed(2);

function Segmented<T extends string>({ value, onChange, options, labelledBy }: { value: T; onChange: (v: T) => void; options: { value: T; label: string; icon?: ReactNode }[]; labelledBy: string }) {
  const refs = useRef<(HTMLButtonElement | null)[]>([]);
  // Radio-group keyboard pattern: one tab stop (the checked option), arrow keys move and select.
  const onKey = (e: KeyboardEvent<HTMLButtonElement>, i: number) => {
    const step = e.key === "ArrowRight" || e.key === "ArrowDown" ? 1 : e.key === "ArrowLeft" || e.key === "ArrowUp" ? -1 : 0;
    if (!step) return;
    e.preventDefault();
    const next = (i + step + options.length) % options.length;
    onChange(options[next].value);
    refs.current[next]?.focus();
  };
  return (
    <div role="radiogroup" aria-labelledby={labelledBy} className="inline-flex w-full rounded-md bg-secondary p-1 sm:w-auto">
      {options.map((o, i) => (
        <button
          key={o.value}
          ref={(el) => {
            refs.current[i] = el;
          }}
          type="button"
          role="radio"
          aria-checked={value === o.value}
          tabIndex={value === o.value ? 0 : -1}
          onClick={() => onChange(o.value)}
          onKeyDown={(e) => onKey(e, i)}
          className={cn(
            "inline-flex min-h-9 flex-1 items-center justify-center gap-1.5 rounded-sm px-3 py-1.5 text-sm font-semibold focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring sm:flex-none [&_svg]:size-4",
            value === o.value ? "bg-card text-foreground shadow-[0_1px_2px_rgb(0_0_0/0.1)]" : "text-muted-foreground hover:text-foreground",
          )}
        >
          {o.icon}
          {o.label}
        </button>
      ))}
    </div>
  );
}

export default function FundUsageCreate({ nav, type: initialType, item, categories, members, admins, relationships, limits, urls }: Props) {
  const isEdit = item !== null;
  const w = item?.welfare;
  const form = useForm<FormData>({
    intent: "draft",
    type: item?.type ?? initialType,
    expense_category_id: item ? String(item.categoryId) : "",
    description: item?.description ?? "",
    payee: item?.payee ?? "",
    invoice_no: item?.invoiceNo ?? "",
    amount: item ? toAmount(item.amount) : "",
    expense_date: item?.invoiceDate ?? today(),
    paid_date: item?.paidDate ?? "",
    minute_ref: item?.minuteRef ?? "",
    remove_attachment_ids: [],
    welfare: {
      beneficiary_user_id: w ? String(w.beneficiaryId) : "",
      subject_name: w?.subjectName ?? "",
      relationship: w?.relationship ?? "",
      recipient_name: w?.recipientName ?? "",
      certificate_sighted_by: w?.certificateSightedById ? String(w.certificateSightedById) : "",
      certificate_sighted_at: w?.certificateSightedOn ?? "",
    },
  });
  const [uploads, setUploads] = useState<UploadItem[]>([]);
  const [fileError, setFileError] = useState<string | undefined>();
  const [sighted, setSighted] = useState<boolean>(!!w?.certificateSightedById);
  const d = form.data;
  const isWelfare = d.type === "welfare";
  // Gateway fees the club absorbs are counted automatically; warn so they are not entered twice.
  const isBankCharges = /^bank charges$/i.test(categories.find((c) => String(c.id) === d.expense_category_id)?.name.trim() ?? "");
  const err = form.errors as Record<string, string | undefined>;
  const existing = (item?.attachments ?? []).filter((a) => !d.remove_attachment_ids.includes(Number(a.id)));
  const fileErr = fileError ?? err.files ?? Object.entries(err).find(([k]) => k.startsWith("files."))?.[1];
  const setW = (k: keyof WelfareForm, v: string) => form.setData("welfare", { ...d.welfare, [k]: v });

  const addFiles = (items: UploadItem[]) => {
    setFileError(undefined);
    const ok: UploadItem[] = [];
    for (const it of items) {
      // Both the extension and the browser-reported type must be allowed (the server checks the content too).
      if (!it.file || !ALLOWED.includes(it.file.type) || !/\.(pdf|jpe?g|png|webp)$/i.test(it.name)) {
        setFileError(`${it.name}: only PDF, JPG, PNG or WebP files can be attached.`);
        continue;
      }
      if (it.file && it.file.size > limits.maxKb * 1024) {
        setFileError(`${it.name} is larger than 10 MB.`);
        continue;
      }
      ok.push(it);
    }
    const room = limits.maxFiles - existing.length - uploads.length;
    if (ok.length > room) setFileError(`A record can have at most ${limits.maxFiles} documents.`);
    setUploads((u) => [...u, ...ok.slice(0, Math.max(0, room))]);
  };

  const send = (intent: "draft" | "submit") => (e?: FormEvent) => {
    e?.preventDefault();
    const withFile = uploads.filter((u) => u.file instanceof File);
    form.transform((data) => ({
      ...data,
      intent,
      payee: data.type === "welfare" ? "" : data.payee,
      invoice_no: data.type === "welfare" ? "" : data.invoice_no,
      welfare: data.type === "welfare" ? { ...data.welfare, ...(sighted ? {} : { certificate_sighted_by: "", certificate_sighted_at: "" }) } : undefined,
      // Filter once so files[i] and kinds[i] stay paired.
      files: withFile.map((u) => u.file as File),
      kinds: withFile.map((u) => u.kind || "other"),
      ...(isEdit ? { _method: "put" } : {}),
    }));
    form.post(urls.action, { forceFormData: true, preserveScroll: true, onError: () => setFocusSummary(true) });
  };

  const errorList = Object.values(err).filter(Boolean) as string[];
  // After a failed submit, move focus to the error summary so keyboard and screen-reader users hear what to fix.
  const summaryRef = useRef<HTMLDivElement>(null);
  const [focusSummary, setFocusSummary] = useState(false);
  useEffect(() => {
    if (focusSummary && errorList.length > 0) {
      summaryRef.current?.focus();
      setFocusSummary(false);
    }
  }, [focusSummary, errorList.length]);
  const title = isEdit ? `Edit ${item.ref}` : isWelfare ? "New welfare case" : "New expense";

  return (
    <AdminLayout nav={nav} active="fund-usage" title={title} wide={false}>
      <PageHeader
        eyebrow={
          <Link href={urls.index} className="hover:underline">
            Fund Usage
          </Link>
        }
        title={title}
        description={isWelfare ? "Sumbangan kebajikan. A different admin must approve before it counts." : "Record spending with its documents. A different admin must approve before it counts."}
      />

      {item?.returned && item.returnReason && (
        <Alert variant="warning">
          <Undo2 aria-hidden />
          <div>
            <AlertTitle>Returned by the checker · Dikembalikan</AlertTitle>
            <AlertDescription className="text-foreground">“{item.returnReason}” Fix it and submit again; the reason stays on the audit trail.</AlertDescription>
          </div>
        </Alert>
      )}
      {item?.status === "submitted" && (
        <Alert variant="warning">
          <Lock aria-hidden />
          <div>
            <AlertTitle>This record is awaiting approval</AlertTitle>
            <AlertDescription className="text-foreground">Saving any change sends it back to draft and unlocks its documents. You will need to submit it again.</AlertDescription>
          </div>
        </Alert>
      )}
      {errorList.length > 0 && (
        <Alert variant="danger" ref={summaryRef} tabIndex={-1} aria-labelledby="error-summary-title" className="scroll-mt-20 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring">
          <AlertCircle aria-hidden />
          <div>
            <AlertTitle id="error-summary-title">
              {errorList.length} thing{errorList.length === 1 ? "" : "s"} need{errorList.length === 1 ? "s" : ""} fixing
            </AlertTitle>
            <AlertDescription>
              <ul className="list-disc pl-4">
                {errorList.slice(0, 6).map((m, i) => (
                  <li key={i}>{m}</li>
                ))}
              </ul>
            </AlertDescription>
          </div>
        </Alert>
      )}

      <form className="flex flex-col gap-5" onSubmit={send("submit")} noValidate>
        <Card>
          <CardContent className="flex flex-col gap-4 p-4 sm:p-5">
            <Field id="type" label="Record type" group>
              <Segmented<FundType>
                labelledBy="type-label"
                value={d.type}
                onChange={(v) => form.setData("type", v)}
                options={[
                  { value: "expense", label: "Expense", icon: <Receipt aria-hidden /> },
                  { value: "welfare", label: "Welfare (kebajikan)", icon: <HandHeart aria-hidden /> },
                ]}
              />
            </Field>
            <div className="grid gap-4 sm:grid-cols-2">
              <Field
                id="category"
                label="Category"
                required
                error={err.expense_category_id}
                hint={isBankCharges ? "Bank charges: only charges the portal can't see. Absorbed gateway fees are added automatically." : undefined}
              >
                <Select value={d.expense_category_id || undefined} onValueChange={(v) => form.setData("expense_category_id", v)}>
                  <SelectTrigger id="category" invalid={!!err.expense_category_id}>
                    <SelectValue placeholder="Select category" />
                  </SelectTrigger>
                  <SelectContent>
                    {categories.map((c) => (
                      <SelectItem key={c.id} value={String(c.id)}>
                        {c.name}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </Field>
              {!isWelfare && (
                <Field id="payee" label="Payee (paid to)" required error={err.payee}>
                  <Input id="payee" invalid={!!err.payee} value={d.payee} onChange={(e) => form.setData("payee", e.target.value)} placeholder="Company or person" maxLength={255} />
                </Field>
              )}
            </div>
            <Field id="desc" label="Description" required hint='e.g. "Refreshments for committee meeting MJK 10/2026"' error={err.description}>
              <Textarea id="desc" rows={2} value={d.description} onChange={(e) => form.setData("description", e.target.value)} maxLength={500} />
            </Field>
          </CardContent>
        </Card>

        {isWelfare && (
          <Card>
            <CardHeader className="pb-2">
              <CardTitle>Welfare details</CardTitle>
              <CardDescription>Butiran kebajikan. Stored encrypted; visible to admins only.</CardDescription>
            </CardHeader>
            <CardContent className="flex flex-col gap-4">
              <div className="grid gap-4 sm:grid-cols-2">
                <Field id="beneficiary" label="Beneficiary member" required hint="Ahli yang menerima manfaat" error={err["welfare.beneficiary_user_id"]}>
                  <Select value={d.welfare.beneficiary_user_id || undefined} onValueChange={(v) => setW("beneficiary_user_id", v)}>
                    <SelectTrigger id="beneficiary" invalid={!!err["welfare.beneficiary_user_id"]}>
                      <SelectValue placeholder="Choose member" />
                    </SelectTrigger>
                    <SelectContent>
                      {members.map((m) => (
                        <SelectItem key={m.id} value={String(m.id)}>
                          {m.name}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </Field>
                <Field id="subject" label="Deceased / affected person" required hint="Nama si mati / mangsa" error={err["welfare.subject_name"]}>
                  <Input id="subject" value={d.welfare.subject_name} onChange={(e) => setW("subject_name", e.target.value)} invalid={!!err["welfare.subject_name"]} maxLength={255} />
                </Field>
                <Field id="relationship" label="Relationship to member" required hint="Hubungan" error={err["welfare.relationship"]}>
                  <Select value={d.welfare.relationship || undefined} onValueChange={(v) => setW("relationship", v)}>
                    <SelectTrigger id="relationship" invalid={!!err["welfare.relationship"]}>
                      <SelectValue placeholder="Choose" />
                    </SelectTrigger>
                    <SelectContent>
                      {relationships.map((r) => (
                        <SelectItem key={r} value={r}>
                          {r}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </Field>
                <Field id="recipient" label="Paid to (recipient / next of kin)" required hint="Penerima (waris)" error={err["welfare.recipient_name"]}>
                  <Input id="recipient" value={d.welfare.recipient_name} onChange={(e) => setW("recipient_name", e.target.value)} invalid={!!err["welfare.recipient_name"]} maxLength={255} />
                </Field>
              </div>
              <div className="rounded-lg border bg-muted/40 p-3">
                <div className="flex items-start gap-3">
                  <Checkbox id="sighted" checked={sighted} onCheckedChange={(c) => setSighted(c === true)} />
                  <Label htmlFor="sighted" className="font-semibold leading-snug">
                    <span className="inline-flex items-center gap-1.5">
                      <ShieldCheck className="h-4 w-4 text-success" aria-hidden /> Death certificate sighted (not uploaded)
                    </span>
                    <span className="mt-0.5 block text-xs font-normal text-muted-foreground">We record who saw the certificate and when, instead of keeping a copy.</span>
                  </Label>
                </div>
                {sighted && (
                  <div className="mt-3 grid gap-4 sm:grid-cols-2">
                    <Field id="sightedBy" label="Sighted by" required error={err["welfare.certificate_sighted_by"]}>
                      <Select value={d.welfare.certificate_sighted_by || undefined} onValueChange={(v) => setW("certificate_sighted_by", v)}>
                        <SelectTrigger id="sightedBy" invalid={!!err["welfare.certificate_sighted_by"]}>
                          <SelectValue placeholder="Choose admin" />
                        </SelectTrigger>
                        <SelectContent>
                          {admins.map((m) => (
                            <SelectItem key={m.id} value={String(m.id)}>
                              {m.name}
                            </SelectItem>
                          ))}
                        </SelectContent>
                      </Select>
                    </Field>
                    <Field id="sightedOn" dateValue={d.welfare.certificate_sighted_at} label="Sighted on" required error={err["welfare.certificate_sighted_at"]}>
                      <Input id="sightedOn" type="date" max={today()} value={d.welfare.certificate_sighted_at} onChange={(e) => setW("certificate_sighted_at", e.target.value)} />
                    </Field>
                  </div>
                )}
              </div>
            </CardContent>
          </Card>
        )}

        <Card>
          <CardHeader className="pb-2">
            <CardTitle>Payment</CardTitle>
          </CardHeader>
          <CardContent className="flex flex-col gap-4">
            <div className="grid gap-4 sm:grid-cols-2">
              <Field id="amount" label="Amount (RM)" required error={err.amount}>
                <div className="relative">
                  <span className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-sm text-muted-foreground">RM</span>
                  <Input id="amount" inputMode="decimal" className="money pl-11" invalid={!!err.amount} value={d.amount} placeholder="0.00" onChange={(e) => form.setData("amount", e.target.value)} />
                </div>
              </Field>
              {!isWelfare && (
                <Field id="invoiceNo" label="Invoice / bill no." hint="As printed on the supplier's document" error={err.invoice_no}>
                  <Input id="invoiceNo" value={d.invoice_no} onChange={(e) => form.setData("invoice_no", e.target.value)} maxLength={100} />
                </Field>
              )}
              <Field id="invoiceDate" dateValue={d.expense_date} label={isWelfare ? "Date approved by committee" : "Invoice date"} required error={err.expense_date}>
                <Input id="invoiceDate" type="date" value={d.expense_date} onChange={(e) => form.setData("expense_date", e.target.value)} />
              </Field>
              <Field id="paidDate" dateValue={d.paid_date} label="Date paid" hint="When the money actually left the account" error={err.paid_date}>
                <Input id="paidDate" type="date" value={d.paid_date} onChange={(e) => form.setData("paid_date", e.target.value)} />
              </Field>
              <Field id="minute" label="Meeting minute ref." hint="Rujukan minit mesyuarat, e.g. MJK 10/2026 item 3.1" error={err.minute_ref} className="sm:col-span-2">
                <Input id="minute" value={d.minute_ref} onChange={(e) => form.setData("minute_ref", e.target.value)} maxLength={255} />
              </Field>
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="pb-2">
            <CardTitle>{isWelfare ? "Proof of payment" : "Documents"}</CardTitle>
            <CardDescription>
              {isWelfare
                ? "Bank slip or signed acknowledgement from the recipient. Do not upload the death certificate. Needed to submit unless the certificate was sighted."
                : "Invoice, receipt and/or bank slip. Tag each file. At least one is needed to submit; files are locked once submitted."}
            </CardDescription>
          </CardHeader>
          <CardContent className="flex flex-col gap-3">
            {existing.length > 0 && (
              <ul className="flex flex-col divide-y rounded-lg border">
                {existing.map((a) => (
                  <li key={a.id} className="flex items-center gap-2.5 p-2.5">
                    <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded bg-muted">
                      {a.mime === "application/pdf" ? <FileText className="h-5 w-5 text-danger" aria-hidden /> : <ImageIcon className="h-5 w-5 text-info" aria-hidden />}
                    </div>
                    <div className="min-w-0 flex-1">
                      <div className="truncate text-sm font-medium">{a.name}</div>
                      <div className="text-xs text-muted-foreground">
                        {attachmentKinds.find((k) => k.value === a.kind)?.label ?? a.kind} · {a.sizeKb} KB · saved
                      </div>
                    </div>
                    <Button
                      type="button"
                      variant="ghost"
                      size="icon"
                      className="h-9 w-9 shrink-0"
                      aria-label={`Remove ${a.name}`}
                      onClick={() => form.setData("remove_attachment_ids", [...d.remove_attachment_ids, Number(a.id)])}
                    >
                      <Trash2 className="text-danger" />
                    </Button>
                  </li>
                ))}
              </ul>
            )}
            <FileUploader
              files={uploads}
              onAdd={addFiles}
              onRemove={(id) => setUploads((f) => f.filter((x) => x.id !== id))}
              onKindChange={(id, kind) => setUploads((f) => f.map((x) => (x.id === id ? { ...x, kind } : x)))}
              error={fileErr}
              maxFiles={limits.maxFiles}
            />
            {form.progress && (
              <div className="h-1.5 w-full overflow-hidden rounded-full bg-muted" role="progressbar" aria-valuenow={form.progress.percentage ?? 0} aria-valuemin={0} aria-valuemax={100} aria-label="Upload progress">
                <div className="h-full bg-primary transition-[width]" style={{ width: `${form.progress.percentage ?? 0}%` }} />
              </div>
            )}
          </CardContent>
        </Card>

        {isWelfare && (
          <Alert variant="info">
            <ShieldCheck aria-hidden />
            <div className="flex flex-col gap-2">
              <AlertTitle>Notis privasi (PDPA 2010) · Privacy notice</AlertTitle>
              <AlertDescription className="flex flex-col gap-2">
                <p lang="ms">
                  Maklumat peribadi dalam rekod ini digunakan hanya untuk mengurus sumbangan kebajikan kelab. Ia hanya boleh dilihat oleh pentadbir kelab, tidak dikongsi dengan ahli lain, dan akan dipadam selepas kira-kira 7 tahun.
                </p>
                <p lang="en">
                  Personal data in this record is used only to manage the club's welfare contribution. Only club admins can see it; it is not shared with other members and is deleted after about 7 years.
                </p>
              </AlertDescription>
            </div>
          </Alert>
        )}

        <div className="flex flex-col-reverse gap-2 border-t pt-4 sm:flex-row sm:items-center sm:justify-between">
          <Button asChild variant="ghost">
            <Link href={urls.show ?? urls.index}>Cancel</Link>
          </Button>
          <div className="flex flex-col-reverse gap-2 sm:flex-row">
            <Button type="button" variant="outline" disabled={form.processing} onClick={send("draft")}>
              Save draft
            </Button>
            <Button type="submit" disabled={form.processing}>
              Submit for approval
            </Button>
          </div>
        </div>
        <p className="-mt-2 text-xs text-muted-foreground sm:text-right">After you submit, another admin (not you{isWelfare ? ", and not the beneficiary" : ""}) must approve it.</p>
      </form>
    </AdminLayout>
  );
}
