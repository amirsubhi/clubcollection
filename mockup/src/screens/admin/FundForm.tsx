import { useState } from "react";
import { AlertCircle, HandHeart, Receipt, ShieldCheck, Undo2 } from "lucide-react";
import { AdminShell } from "@/components/app/AdminShell";
import { PageHeader } from "@/components/app/PageHeader";
import { MockControl } from "@/components/app/MockControl";
import { Field } from "@/components/app/Field";
import { FileUploader, type UploadItem } from "@/components/app/FileUploader";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Textarea } from "@/components/ui/textarea";
import { members } from "@/data/club";
import { expenseCategories, welfareCategories } from "@/data/fund";
import type { AccountKind, FundType } from "@/data/types";
import { cn } from "@/lib/utils";

export interface FundFormProps {
  type: FundType;
  showErrors?: boolean;
}

const sampleExpenseFiles: UploadItem[] = [
  { id: "s1", name: "IMG_20261111_1942.jpg", sizeKb: 2310, isPdf: false, kind: "receipt" },
  { id: "s2", name: "nasi-kandar-pelita-bill.pdf", sizeKb: 188, isPdf: true, kind: "" },
];
const sampleWelfareFiles: UploadItem[] = [{ id: "w1", name: "duitnow-transfer-12nov.jpg", sizeKb: 640, isPdf: false, kind: "bank_slip" }];

function Segmented<T extends string>({ value, onChange, options, label }: { value: T; onChange: (v: T) => void; options: { value: T; label: string; icon?: React.ReactNode }[]; label: string }) {
  return (
    <div role="radiogroup" aria-label={label} className="inline-flex w-full rounded-md bg-secondary p-1 sm:w-auto">
      {options.map((o) => (
        <button
          key={o.value}
          type="button"
          role="radio"
          aria-checked={value === o.value}
          onClick={() => onChange(o.value)}
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

export function FundForm({ type, showErrors = false }: FundFormProps) {
  const isWelfare = type === "welfare";
  const err = showErrors && !isWelfare;
  const [files, setFiles] = useState<UploadItem[]>(err ? [] : isWelfare ? sampleWelfareFiles : sampleExpenseFiles);
  const [account, setAccount] = useState<AccountKind>(isWelfare ? "bank" : "cash");
  const [beneficiary, setBeneficiary] = useState(isWelfare ? "6" : "");
  const [returned, setReturned] = useState(false);

  return (
    <AdminShell active={isWelfare ? "welfare-new" : "fund-new"} wide={false}>
      <PageHeader
        eyebrow={<a href="#fund-usage" className="hover:underline">Fund Usage</a>}
        title={isWelfare ? "New welfare case" : "New expense"}
        description={isWelfare ? "Sumbangan kebajikan. A different admin must approve before it counts." : "Record spending with its documents. A different admin must approve before it counts."}
      />

      {!isWelfare && (
        <MockControl
          label="Form state"
          value={showErrors ? "errors" : returned ? "returned" : "filled"}
          options={[
            { value: "filled", label: "Being filled in" },
            { value: "errors", label: "After submit with errors" },
            { value: "returned", label: "Returned by checker" },
          ]}
          onChange={(v) => {
            if (v === "errors") window.location.hash = "fund-new-errors";
            else {
              setReturned(v === "returned");
              if (showErrors) window.location.hash = "fund-new";
            }
          }}
        />
      )}

      {returned && !err && (
        <Alert variant="warning">
          <Undo2 aria-hidden />
          <div>
            <AlertTitle>Returned by Ahmad Faizal bin Rahman · 12 Nov 2026, 9:05 am</AlertTitle>
            <AlertDescription className="text-foreground">
              “The bill PDF is for RM 168.50 but the amount says RM 186.50. Please check and re-upload the right bill.” Fix it and submit again; the reason stays on the
              audit trail.
            </AlertDescription>
          </div>
        </Alert>
      )}

      {err && (
        <Alert variant="danger">
          <AlertCircle aria-hidden />
          <div>
            <AlertTitle>4 things need fixing before you can submit</AlertTitle>
            <AlertDescription>
              <ul className="list-disc pl-4">
                <li>Choose a category</li>
                <li>Enter who was paid</li>
                <li>Amount must be more than RM 0.00</li>
                <li>Attach at least one document (invoice, receipt or bank slip)</li>
              </ul>
              <p className="mt-1">You can still save it as a draft.</p>
            </AlertDescription>
          </div>
        </Alert>
      )}

      <form className="flex flex-col gap-5" onSubmit={(e) => e.preventDefault()} noValidate>
        <Card>
          <CardContent className="flex flex-col gap-4 p-4 sm:p-5">
            <Field label="Record type">
              <Segmented<FundType>
                label="Record type"
                value={type}
                onChange={(v) => (window.location.hash = v === "welfare" ? "welfare-new" : "fund-new")}
                options={[
                  { value: "expense", label: "Expense", icon: <Receipt aria-hidden /> },
                  { value: "welfare", label: "Welfare (kebajikan)", icon: <HandHeart aria-hidden /> },
                ]}
              />
            </Field>
            <div className="grid gap-4 sm:grid-cols-2">
              <Field id="category" label="Category" required error={err ? "Choose a category." : undefined}>
                <Select defaultValue={err ? undefined : isWelfare ? welfareCategories[0] : "Meeting refreshments"}>
                  <SelectTrigger id="category" invalid={err}>
                    <SelectValue placeholder="Select category" />
                  </SelectTrigger>
                  <SelectContent>
                    {(isWelfare ? welfareCategories : expenseCategories).map((c) => (
                      <SelectItem key={c} value={c}>
                        {c}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </Field>
              {!isWelfare && (
                <Field id="payee" label="Payee (paid to)" required error={err ? "Enter who was paid." : undefined}>
                  <Input id="payee" invalid={err} defaultValue={err ? "" : "Restoran Nasi Kandar Pelita"} placeholder="Company or person" />
                </Field>
              )}
            </div>
            <Field id="desc" label="Description" required hint='e.g. "Refreshments for committee meeting MJK 10/2026"'>
              <Textarea
                id="desc"
                rows={2}
                defaultValue={isWelfare ? "Sumbangan kematian: father of member Tan Wei Ming" : "Refreshments for committee meeting MJK 10/2026"}
              />
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
                <Field id="beneficiary" label="Beneficiary member" required hint="Ahli yang menerima manfaat">
                  <Select value={beneficiary} onValueChange={setBeneficiary}>
                    <SelectTrigger id="beneficiary">
                      <SelectValue placeholder="Search member" />
                    </SelectTrigger>
                    <SelectContent>
                      {members.map((m) => (
                        <SelectItem key={m.id} value={String(m.id)}>
                          {m.name} · {m.memberNo}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </Field>
                <Field id="subject" label="Deceased / affected person" required hint="Nama si mati / mangsa">
                  <Input id="subject" defaultValue="Tan Ah Kow" />
                </Field>
                <Field id="relationship" label="Relationship to member" required hint="Hubungan">
                  <Select defaultValue="Father">
                    <SelectTrigger id="relationship">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      {["Self", "Spouse", "Father", "Mother", "Child", "Father-in-law", "Mother-in-law", "Other"].map((r) => (
                        <SelectItem key={r} value={r}>
                          {r}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </Field>
                <Field id="recipient" label="Paid to (recipient / next of kin)" required hint="Penerima (waris)">
                  <Input id="recipient" defaultValue="Tan Wei Ming" />
                </Field>
              </div>
              <div className="rounded-lg border bg-muted/40 p-3">
                <div className="mb-3 flex items-center gap-2 text-sm font-semibold">
                  <ShieldCheck className="h-4 w-4 text-success" aria-hidden /> Death certificate sighted (not uploaded)
                </div>
                <div className="grid gap-4 sm:grid-cols-2">
                  <Field id="sightedBy" label="Sighted by" required>
                    <Select defaultValue="Lim Siew Ling">
                      <SelectTrigger id="sightedBy">
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent>
                        {members.filter((m) => m.role).map((m) => (
                          <SelectItem key={m.id} value={m.name}>
                            {m.name}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  </Field>
                  <Field id="sightedOn" label="Sighted on" required>
                    <Input id="sightedOn" type="date" defaultValue="2026-11-12" />
                  </Field>
                </div>
                <p className="mt-2 text-xs text-muted-foreground">We record who saw the certificate and when, instead of keeping a copy.</p>
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
              <Field id="amount" label="Amount (RM)" required error={err ? "Amount must be more than RM 0.00." : undefined}>
                <div className="relative">
                  <span className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-sm text-muted-foreground">RM</span>
                  <Input id="amount" inputMode="decimal" className="money pl-11" invalid={err} defaultValue={err ? "0.00" : isWelfare ? "1,000.00" : "186.50"} />
                </div>
              </Field>
              {!isWelfare && (
                <Field id="invoiceNo" label="Invoice / bill no." hint="As printed on the supplier's document">
                  <Input id="invoiceNo" defaultValue={err ? "" : "NKP-77812"} />
                </Field>
              )}
              <Field id="invoiceDate" label={isWelfare ? "Date approved by committee" : "Invoice date"} required>
                <Input id="invoiceDate" type="date" defaultValue="2026-11-11" />
              </Field>
              <Field id="paidDate" label="Date paid" hint="When the money actually left the account">
                <Input id="paidDate" type="date" defaultValue={isWelfare ? "2026-11-12" : "2026-11-11"} />
              </Field>
              <Field label="Paid from account" required>
                <Segmented<AccountKind>
                  label="Paid from account"
                  value={account}
                  onChange={setAccount}
                  options={[
                    { value: "cash", label: "Cash (tunai)" },
                    { value: "bank", label: "Bank" },
                  ]}
                />
              </Field>
              <Field id="minute" label="Meeting minute ref." hint="Rujukan minit mesyuarat, e.g. MJK 10/2026 item 3.1">
                <Input id="minute" defaultValue={isWelfare ? "Circular resolution 12/2026" : "MJK 10/2026, item 7"} />
              </Field>
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="pb-2">
            <CardTitle>{isWelfare ? "Proof of payment (optional)" : "Documents"}</CardTitle>
            <CardDescription>
              {isWelfare
                ? "Bank slip or signed acknowledgement from the recipient. Do not upload the death certificate."
                : "Invoice, receipt and/or bank slip. Tag each file. Files are locked once submitted."}
            </CardDescription>
          </CardHeader>
          <CardContent>
            <FileUploader
              files={files}
              onAdd={(nf) => setFiles((f) => [...f, ...nf])}
              onRemove={(id) => setFiles((f) => f.filter((x) => x.id !== id))}
              onKindChange={(id, kind) => setFiles((f) => f.map((x) => (x.id === id ? { ...x, kind } : x)))}
              error={err ? "Attach at least one document to submit." : undefined}
            />
          </CardContent>
        </Card>

        {isWelfare && (
          <Alert variant="info">
            <ShieldCheck aria-hidden />
            <div className="flex flex-col gap-2">
              <AlertTitle>Notis privasi (PDPA 2010) · Privacy notice</AlertTitle>
              <AlertDescription className="flex flex-col gap-2">
                <p lang="ms">
                  Maklumat peribadi dalam rekod ini digunakan hanya untuk mengurus sumbangan kebajikan kelab. Ia hanya boleh dilihat oleh pentadbir kelab,
                  tidak dikongsi dengan ahli lain, dan akan dipadam selepas kira-kira 7 tahun.
                </p>
                <p lang="en">
                  Personal data in this record is used only to manage the club's welfare contribution. Only club admins can see it; it is not shared with
                  other members and is deleted after about 7 years.
                </p>
              </AlertDescription>
            </div>
          </Alert>
        )}

        <div className="flex flex-col-reverse gap-2 border-t pt-4 sm:flex-row sm:items-center sm:justify-between">
          <Button asChild variant="ghost">
            <a href="#fund-usage">Cancel</a>
          </Button>
          <div className="flex flex-col-reverse gap-2 sm:flex-row">
            <Button asChild variant="outline">
              <a href="#fund-item">Save draft</a>
            </Button>
            {err ? (
              <Button type="button">Submit for approval</Button>
            ) : (
              <Button asChild>
                <a href="#fund-item">Submit for approval</a>
              </Button>
            )}
          </div>
        </div>
        <p className="-mt-2 text-xs text-muted-foreground sm:text-right">
          After you submit, another admin (not you{isWelfare ? ", and not the beneficiary" : ""}) must approve it.
        </p>
      </form>
    </AdminShell>
  );
}
